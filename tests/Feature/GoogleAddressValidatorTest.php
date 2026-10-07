<?php

use App\Enums\AddressValidationOutcome;
use App\Enums\Deliverability;
use App\Http\Integrations\Google\Requests\ValidateAddress;
use App\Models\Shipment;
use App\Services\Validation\GoogleAddressValidator;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    config(['services.google_address_validation.api_key' => 'test-google-key']);
    $this->validator = new GoogleAddressValidator;
});

it('supports every country', function (): void {
    expect($this->validator->supports('US'))->toBeTrue()
        ->and($this->validator->supports('CA'))->toBeTrue()
        ->and($this->validator->supports('XX'))->toBeTrue();
});

it('marks a reference-data match Verified, not deliverable', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
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
                'metadata' => ['business' => true, 'poBox' => false, 'residential' => false],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validation_message)->toBe('Address matched reference data')
        ->and($shipment->validated_address1)->toBe('1600 Amphitheatre Pkwy')
        ->and($shipment->validated_city)->toBe('Mountain View')
        ->and($shipment->validated_state_or_province)->toBe('CA')
        ->and($shipment->validated_postal_code)->toBe('94043')
        ->and($shipment->validated_residential)->toBeFalse();
});

it('marks the address Partial when components are unconfirmed but plausible', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => true],
                'address' => [
                    'postalAddress' => [
                        'addressLines' => ['42 Test St'],
                        'locality' => 'Testville',
                        'administrativeArea' => 'TX',
                        'postalCode' => '73301',
                    ],
                    'addressComponents' => [
                        ['componentType' => 'subpremise', 'confirmationLevel' => 'UNCONFIRMED_BUT_PLAUSIBLE'],
                    ],
                ],
                'metadata' => ['business' => false, 'poBox' => false, 'residential' => true],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Partial)
        ->and($shipment->validated_residential)->toBeTrue();
});

it('marks the address No when a component is unconfirmed and suspicious', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => true],
                'address' => [
                    'postalAddress' => [
                        'addressLines' => ['999 Nowhere Ave'],
                        'locality' => 'Nowhere',
                        'administrativeArea' => 'ZZ',
                        'postalCode' => '00000',
                    ],
                    'addressComponents' => [
                        ['componentType' => 'route', 'confirmationLevel' => 'UNCONFIRMED_AND_SUSPICIOUS'],
                    ],
                ],
                'metadata' => ['business' => false, 'poBox' => false, 'residential' => false],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::No);
});

it('reads an incomplete address as inconclusive, not No', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => false, 'hasUnconfirmedComponents' => true],
                'address' => ['postalAddress' => [], 'addressComponents' => []],
                'metadata' => [],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    expect($this->validator->validate($shipment)->outcome)->toBe(AddressValidationOutcome::Inconclusive);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::Unverified)
        ->and($shipment->validated_address1)->toBeNull();
});

it('prefers USPS DPV data over the geocoding verdict when Google returns it', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
                'address' => [
                    'postalAddress' => [
                        'addressLines' => ['1600 Amphitheatre Pkwy'],
                        'locality' => 'Mountain View',
                        'administrativeArea' => 'CA',
                        'postalCode' => '94043',
                    ],
                    'addressComponents' => [],
                ],
                'metadata' => ['residential' => false],
                'uspsData' => [
                    'dpvConfirmation' => 'Y',
                    'carrierRoute' => 'C018',
                ],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validation_message)->toBe('Address confirmed deliverable')
        ->and($shipment->validated_carrier_route)->toBe('C018');
});

it('marks the address Maybe when USPS DPV data shows a missing secondary number', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
                'address' => ['postalAddress' => [], 'addressComponents' => []],
                'metadata' => [],
                'uspsData' => [
                    'dpvConfirmation' => 'D',
                    'carrierRoute' => 'C018',
                ],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Partial)
        ->and($shipment->validation_message)->toBe('Primary address confirmed, secondary number missing');
});

it('marks the address No for a phantom route even when the geocoding verdict looks complete', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
                'address' => ['postalAddress' => [], 'addressComponents' => []],
                'metadata' => [],
                'uspsData' => [
                    'dpvConfirmation' => 'Y',
                    'carrierRoute' => 'R777',
                ],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe('Address exists but is not deliverable (phantom route)');
});

it('falls back to the geocoding verdict when uspsData is present but has no usable DPV confirmation', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
                'address' => [
                    'postalAddress' => [
                        'addressLines' => ['1600 Amphitheatre Pkwy'],
                        'locality' => 'Mountain View',
                        'administrativeArea' => 'CA',
                        'postalCode' => '94043',
                    ],
                    'addressComponents' => [],
                ],
                'metadata' => ['residential' => false],
                'uspsData' => [],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validation_message)->toBe('Address matched reference data');
});

it('falls back to the geocoding verdict for non-US addresses with no USPS DPV data', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
                'address' => [
                    'postalAddress' => [
                        'addressLines' => ['10 Downing St'],
                        'locality' => 'London',
                    ],
                    'addressComponents' => [],
                ],
                'metadata' => ['residential' => false],
            ],
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'GB']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validation_message)->toBe('Address matched reference data');
});

it('leaves the shipment unchecked when neither the broker nor a local API key is configured', function (): void {
    config([
        'services.google_address_validation.api_key' => null,
        'services.oauth.broker_url' => null,
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked);

    Saloon::assertNothingSent();
});

it('leaves the shipment unchecked on a transport failure', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make('', 503),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked);
});

it('sends no administrative area for a country whose addresses do not use one', function (): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make(['result' => [
            'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
            'address' => ['postalAddress' => [], 'addressComponents' => []],
            'metadata' => [],
        ]]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'DE', 'state_or_province' => 'BY']);
    $this->validator->validate($shipment);

    Saloon::assertSent(fn (ValidateAddress $request): bool => $request->body()->all()['address']['regionCode'] === 'DE'
        && ! array_key_exists('administrativeArea', $request->body()->all()['address']));
});

it('sends the administrative area for a country whose addresses use one', function (string $country, string $state): void {
    Saloon::fake([
        ValidateAddress::class => MockResponse::make(['result' => [
            'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
            'address' => ['postalAddress' => [], 'addressComponents' => []],
            'metadata' => [],
        ]]),
    ]);

    $shipment = Shipment::factory()->create(['country' => $country, 'state_or_province' => $state]);
    $this->validator->validate($shipment);

    Saloon::assertSent(fn (ValidateAddress $request): bool => ($request->body()->all()['address']['administrativeArea'] ?? null) === $state);
})->with([
    'US' => ['US', 'CA'],
    'CA' => ['CA', 'ON'],
    'AU' => ['AU', 'NSW'],
]);

it('settles a German address with a stray stored state once the state is left out', function (): void {
    Saloon::fake([
        ValidateAddress::class => function ($pendingRequest): MockResponse {
            $sentState = array_key_exists('administrativeArea', $pendingRequest->body()->all()['address']);

            return MockResponse::make(['result' => [
                'verdict' => ['addressComplete' => ! $sentState, 'hasUnconfirmedComponents' => $sentState],
                'address' => [
                    'postalAddress' => [
                        'addressLines' => ['Marienplatz 8'],
                        'locality' => 'München',
                        'postalCode' => '80331',
                    ],
                    'addressComponents' => [],
                ],
                'metadata' => ['residential' => false],
            ]]);
        },
    ]);

    $shipment = Shipment::factory()->create([
        'country' => 'DE',
        'state_or_province' => 'Bayern',
        'address1' => 'Marienplatz 8',
        'city' => 'München',
        'postal_code' => '80331',
    ]);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validated_address1)->toBe('Marienplatz 8');
});
