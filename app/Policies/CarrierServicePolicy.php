<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CarrierService;
use App\Models\User;

class CarrierServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, CarrierService $carrierService): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, CarrierService $carrierService): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, CarrierService $carrierService): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
