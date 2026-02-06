<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function update(User $user, Shipment $shipment): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
