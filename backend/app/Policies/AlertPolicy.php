<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

class AlertPolicy
{
    public function view(User $user, Alert $alert): bool
    {
        return $alert->user_id === $user->id;
    }

    public function update(User $user, Alert $alert): bool
    {
        return $alert->user_id === $user->id;
    }
}
