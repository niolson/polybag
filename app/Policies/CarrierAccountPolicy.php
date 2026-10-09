<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CarrierAccount;
use App\Models\User;

/**
 * Carrier accounts carry the billing identity labels are bought on, and their
 * scopes decide which account each client and location buys on. Like App
 * Settings and Connections, they are Admin-only.
 */
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

    public function deleteAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    /**
     * Accepting USPS's prepaid-duties terms makes the account holder liable for
     * duties and taxes on every DDP label, so a manager cannot.
     */
    public function acceptDdpTerms(User $user, CarrierAccount $carrierAccount): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
