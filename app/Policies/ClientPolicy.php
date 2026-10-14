<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;

/**
 * Clients carry the return address, branding and label reference of each brand
 * a 3PL ships for, and are Admin-only. The resource's canAccess() says the same;
 * the policy makes each action say it too, so widening page access never opens
 * them.
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    /**
     * Filament's bulk delete asks this, not delete(), so it must be as strict.
     */
    public function deleteAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
