<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ShippingMethodPostageSource;
use App\Models\User;

/**
 * Who may say which sources sell for a shipping method —
 * `carrier-catalog-reset/09`.
 *
 * An Admin decides; a Manager may look. A Manager still edits the method's
 * services and rules, which pick within what these rows allow. On the model
 * rather than a per-field lock on the Manager-editable method form, so a field
 * added later cannot slip past it.
 */
class ShippingMethodPostageSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function view(User $user, ShippingMethodPostageSource $row): bool
    {
        return $user->role->isAtLeast(Role::Manager);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, ShippingMethodPostageSource $row): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, ShippingMethodPostageSource $row): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function deleteAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
