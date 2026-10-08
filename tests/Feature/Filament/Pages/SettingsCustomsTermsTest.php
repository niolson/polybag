<?php

use App\Enums\DutiesTerms;
use App\Enums\TaxRegistrationRegime;
use App\Filament\Pages\Settings;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\User;
use App\Services\SettingsService;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * `international-customs-terms/03`: a single-client install has no Clients
 * page, so the default client's customs terms are edited on Settings, with the
 * client form's fields and checks.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $this->undoRepeaterFake = Repeater::fake();
});

afterEach(function (): void {
    ($this->undoRepeaterFake)();
});

it('loads the default client\'s duties policy and registrations in single-client mode', function (): void {
    $client = Client::factory()->withDutiesPolicy(['EU' => 'ddp', 'PL' => 'ddu'])->create(['is_default' => true]);
    ClientTaxRegistration::factory()->for($client)->ioss()->create();

    Livewire::test(Settings::class)
        ->assertSet('data.client.duties_policy_eu', 'ddp')
        ->assertSet('data.client.duties_policy_countries', [['country' => 'PL', 'terms' => 'ddu']])
        ->assertSet('data.client.tax_registrations', [['regime' => 'ioss', 'number' => 'IM0000000001']])
        ->assertSee('DDP is recommended');
});

it('saves the default client\'s duties policy and registrations in single-client mode', function (): void {
    $client = Client::factory()->create(['is_default' => true]);

    Livewire::test(Settings::class)
        ->fillForm([
            'client.duties_policy_eu' => DutiesTerms::Ddp->value,
            'client.duties_policy_countries' => [['country' => 'GB', 'terms' => DutiesTerms::Ddu->value]],
            'client.tax_registrations' => [
                ['regime' => TaxRegistrationRegime::Ioss->value, 'number' => 'im 0000000001'],
                ['regime' => TaxRegistrationRegime::Voec->value, 'number' => '0000001'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($client->refresh()->duties_policy)->toBe(['EU' => 'ddp', 'GB' => 'ddu'])
        ->and($client->taxRegistrations()->orderBy('regime')->pluck('number', 'regime')->all())
        ->toBe(['ioss' => 'IM0000000001', 'voec' => '0000001']);
});

it('removes a registration and clears the policy when their rows are emptied on Settings', function (): void {
    $client = Client::factory()->ddpToEu()->withIossRegistration()->create(['is_default' => true]);

    Livewire::test(Settings::class)
        ->fillForm([
            'client.duties_policy_eu' => null,
            'client.tax_registrations' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->refresh()->duties_policy)->toBeNull()
        ->and($client->taxRegistrations()->exists())->toBeFalse();
});

it('checks registrations on Settings as the client form does', function (array $rows, string $errorField): void {
    $client = Client::factory()->create(['is_default' => true]);

    Livewire::test(Settings::class)
        ->fillForm(['client.tax_registrations' => $rows])
        ->call('save')
        ->assertHasFormErrors([$errorField]);

    expect($client->taxRegistrations()->exists())->toBeFalse();
})->with([
    'a malformed number' => [[['regime' => 'ioss', 'number' => 'IM000000001']], 'client.tax_registrations.0.number'],
    'the same regime twice' => [[
        ['regime' => 'ioss', 'number' => 'IM0000000001'],
        ['regime' => 'ioss', 'number' => 'IM0000000002'],
    ], 'client.tax_registrations.0.regime'],
]);

it('refuses the same country twice in the duties policy on Settings', function (): void {
    Client::factory()->create(['is_default' => true]);

    Livewire::test(Settings::class)
        ->fillForm(['client.duties_policy_countries' => [
            ['country' => 'PL', 'terms' => 'ddu'],
            ['country' => 'PL', 'terms' => 'ddp'],
        ]])
        ->call('save')
        ->assertHasFormErrors(['client.duties_policy_countries.0.country']);
});

it('leaves the customs fields off Settings in multi-client mode', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $client = Client::factory()->ddpToEu()->create(['is_default' => true]);

    Livewire::test(Settings::class)
        ->assertFormFieldDoesNotExist('client.duties_policy_eu')
        ->call('save');

    expect($client->refresh()->duties_policy)->toBe(['EU' => 'ddp']);
});

it('loads, saves and checks the default client\'s exporter EIN on Settings', function (): void {
    $client = Client::factory()->withExporterEin()->create(['is_default' => true]);

    Livewire::test(Settings::class)
        ->assertSet('data.client.exporter_ein', '12-3456789')
        ->fillForm(['client.exporter_ein' => '98-7654321'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->refresh()->exporter_ein)->toBe('987654321');

    Livewire::test(Settings::class)
        ->fillForm(['client.exporter_ein' => '98-765'])
        ->call('save')
        ->assertHasFormErrors(['client.exporter_ein']);

    expect($client->refresh()->exporter_ein)->toBe('987654321');
});
