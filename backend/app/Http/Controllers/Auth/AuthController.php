<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\SessionResource;
use App\Http\Resources\UserResource;
use App\Models\Alert;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\EmailOtpService;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    /**
     * Ouverture d'un compte, sans jeton de session.
     *
     * L'inscription ne connecte pas : elle envoie un code à usage unique, que
     * `EmailOtpController::verify()` échange contre un jeton. Rendre un jeton
     * dès ici ferait de la vérification une formalité contournable — il aurait
     * suffi d'ignorer l'écran du code pour entrer quand même, et l'application
     * se retrouverait avec des comptes dont l'adresse n'appartient à personne.
     *
     * L'échec de l'envoi n'annule pas l'inscription : le compte existe, et le
     * code se redemande. Perdre un compte parce qu'un serveur de messagerie
     * répondait mal serait le pire des deux résultats.
     */
    public function register(RegisterRequest $request, EmailOtpService $otp): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'role' => $request->role()->value,
        ]);

        $sent = true;

        /*
         * L'échec de l'envoi ne doit pas emporter l'inscription.
         *
         * Le compte est déjà créé quand le code part. Laisser l'exception
         * remonter rendrait une erreur 500 sur un compte pourtant enregistré,
         * et enfermerait son titulaire dehors pour de bon : il ne pourrait ni
         * se réinscrire, l'adresse étant prise, ni se connecter, l'adresse
         * n'étant pas vérifiée.
         *
         * La panne est donc signalée sans être fatale, et le code se redemande
         * depuis l'écran de vérification.
         */
        try {
            $otp->send($user, OtpPurpose::EmailVerification);
        } catch (Throwable $exception) {
            $sent = false;

            report($exception);
        }

        return response()->json([
            'data' => new UserResource($user),
            'email_verification_required' => true,
            'otp' => [
                'expires_in_minutes' => $otp->validityMinutes(),
                'resend_after_seconds' => $otp->resendIntervalSeconds(),
            ],
            'message' => $sent
                ? 'Un code de vérification vient de vous être envoyé par e-mail.'
                : 'Votre compte est créé, mais l\'envoi du code a échoué. '
                    .'Demandez un nouveau code dans un instant.',
        ], 201);
    }

    public function login(LoginRequest $request, TwoFactorAuthService $twoFactor): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        /*
         * Adresse non vérifiée : pas de jeton.
         *
         * Le contrôle vient après celui du mot de passe, et pas avant. Placé
         * en tête, il révélerait à un inconnu quels comptes existent et
         * lesquels sont encore en attente de vérification — une liste
         * d'adresses valides offerte à qui la demande.
         */
        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'email_verification_required' => true,
                'email' => $user->email,
                'message' => 'Votre adresse n\'est pas encore vérifiée. Saisissez le code reçu par e-mail.',
            ], 403);
        }

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $twoFactor->createChallenge($user),
                'message' => 'Veuillez fournir votre code de double authentification.',
            ]);
        }

        return response()->json($this->issueToken($user));
    }

    /**
     * Émet un jeton et annonce d'emblée quand la session se fermera.
     *
     * Communiquer l'échéance dès la connexion permet au front de programmer son
     * préavis sans avoir à interroger l'API en boucle pour savoir où il en est.
     *
     * @return array<string, mixed>
     */
    public function issueToken(User $user): array
    {
        $token = $user->createToken('auth_token');

        /** @var PersonalAccessToken $accessToken */
        $accessToken = $token->accessToken;

        return [
            'data' => new UserResource($user),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'session' => new SessionResource($accessToken),
            'alerts_unread' => $this->unreadAlerts($user),
        ];
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    public function user(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'data' => new UserResource($request->user()),
            'session' => $token instanceof PersonalAccessToken ? new SessionResource($token) : null,
            'alerts_unread' => $this->unreadAlerts($request->user()),
        ]);
    }

    /**
     * Nombre d'alertes actives non lues, joint à la réponse d'authentification.
     *
     * La pastille de la barre latérale est visible sur tous les écrans, mais ce
     * compteur n'était connu que du tableau de bord et de la page des alertes :
     * arriver ailleurs affichait « 0 » quelle que soit la réalité. Le faire
     * voyager ici évite d'ajouter un appel HTTP au démarrage de l'application —
     * une requête SQL de plus coûte 150 ms, un aller-retour HTTP complet en
     * coûte le double.
     */
    private function unreadAlerts(User $user): int
    {
        return Alert::forUser($user)->active()->unread()->count();
    }

    /**
     * « Rester connecté » : repousse l'échéance d'inactivité.
     *
     * L'horodatage d'usage n'est réécrit qu'au-delà de cinq minutes (voir
     * PersonalAccessToken) — ce qui tombe bien, puisque cet appel n'a de sens
     * qu'après une longue inactivité. Aucune écriture inutile n'est donc
     * déclenchée par un clic répété.
     *
     * L'échéance absolue, elle, ne bouge pas : c'est ce qui la rend absolue.
     */
    public function extendSession(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json(['message' => 'Session introuvable.'], 409);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json(['session' => new SessionResource($token->refresh())]);
    }
}
