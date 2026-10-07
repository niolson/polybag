<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\Enums\AddressValidationOutcome;
use App\Enums\Deliverability;
use App\Enums\ValidationReason;
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

it('supports the US, Puerto Rico and the countries routed to it', function (string $country, bool $supported): void {
    expect($this->validator->supports($country))->toBe($supported);
})->with([
    'US' => ['US', true],
    'Puerto Rico' => ['PR', true],
    'trusted' => ['DE', true],
    'Google unsupported' => ['HK', true],
    'excluded' => ['BE', false],
    'unlisted' => ['CA', false],
]);

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

// --- International reading ---------------------------------------------------------

/**
 * A GAM_VALIDATE resolved address in the shape of the 2026-09-30 production
 * captures, with a synthetic address: a house-level match unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fedexInternationalAddress(array $overrides = []): array
{
    return array_replace_recursive([
        'streetLinesToken' => ['MUSTERWEG 12', 'NORDSTADT'],
        'city' => 'BEISPIELHAUSEN',
        'stateOrProvinceCode' => 'NW',
        'postalCode' => '12345',
        'parsedPostalCode' => ['base' => '12345'],
        'countryCode' => 'DE',
        'classification' => 'UNKNOWN',
        'normalizedStatusNameDPV' => false,
        'standardizedStatusNameMatchSource' => 'Postal',
        'resolutionMethodName' => 'GAM_VALIDATE',
        'attributes' => [
            'ResolutionMethod' => 'GAM_VALIDATE',
            'PostalDataSource' => 'Spectrum International GAM',
            'CountrySupported' => 'true',
            'ValidlyFormed' => 'true',
            'Matched' => 'true',
            'StreetAddress' => 'true',
            'StreetNameAddress' => 'false',
            'InterpolatedStreetAddress' => 'false',
            'AddressPrecision' => 'STREET_ADDRESS',
            'AddressType' => 'STANDARDIZED',
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function fedexInternationalShipment(array $attributes = []): Shipment
{
    return Shipment::factory()->create([
        'address1' => 'Musterweg 12',
        'address2' => '3. OG links',
        'city' => 'Beispielhausen',
        'state_or_province' => null,
        'postal_code' => '12345',
        'country' => 'DE',
        ...$attributes,
    ]);
}

it('verifies an international house-level match with the same house number', function (): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [fedexInternationalAddress()]]]));

    $shipment = fedexInternationalShipment();
    $result = $this->validator->validate($shipment);

    $shipment->refresh();
    expect($result->outcome)->toBe(AddressValidationOutcome::Settled)
        ->and($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Verified)
        ->and($shipment->validation_message)->toBe('Address matched reference data')
        ->and($shipment->validated_address1)->toBe('MUSTERWEG 12')
        ->and($shipment->validated_city)->toBe('BEISPIELHAUSEN')
        ->and($shipment->validated_postal_code)->toBe('12345')
        ->and($shipment->validated_residential)->toBeNull();
});

it('keeps the unit line and state sent rather than FedEx\'s locality line and code', function (): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [fedexInternationalAddress()]]]));

    $shipment = fedexInternationalShipment();
    $this->validator->validate($shipment);

    $address = AddressData::fromShipment($shipment->refresh());
    expect($shipment->validated_address2)->toBeNull()
        ->and($shipment->validated_state_or_province)->toBeNull()
        ->and($address->streetAddress2)->toBe('3. OG links')
        ->and($address->stateOrProvince)->toBeNull();
});

it('leaves an international match inconclusive unless the same house matched', function (
    string $sent,
    array $resolved,
    ValidationReason $reason,
    string $message,
): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [fedexInternationalAddress($resolved)]]]));

    $shipment = fedexInternationalShipment(['address1' => $sent]);
    $result = $this->validator->validate($shipment);

    $shipment->refresh();
    expect($result->outcome)->toBe(AddressValidationOutcome::Inconclusive)
        ->and($result->reason)->toBe($reason)
        ->and($shipment->checked)->toBeFalse()
        ->and($shipment->validation_message)->toBe($message)
        ->and($shipment->validated_address1)->toBeNull();
})->with([
    // Matched=false: the shape of most inflated numbers in DE, FR and CZ.
    'not matched' => ['Musterweg 12', ['attributes' => ['Matched' => 'false']], ValidationReason::NoMatch, 'Address could not be matched'],
    // NO: Skåmekleiva 7067 came back SKÅMEKLEIVA 829.
    'house number substituted' => ['Musterweg 7067', ['streetLinesToken' => ['MUSTERWEG 829']], ValidationReason::HouseNumberChanged, 'Matched with a different house number: MUSTERWEG 829'],
    // IT: VIA FEDERICO SECONDO 237 came back VIA FEDERICO II.
    'house number dropped' => ['Musterweg 237', ['streetLinesToken' => ['MUSTERWEG']], ValidationReason::HouseNumberChanged, 'Matched with a different house number: MUSTERWEG'],
    // BE: Geraniumstraat 957 came back GERANIUM STRAAT 957, still STREET_ADDRESS precision.
    'street-only match echoing the number' => ['Musterweg 957', ['streetLinesToken' => ['MUSTERWEG 957'], 'attributes' => ['StreetAddress' => 'false']], ValidationReason::StreetOnly, 'Street found, house number not confirmed'],
    // CH and AT through GENERIC_VALIDATE.
    'street precision' => ['Musterweg 12', ['resolutionMethodName' => 'GENERIC_VALIDATE', 'attributes' => ['ResolutionMethod' => 'GENERIC_VALIDATE', 'StreetAddress' => 'false', 'StreetNameAddress' => 'true', 'AddressPrecision' => 'Street']], ValidationReason::StreetOnly, 'Street found, house number not confirmed'],
    'two-part number reduced to its first part' => ['Lipová 482/22', ['streetLinesToken' => ['LIPOVÁ 482']], ValidationReason::HouseNumberChanged, 'Matched with a different house number: LIPOVÁ 482'],
]);

it('reads a two-part house number as the same when FedEx keeps the second part or both', function (string $returned): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [
        fedexInternationalAddress(['streetLinesToken' => [$returned], 'countryCode' => 'CZ']),
    ]]]));

    $shipment = fedexInternationalShipment(['address1' => 'Lipová 482/22', 'country' => 'CZ']);
    $this->validator->validate($shipment);

    expect($shipment->refresh()->deliverability)->toBe(Deliverability::Verified);
})->with([
    // SK: Záhradná 482/22 came back ZÁHRADNÁ 22, as all 19 Slovak captures did.
    'second part' => ['LIPOVÁ 22'],
    'both parts' => ['LIPOVÁ 482/22'],
]);

it('compares every number in the street line', function (string $sent, string $returned, Deliverability $deliverability): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [
        fedexInternationalAddress(['streetLinesToken' => [$returned]]),
    ]]]));

    $shipment = fedexInternationalShipment(['address1' => $sent]);
    $this->validator->validate($shipment);

    expect($shipment->refresh()->deliverability)->toBe($deliverability);
})->with([
    // CL: 12 DE OCTUBRE 0605 came back CALLE 12 DE OCTUBRE 605.
    'leading zero dropped' => ['12 de Octubre 0605', 'CALLE 12 DE OCTUBRE 605', Deliverability::Verified],
    // LU: Route de Mondorf 8 came back 8 ROUTE DE MONDORF.
    'number moved to the front' => ['Route de Mondorf 8', '8 ROUTE DE MONDORF', Deliverability::Verified],
    'letter suffix kept' => ['Musterweg 48a', 'MUSTERWEG 48A', Deliverability::Verified],
    // SI: Bukovica pri Vodicah 55d came back BUKOVICA PRI VODICAH 55.
    'letter suffix dropped' => ['Musterweg 55d', 'MUSTERWEG 55', Deliverability::No],
    'number in the street name kept' => ['Ulica 3 Maja 5', 'ULICA 3 MAJA 5', Deliverability::Verified],
    'number in the street name changed' => ['Ulica 3 Maja 5', 'ULICA 3 MAJA 7', Deliverability::No],
    'no number sent' => ['Musterweg', 'MUSTERWEG', Deliverability::No],
    'repetition suffix kept' => ['12 bis Rue Exemple', '12 BIS RUE EXEMPLE', Deliverability::Verified],
    'repetition suffix kept, unspaced' => ['12bis Rue Exemple', '12 BIS RUE EXEMPLE', Deliverability::Verified],
    'repetition suffix dropped' => ['12 bis Rue Exemple', '12 RUE EXEMPLE', Deliverability::No],
    'repetition suffix abbreviated' => ['12 ter Rue Exemple', '12 T RUE EXEMPLE', Deliverability::No],
    'word after the number not taken as a suffix' => ['12 Terrasse du Parc', '12 TERRASSE DU PARC', Deliverability::Verified],
]);

it('accepts a slash number\'s second part only in Czechia and Slovakia', function (string $country, string $returned, Deliverability $deliverability): void {
    fakeFedexValidation(MockResponse::make(['output' => ['resolvedAddresses' => [
        fedexInternationalAddress(['streetLinesToken' => [$returned], 'countryCode' => $country]),
    ]]]));

    $shipment = fedexInternationalShipment(['address1' => 'Lipowa 12/3', 'country' => $country]);
    $this->validator->validate($shipment);

    expect($shipment->refresh()->deliverability)->toBe($deliverability);
})->with([
    'CZ, second part' => ['CZ', 'LIPOWA 3', Deliverability::Verified],
    'SK, second part' => ['SK', 'LIPOWA 3', Deliverability::Verified],
    // PL: in 12/3 the 3 can be the flat, so LIPOWA 3 is another building.
    'PL, second part' => ['PL', 'LIPOWA 3', Deliverability::No],
    'PL, building only' => ['PL', 'LIPOWA 12', Deliverability::No],
    'PL, whole number' => ['PL', 'LIPOWA 12/3', Deliverability::Verified],
]);
