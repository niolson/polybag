<?php

namespace App\Policies;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\Package;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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

    public function ship(User $user, Package $package): Response|bool
    {
        if (! $user->role->isAtLeast(Role::User)) {
            return false;
        }

        // A package already bought is refused by the Ship page itself, with a
        // better message than this one.
        if ($package->status === PackageStatus::Shipped || ! $package->shipment || $user->can('reship', $package->shipment)) {
            return true;
        }

        return Response::deny('This shipment has already shipped. Only a manager can send another package for it.');
    }

    /**
     * Printing a bought label, or recording that it was printed, is open to every
     * shipper: a reprint costs nothing and a label jammed in one printer is often
     * finished at another station.
     */
    public function printLabel(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::User);
    }

    /**
     * Voiding an arbitrary package's label from the Packages pages is a manager's
     * correction.
     */
    public function voidLabel(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    /**
     * The shipper who bought a label may void it — the "void last label" command
     * barcode, for a box that fell off the scale — as may any manager.
     */
    public function voidOwnLabel(User $user, Package $package): bool
    {
        return $user->role->isAtLeast(Role::Manager)
            || $package->shipped_by_user_id === $user->id;
    }
}
