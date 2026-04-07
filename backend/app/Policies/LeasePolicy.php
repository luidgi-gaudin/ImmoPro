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

    private function ownsLease(User $user, Lease $lease): bool
    {
        return $user->portfolios()
            ->whereHas('properties', fn ($q) => $q->where('id', $lease->property_id))
            ->exists();
    }
}
