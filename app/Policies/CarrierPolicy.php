<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Carrier;
use App\Models\User;

class CarrierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, Carrier $carrier): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, Carrier $carrier): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, Carrier $carrier): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
