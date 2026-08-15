<?php

namespace App\Models\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Donne à un modèle une liste filtrable et triable pilotée par l'URL, avec les
 * mêmes conventions sur toute l'API :
 *
 *   ?search=dupont          recherche libre sur les colonnes déclarées
 *   ?sort=last_name         tri sur une colonne déclarée
 *   ?direction=asc|desc     sens du tri
 *   ?<filtre>=<valeur>      filtres métier déclarés par le modèle
 *
 * Tout repose sur des listes blanches : un paramètre inconnu est ignoré et
 * n'atteint jamais le SQL. Une valeur invalide est ignorée plutôt que rejetée —
 * une URL bricolée à la main ne doit pas casser l'écran de l'utilisateur.
 *
 * Le modèle qui utilise ce trait redéfinit les méthodes dont il a besoin.
 * Ce sont des méthodes et non des propriétés : une propriété déclarée à la fois
 * dans un trait et dans la classe qui l'utilise provoque une erreur fatale en
 * PHP dès que les valeurs par défaut diffèrent.
 */
trait Filterable
{
    /**
     * Colonnes balayées par ?search=. La notation « relation.colonne » est
     * acceptée, y compris sur plusieurs niveaux (« lease.property.city »).
     *
     * @return list<string>
     */
    protected function searchable(): array
    {
        return [];
    }

    /**
     * Colonnes autorisées pour ?sort=.
     *
     * @return list<string>
     */
    protected function sortable(): array
    {
        return [];
    }

    /**
     * Filtres métier. Deux écritures possibles :
     *
     *   'statut'                            égalité stricte sur la colonne
     *   'loue' => fn ($query, $valeur) =>   logique sur mesure
     *
     * @return array<int|string, string|Closure>
     */
    protected function filters(): array
    {
        return [];
    }

    /**
     * Expressions SQL de tri, pour les colonnes dont l'ordre alphabétique n'a
     * pas de sens métier — une énumération, typiquement.
     *
     * La clé doit figurer dans sortable(). L'expression est insérée telle quelle
     * dans la requête : elle est écrite ici, jamais construite à partir d'une
     * saisie utilisateur.
     *
     * @return array<string, string>
     */
    protected function sortExpressions(): array
    {
        return [];
    }

    /**
     * Tri appliqué quand ?sort= est absent ou invalide.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    /**
     * Point d'entrée unique : $query->filtered($request).
     */
    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        $this->applySearch($query, trim((string) $request->query('search', '')));
        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        return $query;
    }

    protected function applySearch(Builder $query, string $term): void
    {
        $columns = $this->searchable();

        if ($term === '' || $columns === []) {
            return;
        }

        // addcslashes neutralise les jokers SQL : une recherche sur « 100% »
        // ou « bail_2026 » cherche ces caractères littéralement, au lieu de les
        // interpréter comme des motifs LIKE.
        $pattern = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        $query->where(function (Builder $inner) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    // Tout sauf le dernier segment est le chemin de relation.
                    $position = strrpos($column, '.');
                    $relation = substr($column, 0, $position);
                    $field = substr($column, $position + 1);

                    $inner->orWhereHas(
                        $relation,
                        fn (Builder $related) => $this->whereLikeInsensitive($related, $field, $pattern)
                    );

                    continue;
                }

                $inner->orWhere(
                    fn (Builder $own) => $this->whereLikeInsensitive($own, $column, $pattern)
                );
            }
        });
    }

    /**
     * Recherche insensible à la casse et portable.
     *
     * PostgreSQL rend LIKE sensible à la casse et ne propose ILIKE que chez lui,
     * tandis que SQLite (utilisé par les tests) fait l'inverse. lower() existe
     * dans les deux, d'où ce passage par une expression brute. Le nom de colonne
     * vient d'une liste blanche du modèle et est échappé par la grammaire du
     * driver : il ne peut pas servir de vecteur d'injection.
     *
     * Contrepartie assumée : lower() sur la colonne empêche l'usage d'un index
     * classique. À l'échelle d'un parc immobilier c'est sans effet ; si une
     * table devait dépasser quelques dizaines de milliers de lignes, il faudrait
     * un index fonctionnel sur lower(colonne).
     */
    protected function whereLikeInsensitive(Builder $query, string $column, string $pattern): Builder
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap(
            str_contains($column, '.') ? $column : $query->getModel()->qualifyColumn($column)
        );

        // ESCAPE explicite : SQLite ne reconnaît aucun caractère d'échappement
        // par défaut, contrairement à PostgreSQL.
        return $query->whereRaw("lower({$wrapped}) like ? escape '\\'", [$pattern]);
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        foreach ($this->filters() as $key => $definition) {
            // Écriture courte : la valeur est le nom de colonne.
            $parameter = is_int($key) ? $definition : $key;

            if (! is_string($parameter)) {
                continue;
            }

            $value = $request->query($parameter);

            // Un filtre vide (« Tous » dans l'interface) ne restreint rien.
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            if ($definition instanceof Closure) {
                $definition($query, $value);

                continue;
            }

            $query->where($query->getModel()->qualifyColumn($parameter), $value);
        }
    }

    protected function applySort(Builder $query, Request $request): void
    {
        [$column, $direction] = $this->defaultSort();

        $requested = (string) $request->query('sort', '');

        if ($requested !== '' && in_array($requested, $this->sortable(), true)) {
            $column = $requested;
            // Sur un tri demandé explicitement, l'ordre croissant est le plus
            // attendu (A→Z, ancien→récent) ; le tri par défaut garde le sien.
            $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        }

        $expression = $this->sortExpressions()[$column] ?? null;

        if ($expression !== null) {
            $query->orderByRaw("{$expression} {$direction}");
        } else {
            $query->orderBy($query->getModel()->qualifyColumn($column), $direction);
        }

        // Départage indispensable : sans clé unique en second critère, deux
        // lignes de même valeur peuvent changer d'ordre entre deux requêtes, et
        // la pagination affiche alors des doublons ou saute des lignes.
        if ($column !== $query->getModel()->getKeyName()) {
            $query->orderBy($query->getModel()->qualifyColumn($query->getModel()->getKeyName()), 'desc');
        }
    }
}
