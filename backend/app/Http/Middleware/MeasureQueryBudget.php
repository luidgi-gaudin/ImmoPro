<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte les requêtes SQL d'un appel et le déclare dans les en-têtes.
 *
 * Sur Supabase, un aller-retour coûte ~135 ms et le calcul PHP est négligeable :
 * le temps de réponse d'une page, c'est son nombre de requêtes. Le budget que
 * s'impose ImmoPro est de **deux** — une pour l'authentification, une pour les
 * données — ce qui place une page autour de 290 ms.
 *
 * Ce nombre est invisible autrement. Une requête ajoutée dans une boucle, une
 * relation oubliée dans un `with()`, un accesseur qui relit son parent : rien
 * de tout cela ne se voit à la lecture du code, et tout se paie chez
 * l'utilisateur. L'afficher dans l'onglet réseau du navigateur le rend
 * immédiat.
 *
 * Actif uniquement en débogage : en production, ces en-têtes renseigneraient un
 * attaquant sur la structure interne, et le journal des requêtes consomme de la
 * mémoire proportionnellement au trafic.
 */
class MeasureQueryBudget
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.debug')) {
            return $next($request);
        }

        $queries = 0;
        $milliseconds = 0.0;

        DB::listen(function ($query) use (&$queries, &$milliseconds): void {
            $queries++;
            $milliseconds += $query->time;
        });

        $start = microtime(true);
        $response = $next($request);

        $response->headers->set('X-Immopro-Queries', (string) $queries);
        $response->headers->set('X-Immopro-Db-Ms', (string) round($milliseconds));
        $response->headers->set('X-Immopro-Total-Ms', (string) round((microtime(true) - $start) * 1000));

        return $response;
    }
}
