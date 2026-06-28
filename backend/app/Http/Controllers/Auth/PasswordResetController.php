<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordResetController extends Controller
{
    /**
     * Envoie l'e-mail de réinitialisation. Réponse volontairement générique
     * pour ne pas révéler l'existence d'un compte.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Veuillez patienter avant de redemander un lien de réinitialisation.',
            ], 429);
        }

        return response()->json([
            'message' => 'Si un compte existe pour cette adresse, un e-mail de réinitialisation a été envoyé.',
        ]);
    }

    /**
     * Réinitialise le mot de passe à partir du token reçu par e-mail
     * et révoque tous les tokens d'API existants.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Ce lien de réinitialisation est invalide ou expiré.',
            ]);
        }

        return response()->json(['message' => 'Votre mot de passe a été réinitialisé.']);
    }

    /**
     * Changement de mot de passe pour un utilisateur connecté.
     * Révoque les autres tokens d'API (celui de la session courante est conservé).
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        $user->forceFill(['password' => $validated['password']])->save();

        $query = $user->tokens();

        $current = $user->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $query->where('id', '!=', $current->id);
        }

        $query->delete();

        return response()->json(['message' => 'Mot de passe mis à jour.']);
    }
}
