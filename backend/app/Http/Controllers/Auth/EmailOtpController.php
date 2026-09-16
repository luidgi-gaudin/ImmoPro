<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EmailOtpService;
use App\Services\Auth\TenantAccountLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Vérification de l'adresse e-mail par code à usage unique.
 *
 * Deux points d'entrée publics, tous deux nécessairement anonymes : celui qui
 * vérifie son adresse n'a pas encore de jeton, c'est précisément ce qu'il vient
 * chercher.
 *
 * D'où la règle qui gouverne les réponses de ce contrôleur : **ne jamais
 * révéler si un compte existe**. Un renvoi de code qui répondrait « adresse
 * inconnue » transformerait le formulaire en outil d'énumération, capable de
 * dire lesquelles d'une liste d'adresses sont inscrites. Les deux réponses sont
 * donc identiques, qu'il y ait un compte au bout ou non.
 *
 * La vérification, elle, ne peut pas rester muette : il faut bien dire que le
 * code est faux. Mais elle ne distingue pas « mauvais code » de « pas de
 * compte » — le même message couvre les deux.
 */
class EmailOtpController extends Controller
{
    /**
     * (Re)demande un code.
     *
     * Le débit est plafonné au niveau de la route, et l'intervalle entre deux
     * envois l'est dans le service : sans les deux, ce point d'entrée devient
     * un robinet à courriels pointé sur l'adresse de son choix.
     */
    public function send(Request $request, EmailOtpService $otp): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $otp->send($user, OtpPurpose::EmailVerification);
        }

        return response()->json([
            'message' => 'Si un compte en attente existe pour cette adresse, un code vient d\'être envoyé.',
            'otp' => [
                'expires_in_minutes' => $otp->validityMinutes(),
                'resend_after_seconds' => $otp->resendIntervalSeconds(),
            ],
        ]);
    }

    /**
     * Valide le code et ouvre la session.
     *
     * C'est ici que le compte devient utilisable : le jeton n'est émis qu'une
     * fois l'adresse prouvée.
     *
     * Le rattachement des dossiers locataire se fait dans la foulée, et pas
     * avant : il repose sur l'égalité des adresses, qui ne prouve rien tant que
     * l'adresse n'est pas vérifiée. Un locataire inscrit avec l'adresse que son
     * bailleur a portée au dossier retrouve donc son bail dès sa première
     * connexion, sans démarche.
     */
    public function verify(
        Request $request,
        EmailOtpService $otp,
        TenantAccountLinker $linker,
        AuthController $auth,
    ): JsonResponse {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user === null) {
            // Même message que pour un code erroné : l'écart entre les deux
            // réponses dirait lesquelles de ces adresses sont inscrites.
            throw ValidationException::withMessages([
                'code' => 'Code incorrect ou expiré.',
            ]);
        }

        $otp->verify($user, $validated['code'], OtpPurpose::EmailVerification);
        $otp->markEmailVerified($user);

        $linked = $linker->link($user->refresh());

        return response()->json($auth->issueToken($user) + [
            'linked_tenant_profiles' => $linked,
        ]);
    }
}
