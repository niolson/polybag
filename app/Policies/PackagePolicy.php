<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Package;
use App\Models\User;

class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Package $package): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function update(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function delete(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
