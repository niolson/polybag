<?php

use App\Contracts\AddressValidationInterface;
use App\DataTransferObjects\AddressValidationResult;
use App\Enums\AddressValidationOutcome;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\ValidationReason;
use App\Enums\ValidationTrigger;
use App\Http\Integrations\Fedex\Requests\ValidateAddress as FedexValidateAddress;
use App\Http\Integrations\Google\Requests\ValidateAddress as GoogleValidateAddress;
use App\Http\Integrations\USPS\Requests\Address as UspsAddress;
use App\Jobs\ShadowValidateAddress;
use App\Models\AddressValidationAnswer;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Services\AddressValidationService;
use App\Services\Validation\FakeAddressValidator;
use App\Services\Validation\FedexAddressValidator;
use App\Services\Validation\GoogleAddressValidator;
use App\Services\Validation\UspsAddressValidator;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\FixedValidationPlan;

beforeEach(function (): void {
    createUspsAccount();
    createFedexAccount();
    config([
        'services.google_address_validation.api_key' => 'test-google-key',
        'services.fedex.shadow_address_validation' => true,
    ]);
});

function shadowMethod(string $carrier = Carrier::FEDEX): ShippingMethod
{
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach(CarrierService::factory()->create([
        'carrier_id' => Carrier::firstOrCreate(['name' => $carrier])->id,
    ]));

    return $method;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function shadowShipment(array $attributes = []): Shipment
{
    return Shipment::factory()->create([
        'address1' => '1600 Pennsylvania Ave NW',
        'address2' => null,
        'city' => 'Washington',
        'state_or_province' => 'DC',
        'postal_code' => '20500',
        'country' => 'US',
        'shipping_method_id' => shadowMethod()->id,
        ...$attributes,
    ]);
}

function shadowUspsMatch(): MockResponse
{
    return MockResponse::make([
        'matches' => [['code' => '31']],
        'address' => [
            'streetAddress' => '1600 PENNSYLVANIA AVE NW',
            'city' => 'WASHINGTON',
            'state' => 'DC',
            'ZIPCode' => '20500',
            'ZIPPlus4' => '0005',
        ],
        'additionalInfo' => ['DPVConfirmation' => 'Y', 'carrierRoute' => 'C001', 'business' => 'Y'],
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function shadowFedexResolved(array $overrides = []): MockResponse
{
    return MockResponse::make(['output' => ['resolvedAddresses' => [array_replace_recursive([
        'streetLinesToken' => ['1600 PENNSYLVANIA AVE NW'],
        'city' => 'WASHINGTON',
        'stateOrProvinceCode' => 'DC',
        'countryCode' => 'US',
        'parsedPostalCode' => ['base' => '20500', 'addOn' => '0005'],
        'classification' => 'BUSINESS',
        'attributes' => ['Resolved' => 'true', 'DPV' => 'true', 'Matched' => 'true'],
    ], $overrides)]]]);
}

function fakeShadowApis(MockResponse|Closure $fedex, ?MockResponse $usps = null): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        UspsAddress::class => $usps ?? shadowUspsMatch(),
        FedexValidateAddress::class => $fedex,
        GoogleValidateAddress::class => MockResponse::make(['result' => [
            'verdict' => ['addressComplete' => true, 'validationGranularity' => 'PREMISE'],
            'address' => ['postalAddress' => ['addressLines' => ['Rue de la Loi 16'], 'locality' => 'Bruxelles', 'postalCode' => '1000']],
        ]]),
    ]);
}

/**
 * @param  list<AddressValidationInterface>  $validators
 */
function shadowService(array $validators = []): AddressValidationService
{
    return new AddressValidationService(new FixedValidationPlan($validators ?: [new UspsAddressValidator]));
}

function shadowAnswer(Shipment $shipment): ?AddressValidationAnswer
{
    return $shipment->validationAnswers()->where('shadow', true)->first();
}

// --- The shadow answer ---------------------------------------------------------------

it('asks FedEx after USPS settles a FedEx-eligible Shipment and pairs the answers', function (): void {
    fakeShadowApis(shadowFedexResolved());
    $shipment = shadowShipment();

    shadowService()->validate($shipment, ValidationTrigger::Scheduled);

    $live = $shipment->validationAnswers()->live()->sole();
    $shadow = shadowAnswer($shipment);

    expect($live->validator)->toBe(AddressValidator::Usps)
        ->and($shadow->validator)->toBe(AddressValidator::Fedex)
        ->and($shadow->shadows_answer_id)->toBe($live->id)
        ->and($shadow->outcome)->toBe(AddressValidationOutcome::Settled)
        ->and($shadow->deliverability)->toBe(Deliverability::Yes)
        ->and($shadow->trigger)->toBe(ValidationTrigger::Scheduled)
        ->and($shadow->paid)->toBeFalse()
        ->and($shadow->only(['street_differs', 'house_number_differs', 'city_differs', 'postcode_differs']))
        ->toBe(['street_differs' => false, 'house_number_differs' => false, 'city_differs' => false, 'postcode_differs' => false]);
    Saloon::mockClient()->assertSentCount(1, FedexValidateAddress::class);
});

it('leaves the Shipment exactly as the settled result left it', function (): void {
    fakeShadowApis(shadowFedexResolved([
        'streetLinesToken' => ['1700 PENNSYLVANIA AVE NW'],
        'classification' => 'RESIDENTIAL',
        'attributes' => ['InvalidSuiteNumber' => 'true'],
    ]));
    $shipment = shadowShipment();

    Queue::fake();
    shadowService()->validate($shipment);
    $before = $shipment->fresh()->getAttributes();

    (new ShadowValidateAddress($shipment->validationAnswers()->sole()->id))->handle();

    expect(shadowAnswer($shipment)->deliverability)->toBe(Deliverability::Partial)
        ->and($shipment->fresh()->getAttributes())->toBe($before);
});

it('flags which parts of the address FedEx returned differently', function (array $fedex, array $expected): void {
    fakeShadowApis(shadowFedexResolved($fedex));
    $shipment = shadowShipment();

    shadowService()->validate($shipment);

    expect(shadowAnswer($shipment)->only(array_keys($expected)))->toBe($expected);
})->with([
    'case, spacing and ZIP+4 aside' => [
        ['streetLinesToken' => ['1600  Pennsylvania Ave. NW'], 'city' => 'washington', 'parsedPostalCode' => ['addOn' => null]],
        ['street_differs' => false, 'house_number_differs' => false, 'city_differs' => false, 'postcode_differs' => false],
    ],
    'a different house number' => [
        ['streetLinesToken' => ['1700 PENNSYLVANIA AVE NW']],
        ['street_differs' => true, 'house_number_differs' => true, 'city_differs' => false, 'postcode_differs' => false],
    ],
    'an abbreviation spelled out' => [
        ['streetLinesToken' => ['1600 PENNSYLVANIA AVENUE NW']],
        ['street_differs' => true, 'house_number_differs' => false, 'city_differs' => false, 'postcode_differs' => false],
    ],
    'a different city and ZIP' => [
        ['city' => 'ARLINGTON', 'parsedPostalCode' => ['base' => '22201']],
        ['street_differs' => false, 'house_number_differs' => false, 'city_differs' => true, 'postcode_differs' => true],
    ],
]);

it('logs an inconclusive FedEx answer with its reason and no comparison', function (): void {
    fakeShadowApis(shadowFedexResolved(['attributes' => ['DPV' => 'false']]));
    $shipment = shadowShipment();

    shadowService()->validate($shipment);

    $shadow = shadowAnswer($shipment);

    expect($shadow->outcome)->toBe(AddressValidationOutcome::Inconclusive)
        ->and($shadow->reason)->toBe(ValidationReason::NotDeliveryPoint)
        ->and($shadow->deliverability)->toBeNull()
        ->and($shadow->street_differs)->toBeNull()
        ->and($shadow->postcode_differs)->toBeNull()
        ->and($shipment->fresh()->deliverability)->toBe(Deliverability::Yes);
});

it('writes no row and leaves the Shipment alone when FedEx is unavailable or throws', function (string $failure): void {
    fakeShadowApis(match ($failure) {
        'server error' => MockResponse::make(['errors' => [['message' => 'down']]], 503),
        'connection failure' => fn (): MockResponse => throw new RuntimeException('connection reset'),
        'unexpected exception' => fn (): MockResponse => throw new LogicException('bug'),
    });
    $shipment = shadowShipment();

    shadowService()->validate($shipment);

    expect(shadowAnswer($shipment))->toBeNull()
        ->and($shipment->fresh()->validation_source)->toBe(AddressValidator::Usps)
        ->and($shipment->fresh()->deliverability)->toBe(Deliverability::Yes);
})->with(['server error', 'connection failure', 'unexpected exception']);

it('shadow-checks a Shipment in a country FedEx is never asked about live', function (): void {
    fakeShadowApis(MockResponse::make(['output' => ['resolvedAddresses' => [[
        'streetLinesToken' => ['RUE DE LA LOI 16'],
        'city' => 'BRUXELLES',
        'postalCode' => '1000',
        'attributes' => ['Matched' => 'true', 'StreetAddress' => 'true'],
    ]]]]));
    $shipment = shadowShipment([
        'address1' => 'Rue de la Loi 16',
        'city' => 'Bruxelles',
        'state_or_province' => null,
        'postal_code' => '1000',
        'country' => 'BE',
    ]);

    shadowService([new GoogleAddressValidator])->validate($shipment);

    expect($shipment->validationAnswers()->live()->sole()->validator)->toBe(AddressValidator::Google)
        ->and(shadowAnswer($shipment)->deliverability)->toBe(Deliverability::Verified)
        ->and(shadowAnswer($shipment)->street_differs)->toBeFalse();
});

// --- When it runs ------------------------------------------------------------------

it('dispatches no shadow check', function (Closure $arrange, Closure $validators): void {
    Queue::fake();
    fakeShadowApis(shadowFedexResolved(['attributes' => ['DPV' => 'false']]));
    $shipment = shadowShipment();
    $arrange($shipment);

    shadowService($validators())->validate($shipment);

    Queue::assertNotPushed(ShadowValidateAddress::class);
})->with([
    'with the switch off' => [
        fn () => config(['services.fedex.shadow_address_validation' => false]),
        fn (): array => [new UspsAddressValidator],
    ],
    'when the method cannot buy FedEx' => [
        fn (Shipment $shipment) => $shipment->update(['shipping_method_id' => shadowMethod(Carrier::UPS)->id]),
        fn (): array => [new UspsAddressValidator],
    ],
    'when FedEx answered in the run' => [
        fn () => null,
        fn (): array => [new FedexAddressValidator, new UspsAddressValidator],
    ],
    'when the fake validator settled it' => [
        fn () => null,
        fn (): array => [new FakeAddressValidator],
    ],
    'when no validator settled it' => [
        fn () => null,
        fn (): array => [new class implements AddressValidationInterface
        {
            public function validator(): AddressValidator
            {
                return AddressValidator::Usps;
            }

            public function supports(string $country): bool
            {
                return true;
            }

            public function validate(Shipment $shipment): AddressValidationResult
            {
                return AddressValidationResult::inconclusive(ValidationReason::NoMatch);
            }
        }],
    ],
]);

it('queues the shadow check on the low queue', function (): void {
    Queue::fake();
    fakeShadowApis(shadowFedexResolved());

    shadowService()->validate(shadowShipment());

    Queue::assertPushedOn('low', ShadowValidateAddress::class);
});

it('does nothing once the result it shadows is no longer current', function (Closure $change): void {
    Queue::fake();
    fakeShadowApis(shadowFedexResolved());
    $shipment = shadowShipment();
    shadowService()->validate($shipment);
    $answerId = $shipment->validationAnswers()->sole()->id;

    $change($shipment->fresh());
    (new ShadowValidateAddress($answerId))->handle();

    expect(shadowAnswer($shipment))->toBeNull();
    Saloon::assertNotSent(FedexValidateAddress::class);
})->with([
    'readdressed' => [fn (Shipment $shipment) => $shipment->update(['address1' => '1 Infinite Loop'])],
    're-validated' => [fn (Shipment $shipment) => shadowService()->validate($shipment)],
    'deleted' => [fn (Shipment $shipment) => $shipment->delete()],
]);

it('queues the job only once the validating transaction commits', function (): void {
    Queue::fake();
    fakeShadowApis(shadowFedexResolved());

    shadowService()->validate(shadowShipment());

    Queue::assertPushed(ShadowValidateAddress::class, fn (ShadowValidateAddress $job): bool => $job->afterCommit === true);
});

it('rechecks eligibility when the job runs', function (Closure $change): void {
    Queue::fake();
    fakeShadowApis(shadowFedexResolved());
    $shipment = shadowShipment();
    shadowService()->validate($shipment);
    $answerId = $shipment->validationAnswers()->sole()->id;

    $change($shipment->fresh());
    (new ShadowValidateAddress($answerId))->handle();

    expect(shadowAnswer($shipment))->toBeNull();
    Saloon::assertNotSent(FedexValidateAddress::class);
})->with([
    'method no longer buys FedEx' => [fn (Shipment $shipment) => $shipment->update(['shipping_method_id' => shadowMethod(Carrier::UPS)->id])],
    'switch turned off' => [fn () => config(['services.fedex.shadow_address_validation' => false])],
]);

it('carries an address edit made during the FedEx request onto the shadow answer', function (): void {
    Queue::fake();
    $shipment = shadowShipment();
    fakeShadowApis(function () use ($shipment): MockResponse {
        $shipment->fresh()->update(['address1' => '1 Infinite Loop']);

        return shadowFedexResolved();
    }, shadowUspsMatch());
    shadowService()->validate($shipment);
    $settled = $shipment->validationAnswers()->sole();

    (new ShadowValidateAddress($settled->id))->handle();

    expect($settled->refresh()->address_changed_at)->not->toBeNull()
        ->and(shadowAnswer($shipment)->address_changed_at?->toDateTimeString())
        ->toBe($settled->address_changed_at->toDateTimeString());
});

it('writes one row when the job runs twice', function (): void {
    Queue::fake();
    fakeShadowApis(shadowFedexResolved());
    $shipment = shadowShipment();
    shadowService()->validate($shipment);
    $answerId = $shipment->validationAnswers()->sole()->id;

    (new ShadowValidateAddress($answerId))->handle();
    (new ShadowValidateAddress($answerId))->handle();

    expect($shipment->validationAnswers()->where('shadow', true)->count())->toBe(1);
    Saloon::mockClient()->assertSentCount(1, FedexValidateAddress::class);
});

// --- Address edits and the live scope -----------------------------------------------

it('stamps an address edit on the Shipment\'s answers, keeping the first stamp', function (): void {
    $shipment = shadowShipment();
    $earlier = AddressValidationAnswer::factory()->create([
        'shipment_id' => $shipment->id,
        'address_changed_at' => now()->subDay(),
    ]);
    $current = AddressValidationAnswer::factory()->create(['shipment_id' => $shipment->id]);
    $shadow = AddressValidationAnswer::factory()->shadowOf($current)->create();
    $other = AddressValidationAnswer::factory()->create();

    $shipment->update(['first_name' => 'Renamed']);
    expect($current->refresh()->address_changed_at)->toBeNull();

    $shipment->update(['address1' => '1 Infinite Loop']);

    expect($current->refresh()->address_changed_at)->not->toBeNull()
        ->and($shadow->refresh()->address_changed_at)->not->toBeNull()
        ->and($earlier->refresh()->address_changed_at->isSameDay(now()->subDay()))->toBeTrue()
        ->and($other->refresh()->address_changed_at)->toBeNull();
});

it('leaves shadow answers out of the live scope', function (): void {
    $live = AddressValidationAnswer::factory()->create();
    AddressValidationAnswer::factory()->shadowOf($live)->create();

    expect(AddressValidationAnswer::live()->pluck('id')->all())->toBe([$live->id]);
});
