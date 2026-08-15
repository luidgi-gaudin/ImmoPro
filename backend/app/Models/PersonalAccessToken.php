<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Jeton d'accès Sanctum, avec l'horodatage `last_used_at` écrit avec parcimonie.
 *
 * Sanctum réécrit ce champ à *chaque* requête authentifiée. Sur une base locale
 * c'est indolore ; sur Supabase, c'est une écriture distante d'environ 110 ms
 * ajoutée à toutes les pages de l'application, pour une information dont la
 * précision à la seconde n'a aucun usage.
 *
 * On ne conserve donc l'écriture que si la valeur enregistrée date de plus de
 * quelques minutes. L'information reste exacte à cet intervalle près — largement
 * suffisant pour repérer un jeton dormant ou auditer un accès.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Fenêtre en dessous de laquelle une nouvelle écriture n'apprend rien.
     */
    private const FRESHNESS_MINUTES = 5;

    public function save(array $options = []): bool
    {
        if ($this->onlyRefreshesLastUsedAt() && $this->lastUseIsRecent()) {
            // On garde la valeur en mémoire pour la requête en cours, sans
            // toucher la base.
            $this->syncChanges();
            $this->syncOriginal();

            return true;
        }

        return parent::save($options);
    }

    /**
     * Vrai quand l'unique modification est l'horodatage d'usage : il ne faut
     * surtout pas court-circuiter la création d'un jeton ou une révocation.
     */
    private function onlyRefreshesLastUsedAt(): bool
    {
        return $this->exists && array_keys($this->getDirty()) === ['last_used_at'];
    }

    private function lastUseIsRecent(): bool
    {
        $previous = $this->getOriginal('last_used_at');

        if ($previous === null) {
            // Première utilisation du jeton : on l'enregistre.
            return false;
        }

        return $this->asDateTime($previous)->greaterThan(now()->subMinutes(self::FRESHNESS_MINUTES));
    }
}
