<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\PasswordPolicyService;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Concerns\InteractsWithEmailAuthentication;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property Role $role
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication
{
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, InteractsWithEmailAuthentication, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'password_changed_at',
        'role',
        'auto_ship_enabled',
        'location_id',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
            'role' => Role::class,
            'auto_ship_enabled' => 'boolean',
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

        static::saved(function (User $user): void {
            if ($user->wasChanged('password') && $user->getOriginal('password') !== null) {
                $user->passwordHistories()->create([
                    'password_hash' => $user->getOriginal('password'),
                ]);

                app(PasswordPolicyService::class)->pruneHistory($user);
            }
        });
    }

    /**
     * @return HasMany<PasswordHistory, $this>
     */
    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class)->latest();
    }

    /**
     * Whether this user has a local password (vs SSO-only).
     */
    public function hasLocalPassword(): bool
    {
        return $this->password !== null;
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email ?? $this->username ?? $this->name;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active;
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function resolveLocation(): ?Location
    {
        return $this->location ?? Location::getDefault();
    }
}
