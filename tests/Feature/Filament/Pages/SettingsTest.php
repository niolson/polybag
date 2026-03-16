<?php

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows sandbox mode indicator in topbar when sandbox mode is enabled', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $this->get('/')->assertSeeText('(sandbox mode)');
});

it('does not show sandbox mode indicator when sandbox mode is disabled', function (): void {
    app(SettingsService::class)->clearCache();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('(sandbox mode)</span>');
});

it('mounts sandbox_mode and suppress_printing from settings', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Livewire::test(Settings::class)
        ->assertSet('data.sandbox_mode', true)
        ->assertSet('data.suppress_printing', true);
});

it('saves sandbox_mode setting', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
            'from_address_first_name' => 'Test',
            'from_address_last_name' => 'User',
            'from_address_street' => '123 Main St',
            'from_address_city' => 'Seattle',
            'from_address_state_or_province' => 'WA',
            'from_address_postal_code' => '98072',
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('sandbox_mode'))->toBeTrue();
});

it('saves suppress_printing setting when sandbox_mode is on', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
            'suppress_printing' => true,
            'from_address_first_name' => 'Test',
            'from_address_last_name' => 'User',
            'from_address_street' => '123 Main St',
            'from_address_city' => 'Seattle',
            'from_address_state_or_province' => 'WA',
            'from_address_postal_code' => '98072',
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('suppress_printing'))->toBeTrue();
});

it('forces suppress_printing to false when sandbox_mode is turned off', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => false,
            'from_address_first_name' => 'Test',
            'from_address_last_name' => 'User',
            'from_address_street' => '123 Main St',
            'from_address_city' => 'Seattle',
            'from_address_state_or_province' => 'WA',
            'from_address_postal_code' => '98072',
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('suppress_printing'))->toBeFalse();
});

it('clears API auth caches when sandbox_mode changes', function (): void {
    Cache::put('usps_authenticator', 'test-token', 3600);
    Cache::put('usps_payment_authorization_token', 'test-payment-token', 3600);
    Cache::put('fedex_authenticator', 'test-fedex-token', 3600);

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
            'from_address_first_name' => 'Test',
            'from_address_last_name' => 'User',
            'from_address_street' => '123 Main St',
            'from_address_city' => 'Seattle',
            'from_address_state_or_province' => 'WA',
            'from_address_postal_code' => '98072',
        ])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeFalse()
        ->and(Cache::has('usps_payment_authorization_token'))->toBeFalse()
        ->and(Cache::has('fedex_authenticator'))->toBeFalse();
});

it('does not clear API auth caches when sandbox_mode does not change', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Cache::put('usps_authenticator', 'test-token', 3600);
    Cache::put('fedex_authenticator', 'test-fedex-token', 3600);

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
            'from_address_first_name' => 'Test',
            'from_address_last_name' => 'User',
            'from_address_street' => '123 Main St',
            'from_address_city' => 'Seattle',
            'from_address_state_or_province' => 'WA',
            'from_address_postal_code' => '98072',
        ])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeTrue()
        ->and(Cache::has('fedex_authenticator'))->toBeTrue();
});
