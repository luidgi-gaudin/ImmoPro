<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Sous-requêtes renvoyant du JSON, pour ramener une relation sans requête
 * supplémentaire.
 *
 * `with('coTenants')` est lisible mais coûte un aller-retour de plus — 150 ms
 * sur Supabase, la moitié du budget d'une page. Une sous-requête agrégée voyage
 * dans la requête principale et ne coûte rien de mesurable.
 *
 * À n'employer que pour des relations **bornées** : les colocataires d'un bail,
 * les trois premiers biens d'un portefeuille. Agréger une relation sans limite
 * déplacerait simplement le problème du réseau vers la mémoire du serveur.
 *
 * PostgreSQL et SQLite ne nomment pas ces fonctions pareil, d'où l'aiguillage.
 * Leur sémantique est en revanche identique : couples clé/valeur en entrée,
 * tableau JSON en sortie.
 */
class JsonAggregate
{
    /**
     * @param  array<string, string>  $columns  alias JSON => expression SQL
     * @param  string  $source  le « from … where … order by … limit … » de la sous-requête
     */
    public static function arrayOf(array $columns, string $source): string
    {
        $pairs = [];

        foreach ($columns as $alias => $expression) {
            $pairs[] = "'".str_replace("'", "''", $alias)."', {$expression}";
        }

        $object = implode(', ', $pairs);

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "(select coalesce(json_agg(json_build_object({$object})), '[]'::json) {$source})",
            default => "(select coalesce(json_group_array(json_object({$object})), '[]') {$source})",
        };
    }
}
