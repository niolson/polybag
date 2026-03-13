<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    protected $signature = 'app:create-user';

    protected $description = 'Create a new user account';

    public function handle(): int
    {
        $name = text(
            label: 'Name',
            required: true,
        );

        $username = text(
            label: 'Username',
            required: true,
            validate: fn (string $value) => User::where('username', $value)->exists()
                ? 'Username already exists.'
                : null,
        );

        $password = password(
            label: 'Password',
            required: true,
            validate: fn (string $value) => strlen($value) < 8
                ? 'Password must be at least 8 characters.'
                : null,
        );

        $role = select(
            label: 'Role',
            options: collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => $role->name]),
            default: Role::User->value,
        );

        User::create([
            'name' => $name,
            'username' => $username,
            'password' => $password,
            'role' => $role,
        ]);

        $this->info("User '{$username}' created successfully.");

        return self::SUCCESS;
    }
}
