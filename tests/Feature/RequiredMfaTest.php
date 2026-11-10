<?php

use App\Enums\Role;
use App\Models\User;
use App\Services\SettingsService;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The panel's routes are registered when the app boots, before each test sets
// require_mfa — as in production, where routes are cached at container start.
// So these tests prove the setting is read per request, not at registration.

beforeEach(function (): void {
    app(SettingsService::class)->set('setup_complete', true, 'boolean');
    $this->user = User::factory()->create(['role' => Role::Admin]);
});

it('sends a user with no factor to MFA set-up once require_mfa is turned on', function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');

    $this->actingAs($this->user)
        ->get('/')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());

    $this->get(Filament::getSetUpRequiredMultiFactorAuthenticationUrl())->assertOk();
});

it('lets a user with no factor in while require_mfa is off', function (): void {
    app(SettingsService::class)->set('require_mfa', false, 'boolean');

    $this->actingAs($this->user)->get('/')->assertOk();
});

it('stops requiring set-up as soon as require_mfa is turned off', function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
    $this->actingAs($this->user)->get('/')->assertRedirect();

    app(SettingsService::class)->set('require_mfa', false, 'boolean');

    $this->get('/')->assertOk();
});

it('lets an enrolled user in while require_mfa is on', function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
    $this->user->app_authentication_secret = AppAuthentication::make()->generateSecret();
    $this->user->save();

    $this->actingAs($this->user)->get('/')->assertOk();
});
