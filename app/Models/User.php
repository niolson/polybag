<?php

namespace App\Models;

use App\Enums\Role;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'password_changed_at',
        'role',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'role' => Role::class,
            'active' => 'boolean',
        ];
    }

    /**
     * Track password changes automatically.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('password') && $user->password !== null) {
                $user->password_changed_at = now();
            }
        });
    }

    /**
     * Whether this user has a local password (vs SSO-only).
     */
    public function hasLocalPassword(): bool
    {
        return $this->password !== null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active;
    }
}
