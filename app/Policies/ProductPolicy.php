<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }
}
