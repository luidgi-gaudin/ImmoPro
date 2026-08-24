<?php

namespace App\Models;

use App\Support\Rls;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Jeton d'accès Sanctum : résolution en une seule requête, pose de l'identité
 * RLS au passage, expiration par inactivité, et `last_used_at` écrit avec
 * parcimonie.
 *
 * Quatre choses se jouent ici, toutes pour la même raison : sur Supabase, un
 * aller-retour SQL coûte ~135 ms, et l'authentification s'exécute sur *chaque*
 * requête de l'application.
 *
 * 1. Sanctum résout un jeton en deux requêtes — la ligne du jeton, puis le
 *    porteur. `findToken()` les fusionne en une jointure unique : 270 ms
 *    économisés sur toutes les pages.
 * 2. Cette même requête pose `app.user_id` via `set_config()` dans sa liste de
 *    sélection. La Row Level Security devient donc gratuite : mesuré à 135 ms
 *    contre 135 ms sans elle, là où un `SET` séparé coûterait 155 ms de plus.
 * 3. L'expiration par inactivité se lit sur la ligne déjà chargée, sans requête
 *    supplémentaire.
 * 4. Sanctum réécrit `last_used_at` à chaque requête authentifiée. Sur une base
 *    locale c'est indolore ; ici c'est une écriture distante ajoutée partout,
 *    pour une information dont la précision à la seconde n'a aucun usage.
 *
 * Cette classe n'est pas destinée à être étendue : Sanctum n'accepte qu'un seul
 * modèle de jeton, et c'est celui-ci.
 *
 * @final
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Fenêtre en dessous de laquelle réécrire `last_used_at` n'apprend rien.
     *
     * Elle borne aussi la précision de l'expiration par inactivité : une
     * session peut être coupée jusqu'à cinq minutes trop tôt. Sur un seuil de
     * deux heures, c'est sans conséquence — et l'erreur va dans le sens sûr.
     */
    private const FRESHNESS_MINUTES = 5;

    /**
     * Vrai lorsque le rejet vient de l'inactivité et non d'un jeton inconnu.
     * Lu par le gestionnaire d'exceptions pour distinguer « votre session a
     * expiré » de « identifiants invalides », deux messages qui n'appellent pas
     * la même réaction de l'utilisateur.
     */
    public static bool $rejectedForIdleTimeout = false;

    /**
     * Résout le jeton, son porteur et l'identité RLS en une seule requête.
     *
     * @param  string  $token
     */
    public static function findToken($token): ?self
    {
        self::$rejectedForIdleTimeout = false;

        [$id, $secret] = self::splitToken($token);

        $found = DB::connection()->getDriverName() === 'pgsql'
            ? self::findTokenInOneQuery($id, $secret)
            : self::findTokenPortably($id, $secret);

        if ($found === null) {
            return null;
        }

        if ($found->hasBeenIdleTooLong()) {
            self::$rejectedForIdleTimeout = true;

            return null;
        }

        return $found;
    }

    /**
     * Sépare « 42|clé-en-clair » en identifiant et secret. Les jetons émis
     * avant l'introduction du préfixe numérique ne portent que le secret : leur
     * identifiant est alors inconnu, et la recherche se fait sur le condensat.
     *
     * @return array{0: int|null, 1: string}
     */
    private static function splitToken(string $token): array
    {
        if (! str_contains($token, '|')) {
            return [null, $token];
        }

        [$id, $secret] = explode('|', $token, 2);

        return [ctype_digit($id) ? (int) $id : null, $secret];
    }

    /**
     * Chemin rapide PostgreSQL : jeton, bailleur et identité RLS d'un seul
     * aller-retour.
     *
     * `to_jsonb` évite d'avoir à énumérer les colonnes des deux tables — une
     * liste figée ici se désynchroniserait de la première migration venue.
     * `set_config` est une fonction volatile évaluée par ligne : sur l'unique
     * ligne renvoyée, elle pose l'identité du porteur. Sans ligne — jeton
     * inconnu — rien n'est posé, et les policies refusent tout.
     *
     * Ni `personal_access_tokens` ni `users` ne sont sous RLS : ce sont les
     * tables du chemin d'authentification, qui s'exécute par construction avant
     * qu'une identité existe.
     */
    private static function findTokenInOneQuery(?int $id, string $secret): ?self
    {
        $binding = Rls::active()
            ? Rls::bindingExpression('u.id::text')
            : 'null';

        $row = DB::selectOne(
            'select to_jsonb(t) as token_row, to_jsonb(u) as user_row, '.$binding.' as rls_bound
               from personal_access_tokens t
               join users u
                 on u.id = t.tokenable_id
                and u.deleted_at is null
              where t.tokenable_type = ?
                and '.($id === null ? 't.token = ?' : 't.id = ?').'
              limit 1',
            [User::class, $id ?? hash('sha256', $secret)]
        );

        if ($row === null) {
            return null;
        }

        $attributes = json_decode($row->token_row, true);

        // Le condensat n'est comparé qu'ici, en temps constant : la recherche
        // par identifiant seule ne prouve rien.
        if ($id !== null && ! hash_equals((string) $attributes['token'], hash('sha256', $secret))) {
            return null;
        }

        $token = (new self)->newFromBuilder($attributes);
        $token->setRelation('tokenable', (new User)->newFromBuilder(json_decode($row->user_row, true)));

        Rls::markBound((int) $attributes['tokenable_id']);

        return $token;
    }

    /**
     * Chemin portable, utilisé par les tests sur SQLite. Deux requêtes, ce qui
     * est sans importance sur une base en mémoire.
     */
    private static function findTokenPortably(?int $id, string $secret): ?self
    {
        $token = $id === null
            ? static::where('token', hash('sha256', $secret))->first()
            : static::find($id);

        if ($token === null) {
            return null;
        }

        if ($id !== null && ! hash_equals($token->token, hash('sha256', $secret))) {
            return null;
        }

        Rls::markBound((int) $token->tokenable_id);

        return $token;
    }

    /**
     * Inactivité dépassée : la session est close même si le jeton n'a pas
     * atteint sa durée de vie absolue.
     */
    public function hasBeenIdleTooLong(): bool
    {
        $idleMinutes = (int) config('immopro.session.idle_minutes');

        if ($idleMinutes <= 0) {
            return false;
        }

        // Un jeton jamais utilisé date de sa création : c'est le repère.
        $lastSeen = $this->last_used_at ?? $this->created_at;

        return $lastSeen instanceof Carbon
            && $lastSeen->lt(now()->subMinutes($idleMinutes));
    }

    /** Instant auquel la session se fermera faute d'activité. */
    public function idleExpiresAt(): ?Carbon
    {
        $idleMinutes = (int) config('immopro.session.idle_minutes');

        if ($idleMinutes <= 0) {
            return null;
        }

        $lastSeen = $this->last_used_at ?? $this->created_at;

        return $lastSeen instanceof Carbon ? $lastSeen->copy()->addMinutes($idleMinutes) : null;
    }

    /** Instant auquel la session se fermera quoi qu'il arrive. */
    public function absoluteExpiresAt(): ?Carbon
    {
        if ($this->expires_at instanceof Carbon) {
            return $this->expires_at;
        }

        $ttl = (int) config('sanctum.expiration');

        return $ttl > 0 && $this->created_at instanceof Carbon
            ? $this->created_at->copy()->addMinutes($ttl)
            : null;
    }

    public function save(array $options = []): bool
    {
        if ($this->onlyRefreshesLastUsedAt() && $this->lastUseIsRecent()) {
            // On garde la valeur en mémoire pour la requête en cours, sans
            // toucher la base.
            $this->syncChanges();
            $this->syncOriginal();

            return true;
        }

        return parent::save($options);
    }

    /**
     * Vrai quand l'unique modification est l'horodatage d'usage : il ne faut
     * surtout pas court-circuiter la création d'un jeton ou une révocation.
     */
    private function onlyRefreshesLastUsedAt(): bool
    {
        return $this->exists && array_keys($this->getDirty()) === ['last_used_at'];
    }

    private function lastUseIsRecent(): bool
    {
        $previous = $this->getOriginal('last_used_at');

        if ($previous === null) {
            // Première utilisation du jeton : on l'enregistre.
            return false;
        }

        return $this->asDateTime($previous)->greaterThan(now()->subMinutes(self::FRESHNESS_MINUTES));
    }
}
