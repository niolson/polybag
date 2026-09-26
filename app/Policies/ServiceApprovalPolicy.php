<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ServiceApproval;
use App\Models\User;

/**
 * Approving a discovered service is authorizing unattended spending on a
 * client's account, which puts it with the other Admin acts rather than with
 * the Manager-level mapping beside it.
 *
 * The same line the connection form already draws: a connection's postage
 * setting is the other consent to spend in the app, and only admins edit it.
 * Naming a service is a manager's job; deciding money may be spent on it
 * without anyone watching is not.
 */
class ServiceApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, ServiceApproval $serviceApproval): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, ServiceApproval $serviceApproval): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, ServiceApproval $serviceApproval): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    /**
     * Withdrawing approvals wholesale, with no particular row in hand — what
     * saving the approvals page with fewer rules than before does.
     */
    public function deleteAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
