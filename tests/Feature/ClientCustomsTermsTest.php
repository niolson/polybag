<?php

use App\Enums\DutiesTerms;
use App\Enums\TaxRegistrationRegime;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\User;
use Database\Seeders\ClientTaxRegistrationSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

/**
 * `international-customs-terms/03`: a client's duties policy and seller tax
 * registrations, and the form that edits them.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $this->undoRepeaterFake = Repeater::fake();
});

afterEach(function (): void {
    ($this->undoRepeaterFake)();
});

it('starts a client with no duties policy and no registrations', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Unset Client'])
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::where('name', 'Unset Client')->firstOrFail();

    expect($client->duties_policy)->toBeNull()
        ->and($client->taxRegistrations)->toBeEmpty();
});

it('saves the EU row and country rows as one duties policy map', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Policy Client',
            'duties_policy_eu' => DutiesTerms::Ddp->value,
            'duties_policy_countries' => [
                ['country' => 'PL', 'terms' => DutiesTerms::Ddu->value],
                ['country' => 'GB', 'terms' => DutiesTerms::Ddp->value],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Client::where('name', 'Policy Client')->firstOrFail()->duties_policy)->toBe([
        'EU' => 'ddp',
        'PL' => 'ddu',
        'GB' => 'ddp',
    ]);
});

it('loads a duties policy into the EU row and country rows, and saves changes back', function (): void {
    $client = Client::factory()->withDutiesPolicy(['EU' => 'ddp', 'PL' => 'ddu'])->create();

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->assertFormSet([
            'duties_policy_eu' => DutiesTerms::Ddp,
            'duties_policy_countries' => [['country' => 'PL', 'terms' => 'ddu']],
        ])
        ->fillForm([
            'duties_policy_eu' => DutiesTerms::Ddu->value,
            'duties_policy_countries' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->refresh()->duties_policy)->toBe(['EU' => 'ddu']);
});

it('clears the policy back to unset when every row is emptied', function (): void {
    $client = Client::factory()->ddpToEu()->create();

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['duties_policy_eu' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->refresh()->duties_policy)->toBeNull();
});

it('refuses the same country twice in the duties policy', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Duplicate Country Client',
            'duties_policy_countries' => [
                ['country' => 'PL', 'terms' => DutiesTerms::Ddu->value],
                ['country' => 'PL', 'terms' => DutiesTerms::Ddp->value],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['duties_policy_countries.0.country']);
});

it('marks the EU row as required in the form without requiring it to save', function (): void {
    Livewire::test(CreateClient::class)
        ->assertFormFieldExists('duties_policy_eu', fn ($field): bool => $field->isMarkedAsRequired() && ! $field->isRequired())
        ->assertSee('DDP is recommended');
});

it('creates a tax registration for a regime the client does not have yet', function (TaxRegistrationRegime $regime, string $number): void {
    $client = Client::factory()->create();

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['taxRegistrations' => [['regime' => $regime->value, 'number' => $number]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $registration = $client->taxRegistrations()->sole();

    expect($registration->regime)->toBe($regime)
        ->and($registration->number)->toBe($regime->normalizeNumber($number));
})->with([
    'IOSS, normalized' => [TaxRegistrationRegime::Ioss, 'im 0000000001'],
    'UK VAT' => [TaxRegistrationRegime::UkVat, 'GB000000001'],
    'VOEC' => [TaxRegistrationRegime::Voec, '0000001'],
    'ARN' => [TaxRegistrationRegime::Arn, '000000000001'],
]);

it('rejects a registration number in the wrong format for its regime', function (TaxRegistrationRegime $regime, string $number): void {
    $client = Client::factory()->create();

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['taxRegistrations' => [['regime' => $regime->value, 'number' => $number]]])
        ->call('save')
        ->assertHasFormErrors(['taxRegistrations.0.number']);

    expect($client->taxRegistrations()->exists())->toBeFalse();
})->with([
    'IOSS' => [TaxRegistrationRegime::Ioss, 'GB0000000001'],
    'UK VAT' => [TaxRegistrationRegime::UkVat, 'GB0000000001'],
    'VOEC' => [TaxRegistrationRegime::Voec, '00000001'],
    'ARN' => [TaxRegistrationRegime::Arn, '00000000001'],
]);

it('refuses a second registration for the same regime in the form', function (): void {
    $client = Client::factory()->create();

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['taxRegistrations' => [
            ['regime' => TaxRegistrationRegime::Ioss->value, 'number' => 'IM0000000001'],
            ['regime' => TaxRegistrationRegime::Ioss->value, 'number' => 'IM0000000002'],
        ]])
        ->call('save')
        ->assertHasFormErrors(['taxRegistrations.0.regime']);

    expect($client->taxRegistrations()->exists())->toBeFalse();
});

it('saves two registrations whose regimes were swapped in one save', function (): void {
    // The real repeater keys, so each row stays bound to its saved record.
    ($this->undoRepeaterFake)();
    $this->undoRepeaterFake = fn (): null => null;

    $client = Client::factory()->create();
    $ioss = ClientTaxRegistration::factory()->for($client)->ioss()->create();
    $ukVat = ClientTaxRegistration::factory()->for($client)->ukVat()->create();

    // Each saved row takes the other's regime. Saved record by record, the
    // first update would collide with the second row's regime.
    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['taxRegistrations' => [
            "record-{$ioss->id}" => ['regime' => TaxRegistrationRegime::UkVat->value, 'number' => 'GB000000002'],
            "record-{$ukVat->id}" => ['regime' => TaxRegistrationRegime::Ioss->value, 'number' => 'IM0000000002'],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->taxRegistrations()->orderBy('regime')->pluck('number', 'regime')->all())
        ->toBe(['ioss' => 'IM0000000002', 'uk_vat' => 'GB000000002']);
});

it('removes a registration whose row is deleted on the client form', function (): void {
    $client = Client::factory()->withIossRegistration()->create();
    ClientTaxRegistration::factory()->for($client)->arn()->create();

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['taxRegistrations' => [['regime' => 'arn', 'number' => '000000000001']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->taxRegistrations()->pluck('regime')->map->value->all())->toBe(['arn']);
});

it('refuses a second registration for the same regime in the database', function (): void {
    $client = Client::factory()->withIossRegistration()->create();

    expect(fn () => ClientTaxRegistration::factory()->for($client)->ioss()->create(['number' => 'IM0000000002']))
        ->toThrow(QueryException::class);

    ClientTaxRegistration::factory()->for($client)->ukVat()->create();

    expect($client->taxRegistrations()->count())->toBe(2);
});

it('lets two clients hold registrations under the same regime', function (): void {
    $first = Client::factory()->withIossRegistration()->create();
    $second = Client::factory()->withIossRegistration()->create();

    expect($first->taxRegistrations()->sole()->regime)->toBe(TaxRegistrationRegime::Ioss)
        ->and($second->taxRegistrations()->sole()->regime)->toBe(TaxRegistrationRegime::Ioss);
});

it('builds a DDP-EU client and a client with an IOSS registration', function (): void {
    $ddp = Client::factory()->ddpToEu()->create();
    $ioss = Client::factory()->withIossRegistration()->create();

    expect($ddp->duties_policy)->toBe([Client::DUTIES_POLICY_EU => 'ddp'])
        ->and($ddp->taxRegistrations)->toBeEmpty()
        ->and($ioss->duties_policy)->toBeNull()
        ->and($ioss->taxRegistrations()->sole()->regime)->toBe(TaxRegistrationRegime::Ioss)
        ->and($ioss->taxRegistrations()->sole()->number)->toBe('IM0000000001');
});

it('seeds an opt-in EU demo client, idempotently', function (): void {
    $this->seed(ClientTaxRegistrationSeeder::class);
    $this->seed(ClientTaxRegistrationSeeder::class);

    $client = Client::where('name', ClientTaxRegistrationSeeder::CLIENT_NAME)->sole();

    expect($client->duties_policy)->toBe(['EU' => 'ddp'])
        ->and($client->taxRegistrations()->sole()->number)->toBe('IM0000000001')
        ->and(TaxRegistrationRegime::Ioss->isValidNumber($client->taxRegistrations()->sole()->number))->toBeTrue();
});
