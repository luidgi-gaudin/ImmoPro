<?php

namespace App\Http\Resources;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compte de l'utilisateur connecté.
 *
 * Ne porte que ce que le front doit savoir pour décider quoi afficher. Les
 * coordonnées bancaires et le SIRET sont volontairement absents : ils sont
 * chiffrés au repos, et une réponse d'API renvoyée à chaque appel
 * d'authentification n'est pas l'endroit où les faire circuler.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,

            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'home_path' => $this->role->homePath(),

            /*
             * Adresse en attente de confirmation, s'il y en a une. L'écran de
             * profil doit pouvoir dire « en attente : nouvelle@exemple.fr » :
             * sans cela, un changement laissé en plan est invisible, et
             * l'utilisateur croit son adresse déjà modifiée.
             */
            'pending_email' => $this->pending_email,

            'email_verified' => $this->hasVerifiedEmail(),
            'has_password' => $this->hasUsablePassword(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),

            'has_avatar' => filled($this->avatar_path),

            /*
             * Fournisseurs déjà rattachés, pour que l'écran de profil affiche
             * « Rattaché » plutôt qu'un bouton qui échouera.
             *
             * `whenLoaded` : la relation n'est chargée que là où elle sert. Sur
             * la réponse d'authentification, jouée à chaque démarrage de
             * l'application, une requête de plus se paierait à chaque visite.
             */
            'social_accounts' => $this->whenLoaded(
                'socialAccounts',
                fn () => $this->socialAccounts
                    ->map(fn (SocialAccount $account) => [
                        'provider' => $account->provider->value,
                        'label' => $account->provider->label(),
                        'email' => $account->provider_email,
                        'linked_at' => $account->created_at,
                    ])
                    ->values()
                    ->all()
            ),

            /*
             * Fournisseurs configurés sur ce serveur. Sans cette liste, le front
             * afficherait des boutons « Continuer avec Apple » sur une
             * installation où Apple n'est pas paramétré.
             */
            'available_providers' => array_values(array_filter(
                SocialProvider::catalogue(),
                fn (array $provider) => $provider['enabled']
            )),

            'created_at' => $this->created_at,
        ];
    }
}
