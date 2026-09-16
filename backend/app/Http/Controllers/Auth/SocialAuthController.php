<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\IdentityTokenVerifier;
use App\Services\Auth\SocialAuthService;
use App\Services\Auth\TenantAccountLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inscription et connexion par Google ou Apple.
 *
 * Le navigateur obtient lui-même le jeton d'identité auprès du fournisseur et
 * le poste ici ; le serveur le vérifie. Ce découpage évite d'avoir à détenir un
 * secret client et à gérer une redirection, mais il déplace toute la
 * responsabilité sur la vérification : un jeton d'identité non vérifié n'est
 * qu'un texte que n'importe qui peut écrire. Les quatre contrôles qui le rendent
 * probant sont détaillés dans App\Services\Auth\IdentityTokenVerifier.
 */
class SocialAuthController extends Controller
{
    /**
     * Fournisseurs utilisables sur cette installation.
     *
     * Public : le formulaire de connexion doit savoir quels boutons afficher
     * avant que quiconque soit authentifié. N'expose que des identifiants
     * publics, ceux-là mêmes que le navigateur devra présenter.
     */
    public function providers(): JsonResponse
    {
        return response()->json([
            'data' => array_values(array_filter(
                SocialProvider::catalogue(),
                fn (array $provider) => $provider['enabled']
            )),
        ]);
    }

    /**
     * Connexion, ou inscription au premier passage.
     *
     * `role` n'est lu qu'à la création. Le renvoyer à chaque connexion serait
     * une faille de logique : il suffirait de rejouer la connexion avec l'autre
     * valeur pour changer de profil, et un locataire se retrouverait bailleur.
     */
    public function callback(
        Request $request,
        string $provider,
        IdentityTokenVerifier $verifier,
        SocialAuthService $social,
        TenantAccountLinker $linker,
        AuthController $auth,
    ): JsonResponse {
        $validated = $this->validatePayload($request);

        $identity = $verifier->verify(
            $this->provider($provider),
            $validated['id_token'],
            $validated['nonce'] ?? null,
        );

        [$user, $created] = $social->resolve(
            $identity,
            isset($validated['role']) ? UserRole::from($validated['role']) : null,
        );

        $linker->link($user);

        return response()->json(
            $auth->issueToken($user) + ['created' => $created],
            $created ? 201 : 200
        );
    }

    /**
     * Rattache un fournisseur à un compte déjà connecté.
     *
     * Permet d'ajouter Google à un compte ouvert par mot de passe, ou de
     * cumuler Google et Apple sur le même compte.
     */
    public function link(
        Request $request,
        string $provider,
        IdentityTokenVerifier $verifier,
        SocialAuthService $social,
    ): JsonResponse {
        $validated = $this->validatePayload($request);

        $identity = $verifier->verify(
            $this->provider($provider),
            $validated['id_token'],
            $validated['nonce'] ?? null,
        );

        $social->link($request->user(), $identity);

        return response()->json([
            'data' => new UserResource($request->user()->load('socialAccounts')),
            'message' => "Compte {$identity->provider->label()} rattaché.",
        ]);
    }

    /**
     * Détache un fournisseur.
     *
     * Refusé quand c'est le dernier moyen de se connecter : un compte créé par
     * Google porte un mot de passe aléatoire que personne ne connaît, et le
     * détacher enfermerait son titulaire dehors. La réinitialisation par
     * courriel reste possible, mais on ne met pas quelqu'un à la porte en lui
     * indiquant la fenêtre.
     */
    public function unlink(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();
        $target = $this->provider($provider);

        $account = $user->socialAccounts()->where('provider', $target->value)->first();

        if ($account === null) {
            return response()->json([
                'message' => "Aucun compte {$target->label()} n'est rattaché.",
            ], 404);
        }

        if ($user->socialAccounts()->count() === 1 && ! $user->hasUsablePassword()) {
            return response()->json([
                'message' => "Définissez d'abord un mot de passe : {$target->label()} est "
                    .'actuellement votre seul moyen de connexion.',
            ], 409);
        }

        $account->delete();

        return response()->json([
            'data' => new UserResource($user->load('socialAccounts')),
            'message' => "Compte {$target->label()} détaché.",
        ]);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],

            // Valeur à usage unique posée par le client à l'ouverture de la
            // fenêtre du fournisseur, puis retrouvée dans le jeton : c'est ce
            // qui distingue une connexion en cours d'un jeton rejoué.
            'nonce' => ['nullable', 'string', 'max:255'],

            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);
    }

    private function provider(string $provider): SocialProvider
    {
        return SocialProvider::tryFrom($provider)
            ?? abort(404, 'Fournisseur d\'identité inconnu.');
    }
}
