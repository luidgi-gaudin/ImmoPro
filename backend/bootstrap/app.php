<?php

use App\Http\Middleware\MeasureQueryBudget;
use App\Models\PersonalAccessToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Rend visible, dans l'onglet réseau, le seul chiffre qui détermine le
        // temps de réponse d'une page : son nombre de requêtes SQL.
        // En tête de groupe, et non à la fin : `throttle:api` résout déjà
        // l'utilisateur pour établir sa clé de comptage, donc l'authentification
        // s'exécute avant lui. Un compteur placé après ne verrait ni la requête
        // du jeton, ni l'ouverture de connexion.
        $middleware->prependToGroup('api', MeasureQueryBudget::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Un 401 ne dit pas la même chose selon sa cause. « Session expirée »
         * appelle une reconnexion et rien d'autre ; « jeton invalide » peut
         * signaler un problème de configuration. Le front s'appuie sur `reason`
         * pour choisir son message plutôt que d'afficher « non authentifié »
         * dans les deux cas.
         */
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $expired = PersonalAccessToken::$rejectedForIdleTimeout;

            return response()->json([
                'message' => $expired
                    ? 'Votre session a expiré faute d\'activité. Reconnectez-vous pour continuer.'
                    : 'Authentification requise.',
                'reason' => $expired ? 'session_expired' : 'unauthenticated',
            ], 401);
        });
    })->create();
