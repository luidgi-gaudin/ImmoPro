<?php

namespace App\Http\Controllers;

use App\Queries\GlobalSearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    /**
     * Recherche globale à travers toutes les entités du bailleur connecté.
     *
     * Les résultats sont strictement cantonnés à son compte, et la Row Level
     * Security le garantit une seconde fois côté base. Le détail vit dans
     * GlobalSearchQuery.
     */
    public function search(Request $request): JsonResponse
    {
        $query = new GlobalSearchQuery($request->user());

        return response()->json($query->search(trim((string) $request->query('q', ''))));
    }
}
