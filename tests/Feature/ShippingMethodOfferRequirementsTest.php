<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\OtdrProtectedOrders;
use App\Enums\PackageStatus;
use App\Enums\SourceEnvironment;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| amazon-buy-shipping/16, 17 — on-time and OTDR-protection requirements
|--------------------------------------------------------------------------
|
| The shipping method decides what automation insists on: the due-by date for
| every order on it, OTDR protection for the kinds of Amazon order ticked.
|
*/

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

function requirementsRate(float $price, ?string $deliveryDate, ?bool $otdrProtected = null, array $reasonCodes = []): RateResponse
{
    return new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: 'GROUND',
        serviceName: 'Ground',
        price: $price,
        deliveryDate: $deliveryDate,
        metadata: $otdrProtected === null ? [] : ['benefits' => [
            'includedBenefits' => $otdrProtected ? ['OTDR_PROTECTED'] : [],
            'excludedBenefits' => $otdrProtected ? [] : [['benefit' => 'OTDR_PROTECTED', 'reasonCodes' => $reasonCodes]],
        ]],
    );
}

/**
 * @param  array<int, RateResponse>  $rates
 */
function registerRequirementsAdapter(array $rates): void
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect($rates));
    $adapter->shouldReceive('createShipment')->andReturnUsing(fn ($request): ShipResponse => ShipResponse::success(
        trackingNumber: 'REQ123',
        cost: 5.00,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

/**
 * @param  array<string, mixed>  $method  Shipping method attributes
 * @param  list<string>|null  $programs  The Amazon order's `amazon_programs`
 */
function packageForOrderFrom(
    ?DataSource $connection,
    ?Carbon $deliverBy = new Carbon('tomorrow'),
    array $method = [],
    ?array $programs = null,
): Package {
    $carrier = Carrier::firstOrCreate(['name' => 'MockCarrier'], Carrier::factory()->raw(['name' => 'MockCarrier', 'active' => true]));
    $carrierService = CarrierService::firstOrCreate(
        ['carrier_id' => $carrier->id, 'service_code' => 'GROUND'],
        CarrierService::factory()->raw(['carrier_id' => $carrier->id, 'name' => 'Ground', 'service_code' => 'GROUND', 'active' => true]),
    );
    $shippingMethod = ShippingMethod::factory()->create(['name' => 'Standard', 'commitment_days' => null, ...$method]);
    $shippingMethod->carrierServices()->attach($carrierService->id);

    $product = Product::factory()->create(['weight' => 1.5]);
    $shipment = Shipment::factory()->create([
        'shipping_method_id' => $shippingMethod->id,
        'data_source_id' => $connection?->id,
        'deliver_by' => $deliverBy,
        'metadata' => $programs === null ? null : ['amazon_programs' => $programs],
    ]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);

    $package->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package;
}

function autoShipForRequirements(Package $package): mixed
{
    return app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: test()->user->id, cleanupOnFailure: false),
    );
}

beforeEach(function (): void {
    $this->actingAs($this->user = User::factory()->create());
});

it('buys nothing for an Amazon order whose rates are all late, and names the shipping method', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(), method: ['name' => 'Two Day']);
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::parse('+5 days')->toDateString()),
        requirementsRate(4.00, null),
    ]);

    $result = autoShipForRequirements($package);

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('No On-Time Rates')
        ->and($result->message)->toContain('The shipping method "Two Day" requires a rate that arrives by the due-by date')
        ->and($result->message)->toContain('change the requirement on the shipping method')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('buys the cheapest late rate when the method does not exclude late rates', function (DataSource $connection): void {
    $package = packageForOrderFrom($connection, method: ['excludes_late_rates' => false]);
    registerRequirementsAdapter([requirementsRate(6.00, Carbon::parse('+5 days')->toDateString())]);

    expect(autoShipForRequirements($package)->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
})->with([
    'Amazon order' => fn (): DataSource => DataSource::factory()->amazon()->create(),
    'Shopify order' => fn (): DataSource => DataSource::factory()->shopify()->create(),
]);

it('leaves a Shopify order for a person when every rate arrives after its method\'s commitment', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->shopify()->create(), deliverBy: null, method: ['name' => 'Overnight', 'commitment_days' => 1]);
    registerRequirementsAdapter([requirementsRate(6.00, Carbon::today()->addWeekdays(3)->toDateString())]);

    $result = autoShipForRequirements($package);

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('No On-Time Rates')
        ->and($result->message)->toContain('"Overnight"')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('still buys the cheapest rate for a Shopify order with no due-by date', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->shopify()->create(), deliverBy: null);
    registerRequirementsAdapter([requirementsRate(6.00, Carbon::today()->toDateString()), requirementsRate(4.00, null)]);

    $result = autoShipForRequirements($package);

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('requires protection only for the kinds of Amazon order the method ticks', function (?array $programs, bool $refused): void {
    $package = packageForOrderFrom(
        DataSource::factory()->amazon()->create(),
        method: ['otdr_protection_orders' => [OtdrProtectedOrders::Prime]],
        programs: $programs,
    );
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['NON_SSA_ORDER']),
    ]);

    $result = autoShipForRequirements($package);

    expect($result->success)->toBe(! $refused)
        ->and($result->title)->toBe($refused ? 'No OTDR-Protected Rates' : $result->title);
})->with([
    'Prime order' => [['PRIME'], true],
    'Prime and Premium order' => [['PRIME', 'PREMIUM'], true],
    'Premium order' => [['PREMIUM'], false],
    'ordinary Amazon order' => [null, false],
    'Ship Plus order' => [['FBM_SHIP_PLUS'], false],
]);

it('refuses unprotected rates for every Amazon order with all three ticked, and leaves a Shopify order alone', function (): void {
    $everything = ['otdr_protection_orders' => OtdrProtectedOrders::cases()];
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['NON_SSA_ORDER']),
        requirementsRate(3.00, Carbon::today()->toDateString()),
    ]);

    foreach ([['PRIME'], ['PREMIUM'], null] as $programs) {
        $result = autoShipForRequirements(packageForOrderFrom(DataSource::factory()->amazon()->create(), method: $everything, programs: $programs));

        expect($result->success)->toBeFalse()
            ->and($result->title)->toBe('No OTDR-Protected Rates')
            ->and($result->message)->toContain('is OTDR-protected');
    }

    expect(autoShipForRequirements(packageForOrderFrom(DataSource::factory()->shopify()->create(), method: $everything))->success)->toBeTrue();
});

it('buys a protected rate that is late when only protection is required', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(), method: [
        'excludes_late_rates' => false,
        'otdr_protection_orders' => [OtdrProtectedOrders::Other],
    ]);
    registerRequirementsAdapter([requirementsRate(6.00, Carbon::parse('+5 days')->toDateString(), otdrProtected: true)]);

    expect(autoShipForRequirements($package)->success)->toBeTrue();
});

it('names both requirements when rates fail each of them', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(), method: ['otdr_protection_orders' => [OtdrProtectedOrders::Other]]);
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::parse('+5 days')->toDateString(), otdrProtected: true),
        requirementsRate(7.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['NON_AHT_ORDER']),
    ]);

    $result = autoShipForRequirements($package);

    expect($result->title)->toBe('No On-Time, Protected Rates')
        ->and($result->message)->toContain('arrives on time and is OTDR-protected');
});

it('marks OTDR protection on the Ship page, with Amazon\'s reasons, whatever the method requires', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create());
    registerRequirementsAdapter([
        requirementsRate(5.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['LATE_DELIVERY_RISK']),
        requirementsRate(9.00, Carbon::parse('+5 days')->toDateString(), otdrProtected: true),
        requirementsRate(4.00, Carbon::today()->toDateString()),
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    $byPrice = collect($options->rateOptions)->keyBy(fn (array $rate): string => (string) $rate['price']);

    expect($options->rateOptions)->toHaveCount(3)
        ->and($byPrice['5']['otdrProtection'])->toBe(['protected' => false, 'reasons' => ['late-delivery risk']])
        ->and($byPrice['9']['otdrProtection'])->toBe(['protected' => true, 'reasons' => []])
        ->and($byPrice['4'])->not->toHaveKey('otdrProtection');

    $lateIndex = collect($options->rateOptions)->search(fn (array $rate): bool => $rate['price'] === 9.00);
    expect($options->rateOptionDescriptions[$lateIndex])->toContain('LATE');
});

it('saves both requirements from the shipping method form', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    DataSource::factory()->amazon()->create(['active' => true]);
    $method = ShippingMethod::factory()->create()->refresh();

    Livewire::test(EditShippingMethod::class, ['record' => $method->id])
        ->assertFormSet(['excludes_late_rates' => true, 'otdr_protection_orders' => []])
        ->assertFormFieldVisible('otdr_protection_orders')
        ->fillForm(['excludes_late_rates' => false, 'otdr_protection_orders' => ['prime', 'other']])
        ->call('save')
        ->assertHasNoFormErrors();

    $method->refresh();

    expect($method->excludes_late_rates)->toBeFalse()
        ->and($method->otdr_protection_orders->all())->toBe([OtdrProtectedOrders::Prime, OtdrProtectedOrders::Other]);
});

it('hides the OTDR choice when no Amazon connection is active, and keeps what was saved', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    DataSource::factory()->amazon()->create(['active' => false]);
    DataSource::factory()->shopify()->create(['active' => true]);
    $method = ShippingMethod::factory()->create(['otdr_protection_orders' => [OtdrProtectedOrders::Prime]]);

    Livewire::test(EditShippingMethod::class, ['record' => $method->id])
        ->assertFormFieldVisible('excludes_late_rates')
        ->assertFormFieldHidden('otdr_protection_orders')
        ->fillForm(['excludes_late_rates' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($method->refresh()->otdr_protection_orders->all())->toBe([OtdrProtectedOrders::Prime]);
});

it('no longer keeps the requirements on the Amazon connection', function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
    Location::factory()->create(['is_default' => true]);
    $this->actingAs(User::factory()->admin()->create());

    $connection = DataSource::factory()->amazon()->importDisabled()->create([
        'secret_settings' => ['refresh_token' => 'form-refresh-token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->assertDontSee('Automated Label Purchase')
        ->assertDontSee('Require OTDR protection');

    expect(Schema::hasColumn('data_sources', 'requires_on_time_offers'))->toBeFalse()
        ->and(Schema::hasColumn('data_sources', 'requires_otdr_protected_offers'))->toBeFalse();
});

it('claims only approved rates fail when an unapproved service was withheld', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create());
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::parse('+5 days')->toDateString()),
        new RateResponse(
            carrier: 'MockCarrier',
            serviceCode: 'GROUND',
            serviceName: 'Ground',
            price: 8.00,
            deliveryDate: Carbon::today()->toDateString(),
            observedService: new ObservedServiceIdentity(
                source: 'amazon',
                environment: SourceEnvironment::Production,
                externalCarrierId: 'UPS',
                externalServiceId: 'UPS_PTP_GND',
            ),
        ),
    ]);

    $result = autoShipForRequirements($package);

    expect($result->title)->toBe('No Approved On-Time Rates')
        ->and($result->message)->toContain('none of the rates approved for automated purchase does')
        ->and($result->message)->toContain('Not approved: MockCarrier Ground (via amazon)')
        ->and($result->message)->not->toContain("none of this package's rates");
});

it('leaves an Amazon order with no due-by date for a person when late rates are excluded', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(), deliverBy: null, method: ['name' => 'Standard']);
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::today()->toDateString()),
        requirementsRate(4.00, null),
    ]);

    expect($package->shipment->getDeliverByDate())->toBeNull();

    $result = autoShipForRequirements($package);

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('No Due-By Date')
        ->and($result->message)->toContain('"Standard" requires on-time delivery')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('still buys for an Amazon order with no due-by date when late rates are not excluded', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(), deliverBy: null, method: ['excludes_late_rates' => false]);
    registerRequirementsAdapter([requirementsRate(4.00, null)]);

    expect(autoShipForRequirements($package)->success)->toBeTrue();
});
