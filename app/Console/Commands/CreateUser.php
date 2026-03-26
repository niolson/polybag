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

        $email = text(
            label: 'Email',
            validate: fn (string $value) => match (true) {
                ! empty($value) && ! filter_var($value, FILTER_VALIDATE_EMAIL) => 'Invalid email address.',
                ! empty($value) && User::where('email', $value)->exists() => 'Email already exists.',
                default => null,
            },
        );

        $username = text(
            label: 'Username (optional if email provided)',
            validate: fn (string $value) => ! empty($value) && User::where('username', $value)->exists()
                ? 'Username already exists.'
                : null,
        );

        if (empty($email) && empty($username)) {
            $this->error('Either email or username is required.');

            return self::FAILURE;
        }

        $pw = password(
            label: 'Password (leave empty for SSO-only)',
        );

        $role = select(
            label: 'Role',
            options: collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => $role->name]),
            default: Role::User->value,
        );

        User::create(array_filter([
            'name' => $name,
            'email' => $email ?: null,
            'username' => $username ?: null,
            'password' => $pw ?: null,
            'role' => $role,
        ]));

        $identifier = $email ?: $username;
        $this->info("User '{$identifier}' created successfully.");

        return self::SUCCESS;
    }
}
