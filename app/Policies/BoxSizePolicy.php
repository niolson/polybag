<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\BoxSize;
use App\Models\User;

class BoxSizePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function view(User $user, BoxSize $boxSize): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function update(User $user, BoxSize $boxSize): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function delete(User $user, BoxSize $boxSize): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }
}
