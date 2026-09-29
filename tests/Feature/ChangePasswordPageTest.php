<?php

use App\Enums\Role;
use App\Filament\Pages\Auth\ChangePassword;
use App\Models\User;
use App\Services\SettingsService;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows required MFA setup before changing an expired password without opening the panel', function (): void {
    app(SettingsService::class)->set('setup_complete', true, 'boolean');
    app(SettingsService::class)->set('require_mfa', true, 'boolean');

    // MFA routes are conditional on settings when Filament registers its routes.
    require base_path('vendor/filament/filament/routes/web.php');
    app('router')->getRoutes()->refreshNameLookups();

    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user)->withSession(['password_expired' => true]);
    $setupUrl = Filament::getSetUpRequiredMultiFactorAuthenticationUrl();

    $this->get('/')->assertRedirect(ChangePassword::getUrl());
    $this->get(ChangePassword::getUrl())->assertRedirect($setupUrl);
    $this->get($setupUrl)->assertOk();
    $this->get('/profile')->assertRedirect(ChangePassword::getUrl());

    $user->app_authentication_secret = AppAuthentication::make()->generateSecret();
    $user->save();

    $this->get('/')->assertRedirect(ChangePassword::getUrl());
    $this->get(ChangePassword::getUrl())->assertOk();
    expect(session('password_expired'))->toBeTrue();
});

it('shows the live password policy checklist on the change-password page', function (): void {
    $user = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user)->withSession(['password_expired' => true]);

    Livewire::test(ChangePassword::class)
        ->assertSee('Password must include:')
        ->assertSee('At least 12 characters')
        ->assertSee('At least one number');
});

it('enforces the password policy on the forced change-password page', function (): void {
    $user = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user)->withSession(['password_expired' => true]);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'CurrentPass123!456',
            'password' => 'short1!',
            'password_confirmation' => 'short1!',
        ])
        ->call('changePassword')
        ->assertHasFormErrors(['password']);
});

it('rejects reusing the current password on the forced change-password page', function (): void {
    $user = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user)->withSession(['password_expired' => true]);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'CurrentPass123!456',
            'password' => 'CurrentPass123!456',
            'password_confirmation' => 'CurrentPass123!456',
        ])
        ->call('changePassword')
        ->assertHasFormErrors(['password']);
});

it('accepts a strong, unused new password on the forced change-password page', function (): void {
    $user = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user)->withSession(['password_expired' => true]);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'CurrentPass123!456',
            'password' => 'BrandNewPass987!654',
            'password_confirmation' => 'BrandNewPass987!654',
        ])
        ->call('changePassword')
        ->assertHasNoFormErrors();

    expect(Hash::check('BrandNewPass987!654', $user->fresh()->password))->toBeTrue();
});

it('keeps the session authenticated after changing the password', function (): void {
    $user = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user)->withSession(['password_expired' => true]);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'CurrentPass123!456',
            'password' => 'BrandNewPass987!654',
            'password_confirmation' => 'BrandNewPass987!654',
        ])
        ->call('changePassword')
        ->assertHasNoFormErrors();

    // The session's stored password hash must track the new password, otherwise
    // AuthenticateSession logs the user out on the next request.
    $sessionHash = session('password_hash_'.Filament::getAuthGuard());
    expect($sessionHash)->not->toBeNull()
        ->and(Hash::check('BrandNewPass987!654', $sessionHash))->toBeTrue()
        ->and(session('password_expired'))->toBeNull();
});
