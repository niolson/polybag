<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ShippingMethod;
use App\Models\User;

class ShippingMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function view(User $user, ShippingMethod $shippingMethod): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function update(User $user, ShippingMethod $shippingMethod): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function delete(User $user, ShippingMethod $shippingMethod): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }
}
