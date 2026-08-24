<?php

namespace App\Policies;

use App\Models\Lease;
use App\Models\User;

class LeasePolicy
{
    public function view(User $user, Lease $lease): bool
    {
        return $this->ownsLease($user, $lease);
    }

    public function update(User $user, Lease $lease): bool
    {
        return $this->ownsLease($user, $lease);
    }

    public function delete(User $user, Lease $lease): bool
    {
        return $this->ownsLease($user, $lease);
    }

    /**
     * Un bail appartient au bailleur propriétaire du portefeuille dont dépend
     * le bien loué.
     *
     * Quand le bail vient d'une résolution par l'URL, la réponse est déjà là :
     * `Lease::scopeWithOwner()` l'a ramenée dans la même requête. C'est le cas
     * de toutes les actions de l'API, et cela leur épargne un aller-retour de
     * 150 ms chacune.
     *
     * Le repli n'intervient que sur un bail construit en mémoire — un test, une
     * vérification avant création — où la colonne n'existe pas.
     */
    private function ownsLease(User $user, Lease $lease): bool
    {
        $owner = $lease->getAttribute('owner_user_id');

        if ($owner !== null) {
            return (int) $owner === $user->id;
        }

        return $user->portfolios()
            ->whereHas('properties', fn ($query) => $query->where('id', $lease->property_id))
            ->exists();
    }
}
