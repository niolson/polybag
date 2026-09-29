<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\SpecialService;
use App\Models\User;

class SpecialServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, SpecialService $specialService): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, SpecialService $specialService): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, SpecialService $specialService): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
