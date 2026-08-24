<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Identité transmise à PostgreSQL pour la Row Level Security.
 *
 * Les policies posées par la migration `enable_row_level_security` comparent le
 * propriétaire de chaque ligne au résultat de `public.app_user_id()`, qui lit
 * lui-même le paramètre de session `app.user_id`. Cette classe est le seul
 * endroit qui pose ce paramètre.
 *
 * Le point important est le coût. Un `SET` isolé est un aller-retour complet
 * vers Supabase — mesuré à 155 ms, soit la moitié du budget d'une page. Mais
 * `set_config()` glissé dans la liste de sélection d'une requête déjà
 * nécessaire ne coûte rien de mesurable (135 ms contre 135 ms). Toute la
 * mécanique consiste donc à faire voyager l'identité avec une requête existante
 * plutôt qu'à en ajouter une :
 *
 *   - en régime normal, `App\Models\PersonalAccessToken::findToken()` résout le
 *     jeton, charge le bailleur et pose le paramètre dans **une seule** requête,
 *     puis appelle `markBound()` ;
 *   - `ensureBound()` n'est qu'un filet : il ne déclenche un aller-retour que
 *     si du code atteint une table protégée sans que l'identité ait été posée.
 *
 * Sans identité posée, `app_user_id()` renvoie NULL et toutes les comparaisons
 * des policies valent NULL : l'accès est refusé. L'échec est donc fermé, jamais
 * ouvert — c'est ce qui rend le filet acceptable.
 */
class Rls
{
    /**
     * Valeur distincte de `null`, qui signifie « visiteur anonyme, paramètre
     * volontairement vide ». `false` signifie « rien n'a encore été posé ».
     */
    private static int|null|false $bound = false;

    /** La RLS ne concerne que PostgreSQL ; les tests tournent sur SQLite. */
    public static function active(): bool
    {
        return config('immopro.rls.enabled')
            && DB::connection()->getDriverName() === 'pgsql';
    }

    public static function setting(): string
    {
        return config('immopro.rls.setting', 'app.user_id');
    }

    /**
     * Enregistre que le paramètre vient d'être posé par une autre requête.
     * À n'appeler que depuis le code qui a réellement exécuté le `set_config`.
     */
    public static function markBound(?int $userId): void
    {
        self::$bound = $userId;
    }

    /**
     * Garantit que l'identité est connue de la base avant une requête sur une
     * table protégée. Ne fait rien si elle l'est déjà — ce qui est le cas sur
     * toutes les requêtes authentifiées.
     */
    public static function ensureBound(): void
    {
        if (self::$bound !== false || ! self::active()) {
            return;
        }

        $user = Auth::user();

        self::bind($user instanceof User ? $user->id : null);
    }

    /**
     * Pose l'identité par une requête dédiée. Coûteux : réservé au filet de
     * sécurité et aux commandes en ligne (scan d'alertes, tinker).
     */
    public static function bind(?int $userId): void
    {
        if (! self::active()) {
            self::$bound = $userId;

            return;
        }

        DB::selectOne('select set_config(?, ?, false)', [
            self::setting(),
            $userId === null ? '' : (string) $userId,
        ]);

        self::$bound = $userId;
    }

    /**
     * Fragment SQL à glisser dans la liste de sélection d'une requête existante
     * pour poser l'identité sans aller-retour supplémentaire.
     *
     * `$valueSql` est une expression SQL, pas une valeur : typiquement
     * `u.id::text` quand la requête ramène déjà la ligne du bailleur. Elle est
     * écrite par le code appelant, jamais construite à partir d'une saisie.
     */
    public static function bindingExpression(string $valueSql): string
    {
        return sprintf('set_config(%s, %s, false)', DB::getPdo()->quote(self::setting()), $valueSql);
    }

    /**
     * Exécute un traitement qui doit légitimement voir tous les bailleurs.
     *
     * Le scan d'alertes en est le seul cas : il travaille pour le compte de
     * tout le monde, et sous le rôle applicatif il ne verrait rien du tout —
     * pas une erreur, simplement zéro ligne, ce qui est bien pire à
     * diagnostiquer.
     *
     * Le traitement bascule le temps de son exécution sur la connexion
     * privilégiée, puis la connexion par défaut est rétablie, y compris si le
     * traitement échoue. Rien de ce qui vient d'une requête HTTP ne doit passer
     * par ici.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutRestrictions(callable $callback): mixed
    {
        if (! self::active()) {
            return $callback();
        }

        $previous = config('database.default');
        $bound = self::$bound;

        config(['database.default' => 'pgsql_admin']);
        self::$bound = false;

        try {
            return $callback();
        } finally {
            config(['database.default' => $previous]);
            self::$bound = $bound;
        }
    }

    /**
     * Remet le compteur à zéro entre deux requêtes HTTP simulées. Utilisé par
     * les tests, jamais en production où chaque requête part d'un processus
     * neuf.
     */
    public static function forget(): void
    {
        self::$bound = false;
    }

    /** Identité actuellement posée, pour les assertions de test. */
    public static function boundTo(): int|null|false
    {
        return self::$bound;
    }
}
