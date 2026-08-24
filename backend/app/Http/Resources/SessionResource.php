<?php

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * État de la session en cours : quand elle se ferme, et pourquoi.
 *
 * Le front en a besoin pour prévenir avant la coupure. Sans cette information,
 * il ne peut que découvrir l'expiration en recevant un 401 — c'est-à-dire au
 * moment où l'utilisateur valide un formulaire, et perd sa saisie.
 *
 * Deux échéances sont renvoyées, parce qu'il y en a réellement deux : l'une
 * absolue depuis la connexion, l'autre glissante et repoussée par l'activité.
 * C'est la plus proche des deux qui compte.
 */
class SessionResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $token */
        $token = $this->resource;

        $absolute = $token->absoluteExpiresAt();
        $idle = $token->idleExpiresAt();

        $earliest = collect([$absolute, $idle])->filter()->sort()->first();

        return [
            'expires_at' => $absolute?->toIso8601String(),
            'idle_expires_at' => $idle?->toIso8601String(),
            'ends_at' => $earliest?->toIso8601String(),

            // Ce qui fermera la session en premier. « Votre session expire dans
            // 2 minutes, restez-vous ? » n'a de sens que pour l'inactivité :
            // sur l'échéance absolue, rester connecté ne changerait rien et il
            // faut inviter à se reconnecter.
            'ends_because' => $earliest === null
                ? null
                : ($earliest->equalTo($idle) ? 'inactivite' : 'duree_maximale'),

            'idle_minutes' => (int) config('immopro.session.idle_minutes'),
            'ttl_minutes' => (int) config('sanctum.expiration'),

            // Préavis demandé au front, en secondes.
            'warn_seconds' => (int) config('immopro.session.warn_seconds'),
        ];
    }
}
