<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorAuthService $twoFactor) {}

    /**
     * Génère un secret TOTP en attente de confirmation.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'La double authentification est déjà activée.'], 409);
        }

        $secret = $this->twoFactor->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->twoFactor->otpauthUrl($user, $secret),
            'message' => 'Scannez le QR code dans votre application d\'authentification puis confirmez avec un code à 6 chiffres.',
        ]);
    }

    /**
     * Confirme l'activation avec un premier code valide et délivre les codes de récupération.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'La double authentification est déjà activée.'], 409);
        }

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => 'Aucune activation de double authentification en cours.'], 400);
        }

        if (! $this->twoFactor->verifyCode($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'Le code de double authentification est invalide.']);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return response()->json([
            'message' => 'Double authentification activée.',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Désactive la 2FA (mot de passe requis).
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Le mot de passe est incorrect.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => 'Double authentification désactivée.']);
    }

    /**
     * Regénère les codes de récupération (mot de passe requis).
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Le mot de passe est incorrect.']);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'La double authentification n\'est pas activée.'], 400);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $recoveryCodes])->save();

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    /**
     * Seconde étape du login : échange le token de défi + code TOTP
     * (ou code de récupération) contre un token d'API.
     */
    public function challenge(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        $user = $this->twoFactor->resolveChallenge($request->input('challenge_token'));

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Le défi de double authentification est invalide ou expiré.'], 401);
        }

        $valid = $request->filled('code')
            && $this->twoFactor->verifyCode($user->two_factor_secret, $request->input('code'));

        if (! $valid && $request->filled('recovery_code')) {
            $valid = $this->twoFactor->verifyRecoveryCode($user, $request->input('recovery_code'));
        }

        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'Le code de double authentification est invalide.']);
        }

        $this->twoFactor->forgetChallenge($request->input('challenge_token'));

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data' => new UserResource($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}
