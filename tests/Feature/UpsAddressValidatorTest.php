<?php

use App\Enums\Deliverability;
use App\Http\Integrations\Ups\Requests\ValidateAddress;
use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\Validation\UpsAddressValidator;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    createUpsAccount();
    $this->validator = new UpsAddressValidator;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function upsCandidate(array $overrides = []): array
{
    return array_replace_recursive([
        'AddressClassification' => ['Code' => '2', 'Description' => 'Residential'],
        'AddressKeyFormat' => [
            'AddressLine' => ['26601 ALISO CREEK RD', 'STE D'],
            'PoliticalDivision2' => 'ALISO VIEJO',
            'PoliticalDivision1' => 'CA',
            'PostcodePrimaryLow' => '92656',
            'PostcodeExtendedLow' => '5301',
            'Region' => 'ALISO VIEJO CA 92656-5301',
            'CountryCode' => 'US',
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $xav
 */
function fakeUpsValidation(array $xav, int $status = 200): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ValidateAddress::class => MockResponse::make($status === 200
            ? ['XAVResponse' => array_merge(['Response' => ['ResponseStatus' => ['Code' => '1', 'Description' => 'Success']]], $xav)]
            : $xav, $status),
    ]);
}

it('supports US and Puerto Rico only', function (): void {
    expect($this->validator->supports('US'))->toBeTrue()
        ->and($this->validator->supports('PR'))->toBeTrue()
        ->and($this->validator->supports('CA'))->toBeFalse();
});

it('sends a request body that conforms to the UPS schema', function (): void {
    fakeUpsValidation(['ValidAddressIndicator' => '', 'Candidate' => [upsCandidate()]]);

    $shipment = Shipment::factory()->create([
        'address1' => '26601 Aliso Creek Road',
        'address2' => 'Ste D',
        'city' => 'Aliso Viejo',
        'state_or_province' => 'CA',
        'postal_code' => '92656-5301',
        'country' => 'US',
    ]);

    $this->validator->validate($shipment);

    Saloon::assertSent(function (ValidateAddress $request): bool {
        $body = $request->body()->all();

        assertMatchesApiSchema($body, 'XAVRequestWrapper', 'upsAddressValidation');

        return $request->resolveEndpoint() === '/api/addressvalidation/v2/3'
            && $body === ['XAVRequest' => ['AddressKeyFormat' => [
                'AddressLine' => ['26601 Aliso Creek Road', 'Ste D'],
                'PoliticalDivision2' => 'Aliso Viejo',
                'PoliticalDivision1' => 'CA',
                'PostcodePrimaryLow' => '92656',
                'PostcodeExtendedLow' => '5301',
                'CountryCode' => 'US',
            ]]];
    });
});

it('omits empty fields UPS would reject', function (): void {
    fakeUpsValidation(['NoCandidatesIndicator' => '']);

    $shipment = Shipment::factory()->create([
        'address1' => '1 Main St',
        'address2' => null,
        'postal_code' => '10001',
        'country' => 'US',
    ]);

    $this->validator->validate($shipment);

    Saloon::assertSent(function (ValidateAddress $request): bool {
        $address = $request->body()->all()['XAVRequest']['AddressKeyFormat'];

        assertMatchesApiSchema($request->body()->all(), 'XAVRequestWrapper', 'upsAddressValidation');

        return $address['AddressLine'] === ['1 Main St']
            && ! array_key_exists('PostcodeExtendedLow', $address);
    });
});

it('marks a valid address deliverable and applies the candidate', function (): void {
    fakeUpsValidation([
        'ValidAddressIndicator' => '',
        'AddressClassification' => ['Code' => '2', 'Description' => 'Residential'],
        'Candidate' => [upsCandidate()],
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validation_message)->toBe('Address confirmed valid')
        ->and($shipment->validated_address1)->toBe('26601 ALISO CREEK RD')
        ->and($shipment->validated_address2)->toBe('STE D')
        ->and($shipment->validated_city)->toBe('ALISO VIEJO')
        ->and($shipment->validated_state_or_province)->toBe('CA')
        ->and($shipment->validated_postal_code)->toBe('92656-5301')
        ->and($shipment->validated_residential)->toBeTrue();
});

it('accepts a single candidate returned as an object with a string address line', function (): void {
    fakeUpsValidation([
        'ValidAddressIndicator' => '',
        'Candidate' => upsCandidate(['AddressKeyFormat' => ['AddressLine' => '1 MAIN ST']]),
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validated_address1)->toBe('1 MAIN ST')
        ->and($shipment->validated_address2)->toBeNull();
});

it('maps classification to residential', function (string $code, ?bool $residential): void {
    fakeUpsValidation([
        'ValidAddressIndicator' => '',
        'Candidate' => [upsCandidate(['AddressClassification' => ['Code' => $code, 'Description' => 'x']])],
    ]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    expect($shipment->refresh()->validated_residential)->toBe($residential);
})->with([
    'residential' => ['2', true],
    'commercial' => ['1', false],
    'unclassified' => ['0', null],
]);

it('leaves the shipment unchecked when UPS has no single match', function (array $xav, string $message): void {
    fakeUpsValidation($xav);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe($message)
        ->and($shipment->validated_address1)->toBeNull();
})->with([
    'ambiguous' => [
        ['AmbiguousAddressIndicator' => '', 'Candidate' => [upsCandidate(), upsCandidate()]],
        'Multiple addresses were found for the information you entered.',
    ],
    'no candidates' => [['NoCandidatesIndicator' => ''], 'Address not found'],
]);

it('leaves the shipment unchecked on a UPS client error', function (): void {
    fakeUpsValidation([
        'response' => ['errors' => [['code' => '264002', 'message' => 'Country code is invalid or missing.']]],
    ], 400);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe('Country code is invalid or missing.');
});

it('does not touch the shipment when UPS refuses access', function (): void {
    fakeUpsValidation([
        'response' => ['errors' => [['code' => '250002', 'message' => 'Invalid Authentication Information.']]],
    ], 401);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    $shipment->refresh();
    expect($shipment->checked)->toBeFalse()
        ->and($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->validation_message)->toBeNull();
});

it('does not attempt validation without a UPS account', function (): void {
    CarrierAccount::query()->delete();
    Saloon::fake([]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    Saloon::assertNothingSent();
    expect($shipment->refresh()->deliverability)->toBe(Deliverability::NotChecked);
});

it('does not touch the shipment when a UPS OAuth connection needs reconnecting', function (): void {
    CarrierAccount::query()->delete();
    createUpsAccount(['auth_mode' => 'authorization_code']);
    Saloon::fake([]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    Saloon::assertNothingSent();
    expect($shipment->refresh()->deliverability)->toBe(Deliverability::NotChecked);
});

it('does not touch the shipment when the UPS account is missing its credentials', function (): void {
    CarrierAccount::query()->delete();
    createUpsAccount(['client_id' => '', 'client_secret' => '']);
    Saloon::fake([]);

    $shipment = Shipment::factory()->create(['country' => 'US']);
    $this->validator->validate($shipment);

    Saloon::assertNothingSent();
    expect($shipment->refresh()->deliverability)->toBe(Deliverability::NotChecked);
});
