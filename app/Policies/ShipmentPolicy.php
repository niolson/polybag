<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\ShipmentStatus;
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
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, Shipment $shipment): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    /**
     * Pack slips are floor paperwork: every role that packs may print them.
     */
    public function printPackSlip(User $user, Shipment $shipment): bool
    {
        return true;
    }

    /**
     * Whether another package may be packed and bought for this shipment. Once
     * it has shipped, another package is a reshipment — a lost or damaged
     * parcel, or the wrong item sent — and a second spend on postage, so it
     * is a manager's decision.
     */
    public function reship(User $user, Shipment $shipment): bool
    {
        return $shipment->status !== ShipmentStatus::Shipped
            || $user->role->isAtLeast(Role::Manager);
    }
}
