<?php

use App\Enums\Deliverability;
use App\Http\Integrations\Fedex\Requests\ValidateAddress;
use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\Validation\FedexAddressValidator;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    createFedexAccount();
    $this->validator = new FedexAddressValidator;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fedexResolvedAddress(array $overrides = []): array
{
    return array_replace_recursive([
        'streetLinesToken' => ['7372 PARKRIDGE BLVD', 'APT 286'],
        'city' => 'IRVING',
        'stateOrProvinceCode' => 'TX',
        'countryCode' => 'US',
        'parsedPostalCode' => ['base' => '75063', 'addOn' => '8659', 'deliveryPoint' => '86'],
        'classification' => 'RESIDENTIAL',
        'attributes' => [
            'Resolved' => 'true',
            'DPV' => 'true',
            'Matched' => 'true',
            'InvalidSuiteNumber' => 'false',
            'SuiteRequiredButMissing' => 'false',
        ],
    ], $overrides);
}

function fakeFedexValidation(MockResponse $response): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ValidateAddress::class => $response,
    ]);
}

it('supports US and Puerto Rico only', function (): void {
    expect($this->validator->supports('US'))->toBeTrue()
        ->and($this->validator->supports('PR'))->toBeTrue()
        ->and($this->validator->supports('CA'))->toBeFalse();
});

it('sends the shipment address to the resolve endpoint', function (): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [fedexResolvedAddress()]]]));

    $shipment = Shipment::factory()->create([
        'address1' => '7372 Parkridge Blvd',
        'address2' => 'Apt 286',
        'city' => 'Irving',
        'state_or_province' => 'TX',
        'postal_code' => '75063',
        'country' => 'US',
    ]);

    $this->validator->validate($shipment);

    Saloon::assertSent(fn (ValidateAddress $request, $response): bool => $response->getPendingRequest()->getUrl() === 'https://apis.fedex.com/address/v1/addresses/resolve'
        && $request->body()->all() === [
            'addressesToValidate' => [[
                'address' => [
                    'streetLines' => ['7372 Parkridge Blvd', 'Apt 286'],
                    'city' => 'Irving',
                    'stateOrProvinceCode' => 'TX',
                    'postalCode' => '75063',
                    'countryCode' => 'US',
                ],
            ]],
        ]);
});

it('marks a DPV-confirmed address deliverable and applies the resolved address', function (): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [fedexResolvedAddress()]]]));

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validation_message)->toBe('Address confirmed deliverable')
        ->and($shipment->validated_address1)->toBe('7372 PARKRIDGE BLVD')
        ->and($shipment->validated_address2)->toBe('APT 286')
        ->and($shipment->validated_city)->toBe('IRVING')
        ->and($shipment->validated_state_or_province)->toBe('TX')
        ->and($shipment->validated_postal_code)->toBe('75063-8659')
        ->and($shipment->validated_residential)->toBeTrue();
});

it('accepts attributes as real booleans', function (): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [
        fedexResolvedAddress(['attributes' => ['Resolved' => true, 'DPV' => true]]),
    ]]]));

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    expect($shipment->refresh()->deliverability)->toBe(Deliverability::Yes);
});

it('maps classification to residential', function (string $classification, ?bool $residential): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [
        fedexResolvedAddress(['classification' => $classification]),
    ]]]));

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    expect($shipment->refresh()->validated_residential)->toBe($residential);
})->with([
    'residential' => ['RESIDENTIAL', true],
    'business' => ['BUSINESS', false],
    'mixed' => ['MIXED', null],
    'unknown' => ['UNKNOWN', null],
]);

it('marks the address Maybe when the suite is missing or invalid', function (string $attribute, string $message): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [
        fedexResolvedAddress(['attributes' => [$attribute => 'true']]),
    ]]]));

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Partial)
        ->and($shipment->validation_message)->toBe($message);
})->with([
    'missing' => ['SuiteRequiredButMissing', 'Primary address confirmed, secondary number missing'],
    'invalid' => ['InvalidSuiteNumber', 'Primary address confirmed, secondary number not confirmed'],
]);

it('marks a single-organization ZIP partly verified though FedEx reports a delivery point', function (): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [
        fedexResolvedAddress(['attributes' => ['AddressPrecision' => 'UNIQUE_ZIP']]),
    ]]]));

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Partial)
        ->and($shipment->validation_message)->toBe('ZIP code belongs to a single organization, address within it not confirmed');
});

it('leaves the shipment unchecked when FedEx cannot confirm a delivery point', function (array $attributes, string $message): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [
        fedexResolvedAddress(['attributes' => $attributes]),
    ]]]));

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe($message)
        ->and($shipment->validated_address1)->toBeNull();
})->with([
    'not resolved' => [['Resolved' => 'false'], 'Address could not be resolved'],
    'map match only' => [['DPV' => 'false'], 'Address found but not confirmed as a delivery point'],
]);

it('leaves the shipment unchecked on a FedEx client error', function (): void {
    fakeFedexValidation(MockResponse::make([
        'errors' => [['code' => 'INVALID.INPUT.EXCEPTION', 'message' => 'Invalid field value in the input']],
    ], 400));

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe('Invalid field value in the input');
});

it('does not touch the shipment when FedEx refuses access', function (): void {
    // A FedEx project without the Address Validation API enabled answers 403.
    fakeFedexValidation(MockResponse::make([
        'errors' => [['code' => 'FORBIDDEN.ERROR', 'message' => 'We could not authorize your credentials.']],
    ], 403));

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->validation_message)->toBeNull();
});

it('does not attempt validation without a FedEx account', function (): void {
    CarrierAccount::query()->delete();
    Saloon::fake([]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    Saloon::assertNothingSent();
    expect($shipment->refresh()->deliverability)->toBe(Deliverability::NotChecked);
});

it('does not touch the shipment when the token request fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['errors' => [['code' => 'NOT.AUTHORIZED.ERROR']]], 401),
        ValidateAddress::class => MockResponse::make(['output' => ['resolvedAddresses' => [fedexResolvedAddress()]]]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    Saloon::assertNotSent(ValidateAddress::class);
    expect($shipment->refresh()->deliverability)->toBe(Deliverability::NotChecked);
});

it('does not touch the shipment when the FedEx account is missing its credentials', function (): void {
    // Unlike UPS, the FedEx connector builds its own token request and skips
    // Saloon's empty-credential check, so FedEx answers the attempt with 401.
    CarrierAccount::query()->delete();
    createFedexAccount(['api_key' => '', 'api_secret' => '']);
    Saloon::fake([
        '*oauth*' => MockResponse::make(['errors' => [['code' => 'NOT.AUTHORIZED.ERROR']]], 401),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    Saloon::assertNotSent(ValidateAddress::class);
    expect($shipment->refresh()->deliverability)->toBe(Deliverability::NotChecked);
});
