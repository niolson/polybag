<?php

use App\Enums\Deliverability;
use App\Http\Integrations\USPS\Requests\Address;
use App\Models\Shipment;
use App\Services\AddressValidationService;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->service = app(AddressValidationService::class);
});

// Scenario 1: Not checked (non-US address is skipped)
it('skips non-US addresses', function (): void {
    $shipment = Shipment::factory()->create(['country' => 'CA']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBeNull()
        ->and($shipment->validation_message)->toBeNull()
        ->and($shipment->checked)->toBeFalse();
});

// Scenario 2: API error (address not found)
it('sets deliverability to No on API error', function (): void {
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
    expect($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe('Address Not Found.')
        ->and($shipment->checked)->toBeTrue();
});

// Scenario 3: Multiple addresses (correction code 22)
it('sets deliverability to No for multiple addresses found', function (): void {
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
    expect($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe('Multiple addresses were found for the information you entered.')
        ->and($shipment->checked)->toBeTrue();
});

// Scenario 4: Default address (correction code 32)
it('sets deliverability to Maybe for default address correction', function (): void {
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
    expect($shipment->deliverability)->toBe(Deliverability::Maybe)
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
it('sets deliverability to Maybe for exact match with DPV D', function (): void {
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
    expect($shipment->deliverability)->toBe(Deliverability::Maybe)
        ->and($shipment->validation_message)->toBe('Primary address confirmed, secondary number missing')
        ->and($shipment->validated_address1)->toBe('789 PINE RD')
        ->and($shipment->checked)->toBeTrue();
});

// Scenario 7: Exact match, DPV S (primary confirmed, secondary not confirmed)
it('sets deliverability to Maybe for exact match with DPV S', function (): void {
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
    expect($shipment->deliverability)->toBe(Deliverability::Maybe)
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
    expect($shipment->deliverability)->toBeNull()
        ->and($shipment->validation_message)->toBeNull()
        ->and($shipment->checked)->toBeFalse();
});

// Unexpected response format
it('sets deliverability to No for unexpected response format', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Address::class => MockResponse::make([
            'someUnexpectedField' => 'value',
        ]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);

    $this->service->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe('Unexpected USPS response format')
        ->and($shipment->checked)->toBeTrue();
});
