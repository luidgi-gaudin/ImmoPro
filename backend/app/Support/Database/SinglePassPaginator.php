<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Pagination en une seule requête, au lieu des deux de `paginate()`.
 *
 * Laravel compte d'abord les lignes, puis va chercher la page demandée. Deux
 * allers-retours pour un écran de liste. Sur une base locale l'un des deux est
 * imperceptible ; sur Supabase il coûte ~150 ms, soit la moitié du budget que
 * s'impose ImmoPro pour une page entière.
 *
 * La fonction de fenêtrage `count(*) over ()` donne le total *après* filtrage
 * mais *avant* LIMIT, ce qui est exactement ce dont la barre de pagination a
 * besoin — et elle voyage avec les lignes, gratuitement. Elle est normalisée
 * par SQL:2003 et comprise aussi bien par PostgreSQL que par le SQLite des
 * tests.
 *
 * Le total n'est présent que sur les lignes rapportées : une page vide ne dit
 * rien du total. Ce n'est pas gênant — une page vide au-delà de la première
 * signifie que l'utilisateur a demandé une page qui n'existe plus, et la
 * première page vide signifie bien zéro résultat.
 */
class SinglePassPaginator
{
    /** Colonne technique portant le total. Retirée des modèles avant leur retour. */
    private const TOTAL = '__immopro_total';

    public static function paginate(Builder $query, int $perPage, string $pageName = 'page'): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage($pageName);
        $model = $query->getModel();

        // `select *` explicite : sans lui, ajouter une expression à la liste de
        // sélection remplacerait les colonnes du modèle au lieu de s'y ajouter.
        if (empty($query->getQuery()->columns)) {
            $query->select($model->qualifyColumn('*'));
        }

        $rows = (clone $query)
            ->selectRaw('count(*) over () as '.self::TOTAL)
            ->forPage($page, $perPage)
            ->get();

        $total = $rows->isEmpty()
            ? self::totalForEmptyPage($query, $page, $perPage)
            : (int) $rows->first()->getAttribute(self::TOTAL);

        $rows->each(function (Model $row): void {
            // La colonne technique n'a rien à faire dans la réponse JSON.
            unset($row->{self::TOTAL});
            $row->syncOriginal();
        });

        return new LengthAwarePaginator($rows, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Page vide : le total est indéterminé.
     *
     * Sur la première page, c'est sans ambiguïté — il n'y a aucun résultat.
     * Au-delà, l'utilisateur est arrivé sur une page qui n'existe plus (un
     * élément supprimé entre-temps, un favori ancien) : plutôt que de payer une
     * requête de comptage pour l'apprendre, on renvoie un total qui ramène la
     * pagination à la page précédente, où il trouvera des lignes.
     */
    private static function totalForEmptyPage(Builder $query, int $page, int $perPage): int
    {
        return $page <= 1 ? 0 : ($page - 1) * $perPage;
    }
}
