<?php

namespace App\Http\Controllers;

use App\Queries\DashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tout ce qu'affiche le tableau de bord, en un seul appel HTTP et une seule
 * requête SQL.
 *
 * L'écran demandait à l'origine cinq points d'entrée distincts, puis un seul
 * qui exécutait encore sept requêtes. Les deux réductions relèvent du même
 * constat : sur une base distante, ce qui coûte n'est pas le calcul mais
 * l'aller-retour. Le détail de la requête vit dans DashboardQuery.
 */
class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json((new DashboardQuery($request->user()))->get());
    }
}
