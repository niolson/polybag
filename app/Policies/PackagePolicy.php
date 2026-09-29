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
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function delete(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function ship(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::User);
    }

    /**
     * Handling a bought label — printing it, recording that it was printed, or
     * voiding it — is limited to managers and the operator who shipped the
     * package. Batch shipping is admin only, so batch operators always clear the
     * manager bar.
     */
    public function printLabel(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::Manager)
            || $package->shipped_by_user_id === $user->id;
    }
}
