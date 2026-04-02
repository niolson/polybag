<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function view(User $user, Location $location): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, Location $location): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
