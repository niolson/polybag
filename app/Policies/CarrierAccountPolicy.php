<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CarrierAccount;
use App\Models\User;

class CarrierAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, CarrierAccount $carrierAccount): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, CarrierAccount $carrierAccount): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, CarrierAccount $carrierAccount): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
