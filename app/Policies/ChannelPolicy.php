<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, Channel $channel): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, Channel $channel): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
