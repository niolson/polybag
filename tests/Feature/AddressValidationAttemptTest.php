<?php

use App\Console\Commands\ValidateShipmentsCommand;
use App\DataTransferObjects\Shipping\AddressData;
use App\Enums\AddressValidationOutcome;
use App\Enums\Deliverability;
use App\Enums\Role;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Http\Integrations\Google\Requests\ValidateAddress as GoogleValidateAddress;
use App\Http\Integrations\USPS\Requests\Address;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\AddressValidationService;
use App\Services\Validation\GoogleAddressValidator;
use App\Services\Validation\UspsAddressValidator;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    createUspsAccount();
    config(['services.google_address_validation.api_key' => 'test-google-key']);
});

function uspsToken(): MockResponse
{
    return MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
}

/**
 * A USPS Addresses v3 error body, shaped like the spec's 404 examples.
 *
 * @param  list<array<string, string>>  $errors
 */
function uspsNotFound(string $message, array $errors = []): MockResponse
{
    return MockResponse::make([
        'apiVersion' => 'v3',
        'error' => ['code' => '404', 'message' => $message, 'errors' => $errors],
    ], 404);
}

/**
 * @return array<string, mixed>
 */
function googleSettledResponse(): array
{
    return [
        'result' => [
            'verdict' => ['addressComplete' => true],
            'address' => [
                'postalAddress' => [
                    'addressLines' => ['1600 Amphitheatre Pkwy'],
                    'locality' => 'Mountain View',
                    'administrativeArea' => 'CA',
                    'postalCode' => '94043-1351',
                ],
            ],
            'uspsData' => ['dpvConfirmation' => 'Y', 'carrierRoute' => 'C909'],
            'metadata' => ['residential' => false],
        ],
    ];
}

function uspsAndGoogle(): AddressValidationService
{
    return new AddressValidationService([new UspsAddressValidator, new GoogleAddressValidator]);
}

// What counts as an answer

it('records an attempt and USPS\'s reason when USPS cannot match the address', function (string $message): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound($message),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $outcome = app(AddressValidationService::class)->validate($shipment);

    $shipment->refresh();
    expect($outcome)->toBe(AddressValidationOutcome::Inconclusive)
        ->and($shipment->validation_attempted_at)->not->toBeNull()
        ->and($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe($message);
})->with([
    'no match' => 'There is no match for the address requested.',
    'invalid city' => 'The city in the request is missing or invalid.',
    'invalid state' => 'The state code in the request is missing or invalid.',
    'insufficient data' => 'The address information in the request is insufficient to match.',
    'multiple matches' => 'More than one address was found matching the requested address.',
]);

it('prefers a USPS error detail over the top-level message', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.', [
            ['status' => '404', 'code' => '010004', 'title' => 'Not found', 'detail' => 'The street number is outside the valid range for this street.'],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    expect($shipment->fresh()->validation_message)->toBe('The street number is outside the valid range for this street.');
});

it('records an attempt for a request USPS rejects, so it is not retried', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => MockResponse::make(['error' => ['code' => '400', 'message' => 'ZIPCode must be 5 digits.']], 400),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    expect(app(AddressValidationService::class)->validate($shipment))->toBe(AddressValidationOutcome::Inconclusive)
        ->and($shipment->fresh()->validation_attempted_at)->not->toBeNull()
        ->and($shipment->fresh()->validation_message)->toBe('ZIPCode must be 5 digits.');
});

it('records no attempt and leaves the Shipment alone when USPS rate-limits the request', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => MockResponse::make(['error' => ['code' => '429', 'message' => 'Too Many Requests']], 429),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $outcome = app(AddressValidationService::class)->validate($shipment);

    $shipment->refresh();
    expect($outcome)->toBe(AddressValidationOutcome::Unavailable)
        ->and($shipment->validation_attempted_at)->toBeNull()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->validation_message)->toBeNull();
});

it('records no attempt when the USPS token request is rejected', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['error' => 'invalid_client', 'error_description' => 'Client credentials are invalid.'], 400),
        Address::class => uspsNotFound('There is no match for the address requested.'),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $outcome = app(AddressValidationService::class)->validate($shipment);

    $shipment->refresh();
    expect($outcome)->toBe(AddressValidationOutcome::Unavailable)
        ->and($shipment->validation_attempted_at)->toBeNull()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->validation_message)->toBeNull();

    Saloon::assertNotSent(Address::class);
});

it('records no attempt when Google answers with no result', function (): void {
    Saloon::fake([
        GoogleValidateAddress::class => MockResponse::make(['responseId' => 'abc']),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'DE']);

    $outcome = uspsAndGoogle()->validate($shipment);

    $shipment->refresh();
    expect($outcome)->toBe(AddressValidationOutcome::Unavailable)
        ->and($shipment->validation_attempted_at)->toBeNull()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked);
});

it('records no attempt when every validator is unavailable', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => MockResponse::make('', 503),
        GoogleValidateAddress::class => MockResponse::make('', 503),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    expect(uspsAndGoogle()->validate($shipment))->toBe(AddressValidationOutcome::Unavailable)
        ->and($shipment->fresh()->validation_attempted_at)->toBeNull();
});

it('records an attempt when a later validator settles the address', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.'),
        GoogleValidateAddress::class => MockResponse::make(googleSettledResponse()),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $outcome = uspsAndGoogle()->validate($shipment);

    $shipment->refresh();
    expect($outcome)->toBe(AddressValidationOutcome::Settled)
        ->and($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validation_attempted_at)->not->toBeNull();
});

// Regression: the chain stopped on `checked`, which a previously settled
// Shipment already had, so re-validating it never reached Google and left
// an inconclusive USPS answer reading as checked.
it('re-validates a settled Shipment through the whole chain', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.'),
        GoogleValidateAddress::class => MockResponse::make(['result' => [
            'verdict' => ['addressComplete' => false],
            'address' => ['postalAddress' => []],
        ]]),
    ]);

    $shipment = Shipment::factory()->validated()->create(['country' => 'US']);

    uspsAndGoogle()->validate($shipment);

    Saloon::assertSent(GoogleValidateAddress::class);
    expect($shipment->fresh()->checked)->toBeTrue();
});

it('marks a re-validated Shipment unchecked when every validator is inconclusive', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.'),
    ]);

    $shipment = Shipment::factory()->validated()->create(['country' => 'US']);

    expect(app(AddressValidationService::class)->validate($shipment))->toBe(AddressValidationOutcome::Inconclusive);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_attempted_at)->not->toBeNull();
});

// The scheduled run

it('does not send an exhausted Shipment to the validators again on the next run', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.'),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('Attempted, still unsettled');

    expect($shipment->fresh()->validation_attempted_at)->not->toBeNull();

    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('No pending shipments');

    Saloon::mockClient()->assertSentCount(1, Address::class);
});

it('retries a Shipment on the next run when the validators were unavailable', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => MockResponse::make('', 503),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->artisan('shipments:validate')
        ->assertFailed()
        ->expectsOutputToContain('validators unavailable');

    expect($shipment->fresh()->validation_attempted_at)->toBeNull();

    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.'),
    ]);

    $this->artisan('shipments:validate')->assertSuccessful();

    expect($shipment->fresh()->validation_attempted_at)->not->toBeNull()
        ->and($shipment->fresh()->validation_message)->toBe('There is no match for the address requested.');
});

// Manual validation

it('re-runs validation from View Shipment on an exhausted Shipment', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('The city in the request is missing or invalid.'),
    ]);

    $shipment = Shipment::factory()->validationExhausted()->create(['country' => 'US']);
    $previousAttempt = $shipment->validation_attempted_at;

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->callAction('validateAddress')
        ->assertNotified();

    Saloon::assertSent(Address::class);

    $shipment->refresh();
    expect($shipment->validation_message)->toBe('The city in the request is missing or invalid.')
        ->and($shipment->validation_attempted_at->isAfter($previousAttempt))->toBeTrue();
});

// Clearing the attempt

it('clears the attempt when the address or shipping method changes', function (array $change): void {
    $shipment = Shipment::factory()->validationExhausted()->create(['country' => 'US']);

    $shipment->update(is_callable($change[0] ?? null) ? $change[0]() : $change);

    expect($shipment->fresh()->validation_attempted_at)->toBeNull();
})->with([
    'street' => [['address1' => '1 Corrected Way']],
    'city' => [['city' => 'Elsewhere']],
    'postal code' => [['postal_code' => '99999']],
    'shipping method' => [[fn (): array => ['shipping_method_id' => ShippingMethod::factory()->create()->id]]],
]);

it('keeps the attempt when something other than the address or method changes', function (): void {
    $shipment = Shipment::factory()->validationExhausted()->create(['country' => 'US']);

    $shipment->update(['email' => 'someone@example.com']);

    expect($shipment->fresh()->validation_attempted_at)->not->toBeNull();
});

// A changed address discards the old result

it('discards the validation result when the address changes', function (): void {
    $shipment = Shipment::factory()->validated()->create([
        'country' => 'US',
        'address1' => '1600 Pennsylvania Ave NW',
        'validated_address1' => '1600 PENNSYLVANIA AVE NW',
        'validated_city' => 'WASHINGTON',
        'validated_postal_code' => '20500-0005',
        'validated_residential' => false,
        'validation_attempted_at' => now()->subHour(),
        'validation_attempts' => 2,
    ]);

    $shipment->update(['address1' => '1 Corrected Way', 'city' => 'Springfield']);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->validation_message)->toBeNull()
        ->and($shipment->validation_attempted_at)->toBeNull()
        ->and($shipment->validated_address1)->toBeNull()
        ->and($shipment->validated_postal_code)->toBeNull()
        ->and($shipment->validated_residential)->toBeNull()
        ->and($shipment->validation_attempts)->toBe(2);

    // Rating and labels prefer the validated address, so they must now see the new one.
    $destination = AddressData::fromShipment($shipment);
    expect($destination->streetAddress)->toBe('1 Corrected Way')
        ->and($destination->city)->toBe('Springfield');
});

it('keeps the validation result when only the case or spacing of the address changes', function (): void {
    $shipment = Shipment::factory()->validated()->create([
        'country' => 'US',
        'address1' => '12 Main St',
        'validated_address1' => '12 MAIN ST',
    ]);

    $shipment->update(['address1' => '  12   MAIN st ']);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->validated_address1)->toBe('12 MAIN ST');
});

it('keeps the validation result but clears the attempt when only the shipping method changes', function (): void {
    $shipment = Shipment::factory()->validated()->create([
        'country' => 'US',
        'validated_address1' => '12 MAIN ST',
        'validation_attempted_at' => now()->subHour(),
    ]);

    $shipment->update(['shipping_method_id' => ShippingMethod::factory()->create()->id]);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validated_address1)->toBe('12 MAIN ST')
        ->and($shipment->validation_attempted_at)->toBeNull();
});

it('discards the validated address when an address is corrected on Edit Shipment', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    $shipment = Shipment::factory()->validated()->create([
        'country' => 'US',
        'validated_address1' => '12 MAIN ST',
    ]);

    Livewire::test(EditShipment::class, ['record' => $shipment->id])
        ->fillForm(['address1' => '1 Corrected Way'])
        ->call('save')
        ->assertHasNoFormErrors();

    $shipment->refresh();
    expect($shipment->address1)->toBe('1 Corrected Way')
        ->and($shipment->checked)->toBeFalse()
        ->and($shipment->validated_address1)->toBeNull();
});

it('keeps the validation result when Edit Shipment changes only the phone', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    $shipment = Shipment::factory()->validated()->create([
        'country' => 'US',
        'validated_address1' => '12 MAIN ST',
    ]);

    Livewire::test(EditShipment::class, ['record' => $shipment->id])
        ->fillForm(['phone' => '(512) 555-0100'])
        ->call('save')
        ->assertHasNoFormErrors();

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->validated_address1)->toBe('12 MAIN ST');
});

// The scheduled attempt limit

it('counts scheduled attempts that a validator answered', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.'),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->artisan('shipments:validate')->assertSuccessful();

    expect($shipment->fresh()->validation_attempts)->toBe(1);
});

it('does not count a scheduled run in which the validators were unavailable', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => MockResponse::make('', 503),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->artisan('shipments:validate')->assertFailed();

    expect($shipment->fresh()->validation_attempts)->toBe(0);
});

it('logs a Shipment once it uses its last scheduled attempt', function (): void {
    $shipment = Shipment::factory()->create([
        'country' => 'US',
        'validation_attempts' => ValidateShipmentsCommand::MAX_SCHEDULED_ATTEMPTS - 1,
    ]);

    Log::spy();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'scheduled address validation limit') && $context['shipment_id'] === $shipment->id)
        ->once();

    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.'),
    ]);

    $this->artisan('shipments:validate')->assertSuccessful();

    expect($shipment->fresh()->validation_attempts)->toBe(ValidateShipmentsCommand::MAX_SCHEDULED_ATTEMPTS);
});

it('leaves a Shipment at the limit to manual validation, even after its address changes', function (): void {
    Saloon::fake([
        '*oauth*' => uspsToken(),
        Address::class => uspsNotFound('There is no match for the address requested.'),
    ]);

    $shipment = Shipment::factory()->validationExhausted()->create([
        'country' => 'US',
        'validation_attempts' => ValidateShipmentsCommand::MAX_SCHEDULED_ATTEMPTS,
    ]);
    $shipment->update(['address1' => '1 Corrected Way']);

    $this->artisan('shipments:validate')
        ->assertSuccessful()
        ->expectsOutputToContain('1 shipment(s) reached the limit')
        ->expectsOutputToContain('No pending shipments');

    Saloon::assertNotSent(Address::class);

    // Validating by hand still runs, and doesn't count against the limit.
    $shipment->refresh()->validateAddress();

    Saloon::assertSent(Address::class);
    expect($shipment->fresh()->validation_attempted_at)->not->toBeNull()
        ->and($shipment->fresh()->validation_attempts)->toBe(ValidateShipmentsCommand::MAX_SCHEDULED_ATTEMPTS);
});
