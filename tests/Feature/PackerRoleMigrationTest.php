<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('renames the user role to packer and makes it the default', function (): void {
    $migration = require database_path('migrations/2026_10_29_230523_rename_user_role_to_packer_on_users_table.php');
    $migration->down();

    $insert = fn (string $username, ?string $role): int => DB::table('users')->insertGetId(array_filter([
        'name' => $username,
        'username' => $username,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $floor = $insert('floor', 'user');
    $manager = $insert('lead', 'manager');

    $migration->up();

    $defaulted = $insert('new', null);

    expect(User::find($floor)->role)->toBe(Role::Packer)
        ->and(User::find($manager)->role)->toBe(Role::Manager)
        ->and(User::find($defaulted)->role)->toBe(Role::Packer);
});
