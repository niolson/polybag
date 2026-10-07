<?php

use App\Enums\AddressValidationOutcome;
use App\Enums\Deliverability;
use App\Events\AddressValidationFailed;
use App\Http\Integrations\Google\Requests\ValidateAddress as GoogleValidateAddress;
use App\Http\Integrations\USPS\Requests\Address;
use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\AddressValidationService;
use App\Services\SettingsService;
use App\Services\Validation\FakeAddressValidator;
use App\Services\Validation\GoogleAddressValidator;
use App\Services\Validation\UspsAddressValidator;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->service = app(AddressValidationService::class);
    createUspsAccount();
});

// Scenario 1: Not checked (non-US address is skipped)
it('skips non-US addresses', function (): void {
    $shipment = Shipment::factory()->create(['country' => 'CA']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->validation_message)->toBeNull()
        ->and($shipment->checked)->toBeFalse();
});

// Scenario 2: API error (address not found)
it('leaves the Shipment unverified when USPS errors and nothing else can answer', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'error' => [
                'message' => 'Address Not Found.',
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Unverified)
        ->and($shipment->validation_message)->toBe('Address Not Found.')
        ->and($shipment->checked)->toBeFalse();
});

// Scenario 3: Multiple addresses (correction code 22)
it('leaves the Shipment unverified when USPS finds multiple addresses', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'corrections' => [
                ['code' => '22', 'text' => 'Multiple addresses were found for the information you entered.'],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Unverified)
        ->and($shipment->validation_message)->toBe('Multiple addresses were found for the information you entered.')
        ->and($shipment->checked)->toBeFalse();
});

// Scenario 4: Default address (correction code 32)
it('sets deliverability to Partial for default address correction', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'corrections' => [
                ['code' => '32', 'text' => 'More information is needed to deliver to this address.'],
            ],
            'address' => [
                'streetAddress' => '123 MAIN ST',
                'secondaryAddress' => '',
                'city' => 'ANYTOWN',
                'state' => 'NY',
                'ZIPCode' => '10001',
            ],
            'additionalInfo' => [
                'DPVConfirmation' => 'D',
                'business' => 'N',
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    // Code 32 overrides DPV-derived deliverability
    expect($shipment->deliverability)->toBe(Deliverability::Partial)
        ->and($shipment->validation_message)->toBe('More information is needed to deliver to this address.')
        ->and($shipment->validated_address1)->toBe('123 MAIN ST')
        ->and($shipment->validated_city)->toBe('ANYTOWN')
        ->and($shipment->validated_state_or_province)->toBe('NY')
        ->and($shipment->validated_postal_code)->toBe('10001')
        ->and($shipment->checked)->toBeTrue();
});

// Scenario 5: Exact match, DPV N
it('sets deliverability to No for exact match with DPV N', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'matches' => [
                ['code' => '31'],
            ],
            'address' => [
                'streetAddress' => '456 OAK AVE',
                'secondaryAddress' => '',
                'city' => 'PORTLAND',
                'state' => 'OR',
                'ZIPCode' => '97201',
            ],
            'additionalInfo' => [
                'DPVConfirmation' => 'N',
                'business' => 'Y',
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe('Address found but not confirmed as deliverable')
        ->and($shipment->validated_address1)->toBe('456 OAK AVE')
        ->and($shipment->validated_residential)->toBeFalse()
        ->and($shipment->checked)->toBeTrue();
});

// Scenario 6: Exact match, DPV D (primary confirmed, secondary missing)
it('sets deliverability to Partial for exact match with DPV D', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'matches' => [
                ['code' => '31'],
            ],
            'address' => [
                'streetAddress' => '789 PINE RD',
                'secondaryAddress' => '',
                'city' => 'DENVER',
                'state' => 'CO',
                'ZIPCode' => '80201',
            ],
            'additionalInfo' => [
                'DPVConfirmation' => 'D',
                'business' => 'N',
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Partial)
        ->and($shipment->validation_message)->toBe('Primary address confirmed, secondary number missing')
        ->and($shipment->validated_address1)->toBe('789 PINE RD')
        ->and($shipment->checked)->toBeTrue();
});

// Scenario 7: Exact match, DPV S (primary confirmed, secondary not confirmed)
it('sets deliverability to Partial for exact match with DPV S', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'matches' => [
                ['code' => '31'],
            ],
            'address' => [
                'streetAddress' => '321 ELM BLVD',
                'secondaryAddress' => 'APT 4B',
                'city' => 'AUSTIN',
                'state' => 'TX',
                'ZIPCode' => '73301',
            ],
            'additionalInfo' => [
                'DPVConfirmation' => 'S',
                'business' => 'N',
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Partial)
        ->and($shipment->validation_message)->toBe('Primary address confirmed, secondary number not confirmed')
        ->and($shipment->validated_address1)->toBe('321 ELM BLVD')
        ->and($shipment->validated_address2)->toBe('APT 4B')
        ->and($shipment->checked)->toBeTrue();
});

// Scenario 8: Exact match, DPV Y (fully confirmed)
it('sets deliverability to Yes for exact match with DPV Y', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'matches' => [
                ['code' => '31'],
            ],
            'address' => [
                'streetAddress' => '1600 PENNSYLVANIA AVE NW',
                'secondaryAddress' => '',
                'city' => 'WASHINGTON',
                'state' => 'DC',
                'ZIPCode' => '20500',
            ],
            'additionalInfo' => [
                'DPVConfirmation' => 'Y',
                'business' => 'Y',
                'carrierRoute' => 'C018',
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validation_message)->toBe('Address confirmed deliverable')
        ->and($shipment->validated_address1)->toBe('1600 PENNSYLVANIA AVE NW')
        ->and($shipment->validated_city)->toBe('WASHINGTON')
        ->and($shipment->validated_state_or_province)->toBe('DC')
        ->and($shipment->validated_postal_code)->toBe('20500')
        ->and($shipment->validated_residential)->toBeFalse()
        ->and($shipment->validated_carrier_route)->toBe('C018')
        ->and($shipment->checked)->toBeTrue();
});

// Server error (5xx) — shipment should remain unchecked
it('leaves shipment unchecked on server error', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make('', 503),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->validation_message)->toBeNull()
        ->and($shipment->checked)->toBeFalse();
});

// Regression: the validator authenticates with the resolved CarrierAccount's client
// credentials (the demo.polybag.app 500 happened when it used empty global settings).
it('authenticates with the resolved carrier account credentials', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'matches' => [['code' => '31']],
            'address' => [
                'streetAddress' => '1600 PENNSYLVANIA AVE NW',
                'city' => 'WASHINGTON',
                'state' => 'DC',
                'ZIPCode' => '20500',
            ],
            'additionalInfo' => ['DPVConfirmation' => 'Y', 'business' => 'N'],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->checked)->toBeTrue();
});

// Regression: with no USPS carrier account configured, validation must skip gracefully
// rather than bubble an OAuthConfigValidationException up into a 500 error page.
it('skips gracefully when no USPS carrier account is configured', function (): void {
    CarrierAccount::query()->delete();

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->checked)->toBeFalse();
});

// Regression: OAuth-connected USPS accounts can't be used while sandbox mode is
// enabled. The connector throws a RuntimeException for this — it's a "couldn't
// attempt" configuration error (not a real deliverability verdict), so it must
// leave the shipment unchecked (allowing fallback to another validator) rather
// than bubbling up into a 500 error page or recording a false "not deliverable".
it('leaves shipment unchecked when sandbox mode is enabled for an OAuth-connected account', function (): void {
    CarrierAccount::query()->delete();
    createUspsAccount(['auth_mode' => 'authorization_code']);
    app(SettingsService::class)->set('sandbox_mode', true);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->checked)->toBeFalse();
});

// Unexpected response format
it('leaves the Shipment unverified on an unexpected USPS response', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'someUnexpectedField' => 'value',
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Unverified)
        ->and($shipment->validation_message)->toBe('Unexpected USPS response format')
        ->and($shipment->checked)->toBeFalse();
});

// --- Google fallback dispatch -------------------------------------------------

function googleValidResponse(): array
{
    return [
        'result' => [
            'verdict' => [
                'addressComplete' => true,
                'hasUnconfirmedComponents' => false,
            ],
            'address' => [
                'postalAddress' => [
                    'addressLines' => ['1600 Amphitheatre Pkwy'],
                    'locality' => 'Mountain View',
                    'administrativeArea' => 'CA',
                    'postalCode' => '94043',
                ],
                'addressComponents' => [
                    ['componentType' => 'street_number', 'confirmationLevel' => 'CONFIRMED'],
                ],
            ],
            'metadata' => [
                'business' => true,
                'poBox' => false,
                'residential' => false,
            ],
        ],
    ];
}

beforeEach(function (): void {
    config(['services.google_address_validation.api_key' => 'test-google-key']);
});

it('falls through to Google when no USPS carrier account is configured', function (): void {
    CarrierAccount::query()->delete();

    Saloon::fake([
        GoogleValidateAddress::class => MockResponse::make(googleValidResponse()),
    ]);

    $service = new AddressValidationService([new UspsAddressValidator, new GoogleAddressValidator]);
    $shipment = Shipment::factory()->create(['country' => 'US']);

    $service->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validated_city)->toBe('Mountain View')
        ->and($shipment->validated_residential)->toBeFalse();
});

it('falls through to Google when USPS denies access (missing license)', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make(['error' => ['message' => 'not authorized']], 403),
        GoogleValidateAddress::class => MockResponse::make(googleValidResponse()),
    ]);

    $service = new AddressValidationService([new UspsAddressValidator, new GoogleAddressValidator]);
    $shipment = Shipment::factory()->create(['country' => 'US']);

    $service->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified);
});

// Regression: USPS couldn't match the input to a specific address at all (as
// opposed to matching it and confirming it undeliverable) — this must not
// block Google from getting a real attempt at the same address.
it('falls through to Google when USPS cannot match the input address', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'corrections' => [
                ['code' => '22', 'text' => 'Multiple addresses were found for the information you entered.'],
            ],
        ]),
        GoogleValidateAddress::class => MockResponse::make(googleValidResponse()),
    ]);

    $service = new AddressValidationService([new UspsAddressValidator, new GoogleAddressValidator]);
    $shipment = Shipment::factory()->create(['country' => 'US']);

    $service->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validated_city)->toBe('Mountain View');
});

// Regression: USPS's inconclusive result used to fire AddressValidationFailed
// immediately, before Google got its turn — leaving a contradictory failure
// audit log on a shipment that Google went on to confirm as deliverable.
it('does not dispatch AddressValidationFailed when USPS is inconclusive but Google confirms the address', function (): void {
    Event::fake([AddressValidationFailed::class]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'corrections' => [
                ['code' => '22', 'text' => 'Multiple addresses were found for the information you entered.'],
            ],
        ]),
        GoogleValidateAddress::class => MockResponse::make(googleValidResponse()),
    ]);

    $service = new AddressValidationService([new UspsAddressValidator, new GoogleAddressValidator]);
    $shipment = Shipment::factory()->create(['country' => 'US']);

    $service->validate($shipment);

    Event::assertNotDispatched(AddressValidationFailed::class);
});

it('routes non-US addresses straight to Google, skipping USPS', function (): void {
    Saloon::fake([
        GoogleValidateAddress::class => MockResponse::make(googleValidResponse()),
    ]);

    $service = new AddressValidationService([new UspsAddressValidator, new GoogleAddressValidator]);
    $shipment = Shipment::factory()->create(['country' => 'CA']);

    $service->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified);

    Saloon::assertNotSent(Address::class);
});

// Regression: Google is opt-in via Settings, billed to PolyBag across every
// tenant — it must never run unless address_validation_google_enabled is set.
it('does not include Google in the default container-resolved service', function (): void {
    CarrierAccount::query()->delete();

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked);
});

it('includes Google once the setting is enabled, resolved through the container', function (): void {
    CarrierAccount::query()->delete();
    app(SettingsService::class)->set('address_validation_google_enabled', true);
    app()->forgetInstance(AddressValidationService::class);

    Saloon::fake([
        GoogleValidateAddress::class => MockResponse::make(googleValidResponse()),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified);
});

// Regression: sandbox mode is no longer "free" (USPS's TEM environment now
// requires the same paid license). Sandbox/demo must never make live paid
// calls — route to the FakeAddressValidator instead.
it('uses the fake validator when sandbox mode is enabled', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);
    app()->forgetInstance(AddressValidationService::class);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validation_message)->toBe('Address confirmed deliverable (fake)');
});

// Sandbox mode can opt into real validator calls (for testing the actual
// integration), overriding the fake-by-default behavior above.
it('uses real validators when sandbox mode opts into real address validation', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);
    app(SettingsService::class)->set('address_validation_use_real_in_sandbox', true);
    app()->forgetInstance(AddressValidationService::class);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'matches' => [['code' => '31']],
            'address' => [
                'streetAddress' => '1600 PENNSYLVANIA AVE NW',
                'city' => 'WASHINGTON',
                'state' => 'DC',
                'ZIPCode' => '20500',
            ],
            'additionalInfo' => ['DPVConfirmation' => 'Y', 'business' => 'N'],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validation_message)->toBe('Address confirmed deliverable');
});

// Demo mode must always use the fake validator, even if a sandbox instance
// previously had "use real address validation" turned on — demo tenants must
// never make live paid calls, no exceptions.
it('always uses the fake validator in demo mode, even if real validation is enabled for sandbox', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);
    app(SettingsService::class)->set('address_validation_use_real_in_sandbox', true);
    $this->app['env'] = 'demo';
    app()->forgetInstance(AddressValidationService::class);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validation_message)->toBe('Address confirmed deliverable (fake)');
});

// --- A chain that ends inconclusive -------------------------------------------

it('leaves the Shipment unverified and dispatches one failure when no validator settles it', function (): void {
    Event::fake([AddressValidationFailed::class]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'corrections' => [
                ['code' => '22', 'text' => 'Multiple addresses were found for the information you entered.'],
            ],
        ]),
        GoogleValidateAddress::class => MockResponse::make(['result' => [
            'verdict' => ['addressComplete' => false],
            'address' => ['postalAddress' => [], 'addressComponents' => []],
        ]]),
    ]);

    $service = new AddressValidationService([new UspsAddressValidator, new GoogleAddressValidator]);
    $shipment = Shipment::factory()->create(['country' => 'US']);

    expect($service->validate($shipment))->toBe(AddressValidationOutcome::Inconclusive);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Unverified)
        ->and($shipment->checked)->toBeFalse();

    Event::assertDispatchedTimes(AddressValidationFailed::class, 1);
    Event::assertDispatched(
        AddressValidationFailed::class,
        fn (AddressValidationFailed $event): bool => str_starts_with($event->reason, 'No validator could confirm or reject the address'),
    );
});

// Regression: a valid German or Polish address Google reads as incomplete was
// recorded as not deliverable and counted as an exception.
it('records an incomplete Google verdict on an international address as unverified, not No', function (): void {
    Saloon::fake([
        GoogleValidateAddress::class => MockResponse::make(['result' => [
            'verdict' => ['addressComplete' => false, 'hasUnconfirmedComponents' => true],
            'address' => ['postalAddress' => [], 'addressComponents' => [
                ['componentType' => 'route', 'confirmationLevel' => 'UNCONFIRMED_BUT_PLAUSIBLE'],
            ]],
        ]]),
    ]);

    $service = new AddressValidationService([new UspsAddressValidator, new GoogleAddressValidator]);
    $shipment = Shipment::factory()->create(['country' => 'PL']);

    $service->validate($shipment);

    expect($shipment->fresh()->deliverability)->toBe(Deliverability::Unverified);
});
