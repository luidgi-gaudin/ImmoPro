<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->id === $tenant->user_id;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->id === $tenant->user_id;
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->id === $tenant->user_id;
    }
}
