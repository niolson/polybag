<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit log is read by Admins and written only by the application. No one
 * creates, edits or deletes an entry through the UI; retention is a setting.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
