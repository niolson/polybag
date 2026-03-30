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
        ])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeTrue()
        ->and(Cache::has('fedex_authenticator'))->toBeTrue();
});

it('escapes oauth scopes in the settings page', function (): void {
    $payload = '<img src=x onerror=alert(\'pwnd\')>';

    Setting::create(['key' => 'shopify.oauth_access_token', 'value' => 'token', 'type' => 'string', 'encrypted' => true, 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.oauth_connected_at', 'value' => now()->toIso8601String(), 'type' => 'string', 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.oauth_scopes', 'value' => $payload, 'type' => 'string', 'group' => 'shopify']);
    app(SettingsService::class)->clearCache();

    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertSee(e($payload), false)
        ->assertDontSee($payload, false);
});

it('saves ssh server host key and writes a known_hosts file', function (): void {
    $knownHostsPath = storage_path('app/private/ssh/import_known_hosts');
    @unlink($knownHostsPath);

    $hostKey = 'bastion.example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBastionKeyExample';

    Livewire::test(Settings::class)
        ->fillForm([
            'import_ssh_enabled' => true,
            'import_ssh_host_key' => $hostKey,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('import.ssh_host_key'))->toBe($hostKey);
    expect(file_exists($knownHostsPath))->toBeTrue();
    expect(trim((string) file_get_contents($knownHostsPath)))->toBe($hostKey);

    @unlink($knownHostsPath);
});
