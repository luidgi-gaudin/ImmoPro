<?php

namespace App\Http\Controllers;

use App\Queries\ReportQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Rapport agrégé pour le bailleur : vue d'ensemble du patrimoine, des baux
     * et des paiements du mois en cours, ventilée par bien et par locataire.
     *
     * Le détail vit dans ReportQuery, qui fait tout en une requête SQL.
     */
    public function overview(Request $request): JsonResponse
    {
        return response()->json((new ReportQuery($request->user()))->get());
    }
}
