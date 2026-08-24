<?php

namespace App\Http\Controllers;

use App\Support\Database\SinglePassPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Taille de page demandée par le client, ramenée dans des bornes sûres.
     *
     * Le plafond n'est pas cosmétique : sans lui, un ?per_page=1000000 suffit à
     * faire charger toute la table en mémoire et à faire tomber le serveur.
     */
    protected function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        $requested = (int) $request->query('per_page', (string) $default);

        if ($requested < 1) {
            return $default;
        }

        return min($requested, $max);
    }

    /**
     * Pagine une liste en une seule requête SQL.
     *
     * À utiliser partout à la place de `->paginate()`, qui en exécute deux : un
     * comptage puis les lignes. Sur Supabase, ce comptage séparé coûte ~150 ms,
     * soit la moitié du budget d'une page entière — alors que la même
     * information voyage gratuitement avec les lignes (voir
     * SinglePassPaginator).
     *
     * `withQueryString()` est appliqué ici plutôt que rappelé dans chaque
     * contrôleur : l'oublier fait perdre recherche et filtres au passage à la
     * page 2, qui réaffiche alors la liste complète.
     *
     * Accepte aussi une relation (`$user->tenants()`), qui n'est pas un
     * constructeur de requêtes mais en enveloppe un, contraintes déjà posées.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, *>  $query
     */
    protected function paginate(Builder|Relation $query, Request $request): LengthAwarePaginator
    {
        return SinglePassPaginator::paginate(
            $query instanceof Relation ? $query->getQuery() : $query,
            $this->perPage($request)
        )->withQueryString();
    }
}
