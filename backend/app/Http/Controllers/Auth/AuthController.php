<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\SessionResource;
use App\Http\Resources\UserResource;
use App\Models\Alert;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);

        return response()->json($this->issueToken($user), 201);
    }

    public function login(LoginRequest $request, TwoFactorAuthService $twoFactor): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
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
