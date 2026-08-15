<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

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
}
