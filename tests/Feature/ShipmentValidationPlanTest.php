<?php

use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\PostageSourceKind;
use App\Enums\ValidationReason;
use App\Http\Integrations\Fedex\Requests\ValidateAddress as FedexValidateAddress;
use App\Http\Integrations\Google\Requests\ValidateAddress as GoogleValidateAddress;
use App\Http\Integrations\USPS\Requests\Address as UspsAddress;
use App\Models\AddressValidationAnswer;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\Location;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Services\AddressValidationService;
use App\Services\SettingsService;
use App\Services\Validation\AddressValidationCountries;
use App\Services\Validation\FedexAddressValidator;
use App\Services\Validation\ShipmentValidationPlan;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * A shipping method listing one active service per named carrier, and
 * allowing Amazon Buy Shipping when asked.
 *
 * @param  list<string>  $carriers
 */
function planMethod(array $carriers, bool $amazon = false): ShippingMethod
{
    $method = ShippingMethod::factory()->create();

    foreach ($carriers as $name) {
        $carrier = Carrier::firstOrCreate(['name' => $name]);
        $method->carrierServices()->attach(CarrierService::factory()->create(['carrier_id' => $carrier->id]));
    }

    if ($amazon) {
        ShippingMethodPostageSource::factory()->amazon()->create(['shipping_method_id' => $method->id]);
    }

    return $method->refresh();
}

function planFedexAccount(bool $active = true): CarrierAccount
{
    return CarrierAccount::factory()->create([
        'carrier_id' => Carrier::firstOrCreate(['name' => Carrier::FEDEX])->id,
        'active' => $active,
    ]);
}

/**
 * @param  array{location?: Location, client?: Client}  $slot
 */
function planScope(CarrierAccount $account, array $slot = []): CarrierAccountScope
{
    return CarrierAccountScope::factory()->forAccount($account)->create([
        'location_id' => ($slot['location'] ?? null)?->id,
        'client_id' => ($slot['client'] ?? null)?->id,
    ]);
}

/**
 * @return list<AddressValidator>
 */
function plannedValidators(Shipment $shipment): array
{
    return array_map(
        fn ($validator): AddressValidator => $validator->validator(),
        app(ShipmentValidationPlan::class)->validatorsFor($shipment),
    );
}

beforeEach(function (): void {
    $this->client = Client::factory()->create();
});

// --- Shipping method and account ------------------------------------------------

it('orders the validators by what the method can buy and the Client\'s FedEx account', function (
    ?array $carriers,
    bool $amazon,
    ?bool $fedexAccountActive,
    array $expected,
): void {
    if ($fedexAccountActive !== null) {
        planScope(planFedexAccount($fedexAccountActive), ['client' => $this->client]);
    }

    $shipment = Shipment::factory()->create([
        'client_id' => $this->client->id,
        'shipping_method_id' => $carriers === null ? null : planMethod($carriers, $amazon)->id,
    ]);

    expect(plannedValidators($shipment))->toBe($expected);
})->with([
    'FedEx-only method' => [[Carrier::FEDEX], false, true, [AddressValidator::Fedex, AddressValidator::Usps]],
    'UPS-only method' => [[Carrier::UPS], false, true, [AddressValidator::Usps]],
    'USPS-only method' => [[Carrier::USPS], false, true, [AddressValidator::Usps]],
    'mixed FedEx and UPS method' => [[Carrier::UPS, Carrier::FEDEX], false, true, [AddressValidator::Fedex, AddressValidator::Usps]],
    'method allowing Amazon Buy Shipping' => [[Carrier::USPS], true, true, [AddressValidator::Fedex, AddressValidator::Usps]],
    'no method' => [null, false, true, [AddressValidator::Usps]],
    'FedEx method, no FedEx account' => [[Carrier::FEDEX], false, null, [AddressValidator::Usps]],
    'FedEx method, inactive FedEx account' => [[Carrier::FEDEX], false, false, [AddressValidator::Usps]],
    'Amazon method, no FedEx account' => [[], true, null, [AddressValidator::Usps]],
]);

it('does not count an inactive FedEx service', function (): void {
    planScope(planFedexAccount(), ['client' => $this->client]);
    $method = planMethod([Carrier::FEDEX]);
    $method->carrierServices()->update(['active' => false]);

    $shipment = Shipment::factory()->create(['client_id' => $this->client->id, 'shipping_method_id' => $method->id]);

    expect(plannedValidators($shipment))->toBe([AddressValidator::Usps]);
});

it('does not count a listed FedEx service the method cannot buy directly', function (bool $directAllowed, bool $fedexActive): void {
    planScope(planFedexAccount(), ['client' => $this->client]);
    $method = planMethod([Carrier::FEDEX]);

    if (! $directAllowed) {
        $method->postageSources()->where('source_kind', PostageSourceKind::Direct)->delete();
    }

    Carrier::where('name', Carrier::FEDEX)->update(['active' => $fedexActive]);

    $shipment = Shipment::factory()->create(['client_id' => $this->client->id, 'shipping_method_id' => $method->id]);

    expect(plannedValidators($shipment))->toBe([AddressValidator::Usps]);
})->with([
    'direct postage off' => [false, true],
    'FedEx carrier inactive' => [true, false],
]);

it('still counts Amazon Buy Shipping with direct postage off', function (): void {
    planScope(planFedexAccount(), ['client' => $this->client]);
    $method = planMethod([Carrier::USPS], amazon: true);
    $method->postageSources()->where('source_kind', PostageSourceKind::Direct)->delete();

    $shipment = Shipment::factory()->create(['client_id' => $this->client->id, 'shipping_method_id' => $method->refresh()->id]);

    expect(plannedValidators($shipment))->toBe([AddressValidator::Fedex, AddressValidator::Usps]);
});

it('does not count another Client\'s FedEx account', function (): void {
    planScope(planFedexAccount(), ['client' => Client::factory()->create()]);

    $shipment = Shipment::factory()->create([
        'client_id' => $this->client->id,
        'shipping_method_id' => planMethod([Carrier::FEDEX])->id,
    ]);

    expect(plannedValidators($shipment))->toBe([AddressValidator::Usps]);
});

// --- Settings ---------------------------------------------------------------------

it('follows the fake-carrier, demo, sandbox and Google settings', function (
    array $settings,
    bool $fakeCarriers,
    bool $demo,
    array $expected,
): void {
    planScope(planFedexAccount(), ['client' => $this->client]);

    foreach ($settings as $key => $value) {
        app(SettingsService::class)->set($key, $value);
    }

    config(['app.fake_carriers' => $fakeCarriers]);

    if ($demo) {
        $this->app['env'] = 'demo';
    }

    $shipment = Shipment::factory()->create([
        'client_id' => $this->client->id,
        'shipping_method_id' => planMethod([Carrier::FEDEX])->id,
    ]);

    expect(plannedValidators($shipment))->toBe($expected);
})->with([
    'production, Google off' => [[], false, false, [AddressValidator::Fedex, AddressValidator::Usps]],
    'production, Google on' => [['address_validation_google_enabled' => true], false, false, [AddressValidator::Fedex, AddressValidator::Usps, AddressValidator::Google]],
    'fake carriers' => [['address_validation_google_enabled' => true], true, false, [AddressValidator::Fake]],
    'demo, even with real validation in sandbox' => [['sandbox_mode' => true, 'address_validation_use_real_in_sandbox' => true], false, true, [AddressValidator::Fake]],
    'sandbox' => [['sandbox_mode' => true], false, false, [AddressValidator::Fake]],
    'sandbox with real validation' => [['sandbox_mode' => true, 'address_validation_use_real_in_sandbox' => true], false, false, [AddressValidator::Fedex, AddressValidator::Usps]],
    'sandbox with real validation, Google on' => [['sandbox_mode' => true, 'address_validation_use_real_in_sandbox' => true, 'address_validation_google_enabled' => true], false, false, [AddressValidator::Fedex, AddressValidator::Usps, AddressValidator::Google]],
]);

// --- Which FedEx account counts -------------------------------------------------------

it('counts a FedEx account for a located Shipment only where label purchase would find one', function (
    ?bool $atItsLocation,
    bool $forItsClient,
    bool $eligible,
): void {
    $location = Location::factory()->create();

    planScope(planFedexAccount(), [
        'location' => $atItsLocation === null ? null : ($atItsLocation ? $location : Location::factory()->create()),
        'client' => $forItsClient ? $this->client : null,
    ]);

    $shipment = Shipment::factory()->create([
        'client_id' => $this->client->id,
        'location_id' => $location->id,
        'shipping_method_id' => planMethod([Carrier::FEDEX])->id,
    ]);

    expect(app(ShipmentValidationPlan::class)->fedexMayValidate($shipment))->toBe($eligible);
})->with([
    'its location' => [true, false, true],
    'its location, for its Client' => [true, true, true],
    'another location' => [false, false, false],
    'another location, for its Client' => [false, true, false],
    'client-wide' => [null, true, true],
    'global' => [null, false, true],
]);

it('counts any of its Client\'s FedEx accounts for an unlocated Shipment', function (
    bool $locationScoped,
    ?bool $forItsClient,
    bool $eligible,
): void {
    planScope(planFedexAccount(), [
        'location' => $locationScoped ? Location::factory()->create() : null,
        'client' => $forItsClient === null ? null : ($forItsClient ? $this->client : Client::factory()->create()),
    ]);

    $shipment = Shipment::factory()->create([
        'client_id' => $this->client->id,
        'location_id' => null,
        'shipping_method_id' => planMethod([Carrier::FEDEX])->id,
    ]);

    expect(app(ShipmentValidationPlan::class)->fedexMayValidate($shipment))->toBe($eligible);
})->with([
    'location-scoped, for its Client' => [true, true, true],
    'location default' => [true, null, true],
    'location-scoped, for another Client' => [true, false, false],
    'client-wide' => [false, true, true],
    'global' => [false, null, true],
]);

it('picks an unlocated Shipment\'s FedEx account deterministically', function (): void {
    $fedex = Carrier::firstOrCreate(['name' => Carrier::FEDEX])->id;
    $shipment = Shipment::factory()->create(['client_id' => $this->client->id, 'location_id' => null]);

    planScope($global = planFedexAccount());
    planScope($sharedAtLocation = planFedexAccount(), ['location' => Location::factory()->create()]);
    expect(CarrierAccount::resolveForAddressValidation($fedex, $shipment)->is($global))->toBeTrue();

    planScope($clientAtLocationB = planFedexAccount(), ['location' => Location::factory()->create(), 'client' => $this->client]);
    planScope(planFedexAccount(), ['location' => Location::factory()->create(), 'client' => $this->client]);
    expect(CarrierAccount::resolveForAddressValidation($fedex, $shipment)->is($clientAtLocationB))->toBeTrue();

    planScope($clientWide = planFedexAccount(), ['client' => $this->client]);
    expect(CarrierAccount::resolveForAddressValidation($fedex, $shipment)->is($clientWide))->toBeTrue();
});

// --- Through the service ----------------------------------------------------------------

function planFedexResponse(array $attributes): MockResponse
{
    return MockResponse::make(['output' => ['resolvedAddresses' => [[
        'streetLinesToken' => ['7372 PARKRIDGE BLVD'],
        'city' => 'IRVING',
        'stateOrProvinceCode' => 'TX',
        'countryCode' => 'US',
        'parsedPostalCode' => ['base' => '75063', 'addOn' => '8659'],
        'classification' => 'RESIDENTIAL',
        'attributes' => $attributes,
    ]]]]);
}

/**
 * USPS and Google configured, so the whole chain can run.
 */
function planEnableFallbacks(): void
{
    createUspsAccount();
    config(['services.google_address_validation.api_key' => 'test-google-key']);
    app(SettingsService::class)->set('address_validation_google_enabled', true);
}

function planFedexEligibleShipment(Client $client): Shipment
{
    planEnableFallbacks();
    planScope(createFedexAccount(), ['client' => $client]);

    return Shipment::factory()->create([
        'client_id' => $client->id,
        'country' => 'US',
        'shipping_method_id' => planMethod([Carrier::FEDEX])->id,
    ]);
}

it('sends no USPS or Google request when FedEx settles the address', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexValidateAddress::class => planFedexResponse(['Resolved' => 'true', 'DPV' => 'true']),
        UspsAddress::class => MockResponse::make([], 500),
        GoogleValidateAddress::class => MockResponse::make([], 500),
    ]);

    $shipment = planFedexEligibleShipment($this->client);
    app(AddressValidationService::class)->validate($shipment);

    Saloon::assertSent(FedexValidateAddress::class);
    Saloon::assertNotSent(UspsAddress::class);
    Saloon::assertNotSent(GoogleValidateAddress::class);

    $shipment->refresh();
    expect($shipment->validation_source)->toBe(AddressValidator::Fedex)
        ->and($shipment->deliverability)->toBe(Deliverability::Yes);
});

it('falls through to USPS and then Google when FedEx is inconclusive', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexValidateAddress::class => planFedexResponse(['Resolved' => 'false']),
        UspsAddress::class => MockResponse::make(['error' => ['message' => 'Address Not Found.']]),
        GoogleValidateAddress::class => MockResponse::make([
            'result' => [
                'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
                'address' => [
                    'postalAddress' => ['addressLines' => ['7372 Parkridge Blvd'], 'locality' => 'Irving', 'administrativeArea' => 'TX', 'postalCode' => '75063'],
                    'addressComponents' => [['componentType' => 'street_number', 'confirmationLevel' => 'CONFIRMED']],
                ],
                'metadata' => ['business' => false, 'poBox' => false, 'residential' => true],
            ],
        ]),
    ]);

    $shipment = planFedexEligibleShipment($this->client);
    app(AddressValidationService::class)->validate($shipment);

    $answered = AddressValidationAnswer::where('shipment_id', $shipment->id)->orderBy('id')->pluck('validator')->all();

    expect($answered)->toBe([AddressValidator::Fedex, AddressValidator::Usps, AddressValidator::Google])
        ->and($shipment->refresh()->validation_source)->toBe(AddressValidator::Google);
});

it('never sends a FedEx request for a Shipment with no shipping method', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        UspsAddress::class => MockResponse::make(['error' => ['message' => 'Address Not Found.']]),
        GoogleValidateAddress::class => MockResponse::make([], 500),
    ]);

    planEnableFallbacks();
    planScope(createFedexAccount(), ['client' => $this->client]);
    $shipment = Shipment::factory()->withoutShippingMethod()->create(['client_id' => $this->client->id, 'country' => 'US']);

    app(AddressValidationService::class)->validate($shipment);

    Saloon::assertNotSent(FedexValidateAddress::class);
    Saloon::assertSent(UspsAddress::class);
});

it('authenticates FedEx with the account the plan found', function (): void {
    $location = Location::factory()->create();
    planScope(createFedexAccount([], ['account_number' => 'elsewhere']), ['location' => Location::factory()->create()]);
    $here = planFedexAccount();
    planScope($here, ['location' => $location]);

    $shipment = Shipment::factory()->create([
        'client_id' => $this->client->id,
        'location_id' => $location->id,
        'shipping_method_id' => planMethod([Carrier::FEDEX])->id,
    ]);

    $validator = new class extends FedexAddressValidator
    {
        public function accountFor(Shipment $shipment): ?CarrierAccount
        {
            return $this->resolveAccount($shipment);
        }
    };

    expect($validator->accountFor($shipment)?->is($here))->toBeTrue();
});

// --- International ------------------------------------------------------------------

it('places FedEx by the Shipment\'s country', function (string $country, bool $eligible, bool $google, array $expected): void {
    if ($google) {
        app(SettingsService::class)->set('address_validation_google_enabled', true);
    }

    if ($eligible) {
        planScope(planFedexAccount(), ['client' => $this->client]);
    }

    $shipment = Shipment::factory()->create([
        'client_id' => $this->client->id,
        'country' => $country,
        'shipping_method_id' => planMethod([Carrier::FEDEX])->id,
    ]);

    expect(plannedValidators($shipment))->toBe($expected);
})->with([
    'trusted, eligible' => ['DE', true, true, [AddressValidator::Fedex, AddressValidator::Google]],
    'trusted, not eligible' => ['DE', false, true, [AddressValidator::Google]],
    'trusted, eligible, Google off' => ['DE', true, false, [AddressValidator::Fedex]],
    'excluded, eligible' => ['BE', true, true, [AddressValidator::Google]],
    'excluded, not eligible' => ['BE', false, true, [AddressValidator::Google]],
    'Google unsupported, eligible' => ['HK', true, true, [AddressValidator::Fedex]],
    'Google unsupported, not eligible' => ['HK', false, true, []],
    'unlisted, eligible' => ['JP', true, true, [AddressValidator::Google]],
    'unlisted, not eligible' => ['JP', false, true, [AddressValidator::Google]],
    'Puerto Rico, eligible' => ['PR', true, true, [AddressValidator::Fedex, AddressValidator::Google]],
]);

it('keeps every country list entry cited and the lists apart', function (): void {
    $lists = [
        AddressValidationCountries::FEDEX_TRUSTED,
        AddressValidationCountries::FEDEX_EXCLUDED,
        AddressValidationCountries::GOOGLE_UNSUPPORTED,
    ];

    foreach ($lists as $list) {
        foreach ($list as $country => $citation) {
            expect($country)->toMatch('/^[A-Z]{2}$/')
                ->and($citation)->not->toBeEmpty();
        }
    }

    expect(array_intersect_key(AddressValidationCountries::FEDEX_TRUSTED, AddressValidationCountries::FEDEX_EXCLUDED))->toBe([])
        ->and(array_intersect_key(AddressValidationCountries::FEDEX_TRUSTED, AddressValidationCountries::GOOGLE_UNSUPPORTED))->toBe([])
        ->and(array_intersect_key(AddressValidationCountries::FEDEX_EXCLUDED, AddressValidationCountries::GOOGLE_UNSUPPORTED))->toBe([]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function planInternationalFedexResponse(string $street, array $attributes = []): MockResponse
{
    return MockResponse::make(['output' => ['resolvedAddresses' => [[
        'streetLinesToken' => [$street],
        'city' => 'BEISPIELHAUSEN',
        'postalCode' => '12345',
        'parsedPostalCode' => ['base' => '12345'],
        'classification' => 'UNKNOWN',
        'resolutionMethodName' => 'GAM_VALIDATE',
        'attributes' => ['Matched' => 'true', 'StreetAddress' => 'true', 'AddressPrecision' => 'STREET_ADDRESS', ...$attributes],
    ]]]]);
}

function planGoogleVerifiedResponse(): MockResponse
{
    return MockResponse::make([
        'result' => [
            'verdict' => ['addressComplete' => true, 'hasUnconfirmedComponents' => false],
            'address' => [
                'postalAddress' => ['addressLines' => ['Musterweg 12'], 'locality' => 'Beispielhausen', 'postalCode' => '12345'],
                'addressComponents' => [['componentType' => 'street_number', 'confirmationLevel' => 'CONFIRMED']],
            ],
            'metadata' => ['business' => false, 'poBox' => false, 'residential' => true],
        ],
    ]);
}

function planInternationalShipment(Client $client, string $country): Shipment
{
    planEnableFallbacks();
    planScope(createFedexAccount(), ['client' => $client]);

    return Shipment::factory()->create([
        'client_id' => $client->id,
        'address1' => 'Musterweg 12',
        'country' => $country,
        'state_or_province' => null,
        'shipping_method_id' => planMethod([Carrier::FEDEX])->id,
    ]);
}

it('falls through to Google when FedEx can\'t settle a trusted country\'s address', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexValidateAddress::class => planInternationalFedexResponse('MUSTERWEG 829'),
        UspsAddress::class => MockResponse::make([], 500),
        GoogleValidateAddress::class => planGoogleVerifiedResponse(),
    ]);

    $shipment = planInternationalShipment($this->client, 'DE');
    app(AddressValidationService::class)->validate($shipment);

    $answers = AddressValidationAnswer::where('shipment_id', $shipment->id)->orderBy('id')->get();

    Saloon::assertNotSent(UspsAddress::class);
    expect($answers->pluck('validator')->all())->toBe([AddressValidator::Fedex, AddressValidator::Google])
        ->and($answers->first()->reason)->toBe(ValidationReason::HouseNumberChanged)
        ->and($shipment->refresh()->validation_source)->toBe(AddressValidator::Google);
});

it('settles a trusted country\'s address with FedEx alone', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexValidateAddress::class => planInternationalFedexResponse('MUSTERWEG 12'),
        GoogleValidateAddress::class => MockResponse::make([], 500),
    ]);

    $shipment = planInternationalShipment($this->client, 'DE');
    app(AddressValidationService::class)->validate($shipment);

    Saloon::assertNotSent(GoogleValidateAddress::class);
    $shipment->refresh();
    expect($shipment->validation_source)->toBe(AddressValidator::Fedex)
        ->and($shipment->deliverability)->toBe(Deliverability::Verified);
});

it('reaches FedEx where Google doesn\'t support the country', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexValidateAddress::class => planInternationalFedexResponse('MUSTERWEG 12'),
        GoogleValidateAddress::class => MockResponse::make([], 500),
    ]);

    $shipment = planInternationalShipment($this->client, 'HK');
    app(AddressValidationService::class)->validate($shipment);

    Saloon::assertNotSent(GoogleValidateAddress::class);
    Saloon::assertSent(FedexValidateAddress::class);
    expect($shipment->refresh()->validation_source)->toBe(AddressValidator::Fedex);
});

it('never sends a FedEx request for an excluded country', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexValidateAddress::class => planInternationalFedexResponse('MUSTERWEG 12'),
        GoogleValidateAddress::class => planGoogleVerifiedResponse(),
    ]);

    $shipment = planInternationalShipment($this->client, 'BE');
    app(AddressValidationService::class)->validate($shipment);

    Saloon::assertNotSent(FedexValidateAddress::class);
    expect($shipment->refresh()->validation_source)->toBe(AddressValidator::Google);
});
