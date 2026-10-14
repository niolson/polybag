<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\DataSource;
use App\Models\User;

/**
 * Connections hold the credentials of every sales channel and import database,
 * and sell postage on them. Like Carrier Accounts and App Settings, they are
 * Admin-only. The resource's canAccess() says the same; the policy makes each
 * action say it too, so widening page access never opens them.
 */
class DataSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, DataSource $dataSource): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, DataSource $dataSource): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, DataSource $dataSource): bool
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
