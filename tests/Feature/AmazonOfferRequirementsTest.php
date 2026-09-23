<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\SourceEnvironment;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
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
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| amazon-buy-shipping/16 — on-time and OTDR-protection requirements
|--------------------------------------------------------------------------
|
| An Amazon connection decides what automation insists on for its orders;
| every other order keeps the old fallback to the cheapest late rate.
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

function packageForOrderFrom(?DataSource $connection, ?Carbon $deliverBy = new Carbon('tomorrow')): Package
{
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $carrierService = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Ground',
        'service_code' => 'GROUND',
        'active' => true,
    ]);
    $shippingMethod = ShippingMethod::factory()->create(['commitment_days' => null]);
    $shippingMethod->carrierServices()->attach($carrierService->id);

    $product = Product::factory()->create(['weight' => 1.5]);
    $shipment = Shipment::factory()->create([
        'shipping_method_id' => $shippingMethod->id,
        'data_source_id' => $connection?->id,
        'deliver_by' => $deliverBy,
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

it('buys nothing for an Amazon order whose rates are all late, and says lateness is why', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(['name' => 'US Store']));
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::parse('+5 days')->toDateString()),
        requirementsRate(4.00, null),
    ]);

    $result = autoShipForRequirements($package);

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('No On-Time Rates')
        ->and($result->message)->toContain('"US Store"')
        ->and($result->message)->toContain('arrives by the deliver-by date')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('buys the cheapest late rate for an Amazon order when the connection requires nothing', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(['requires_on_time_offers' => false]));
    registerRequirementsAdapter([requirementsRate(6.00, Carbon::parse('+5 days')->toDateString())]);

    expect(autoShipForRequirements($package)->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('still buys a late rate for an order that is not from Amazon', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->shopify()->create());
    registerRequirementsAdapter([requirementsRate(6.00, Carbon::parse('+5 days')->toDateString())]);

    expect(autoShipForRequirements($package)->success)->toBeTrue();
});

it('refuses unprotected rates when the connection requires OTDR protection, and says so', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create([
        'requires_on_time_offers' => false,
        'requires_otdr_protected_offers' => true,
    ]));
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['NON_SSA_ORDER']),
        requirementsRate(3.00, Carbon::today()->toDateString()),
    ]);

    $result = autoShipForRequirements($package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('No OTDR-Protected Rates')
        ->and($result->message)->toContain('is OTDR-protected');
});

it('buys a protected rate that is late when only protection is required', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create([
        'requires_on_time_offers' => false,
        'requires_otdr_protected_offers' => true,
    ]));
    registerRequirementsAdapter([requirementsRate(6.00, Carbon::parse('+5 days')->toDateString(), otdrProtected: true)]);

    expect(autoShipForRequirements($package)->success)->toBeTrue();
});

it('names both requirements when rates fail each of them', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(['requires_otdr_protected_offers' => true]));
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::parse('+5 days')->toDateString(), otdrProtected: true),
        requirementsRate(7.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['NON_AHT_ORDER']),
    ]);

    $result = autoShipForRequirements($package);

    expect($result->title)->toBe('No On-Time, Protected Rates')
        ->and($result->message)->toContain('arrives on time and is OTDR-protected');
});

it('marks OTDR protection on the Ship page, with Amazon\'s reasons, whatever the connection requires', function (): void {
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

it('saves both requirements from the Amazon connection form', function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
    Location::factory()->create(['is_default' => true]);
    $this->actingAs(User::factory()->admin()->create());

    $connection = DataSource::factory()->amazon()->importDisabled()->create([
        'secret_settings' => ['refresh_token' => 'form-refresh-token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->assertFormSet(['requires_on_time_offers' => true, 'requires_otdr_protected_offers' => false])
        ->fillForm(['requires_on_time_offers' => false, 'requires_otdr_protected_offers' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $connection->refresh();

    expect($connection->requires_on_time_offers)->toBeFalse()
        ->and($connection->requires_otdr_protected_offers)->toBeTrue();
});

it('claims only approved rates fail when an unapproved service was withheld', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(['name' => 'US Store']));
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

it('leaves an Amazon order with no deliver-by date for a person when on-time delivery is required', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(['name' => 'US Store']), deliverBy: null);
    registerRequirementsAdapter([
        requirementsRate(6.00, Carbon::today()->toDateString()),
        requirementsRate(4.00, null),
    ]);

    expect($package->shipment->getDeliverByDate())->toBeNull();

    $result = autoShipForRequirements($package);

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('No Deliver-By Date')
        ->and($result->message)->toContain('"US Store" requires on-time delivery')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('still buys for an Amazon order with no deliver-by date when on-time delivery is not required', function (): void {
    $package = packageForOrderFrom(DataSource::factory()->amazon()->create(['requires_on_time_offers' => false]), deliverBy: null);
    registerRequirementsAdapter([requirementsRate(4.00, null)]);

    expect(autoShipForRequirements($package)->success)->toBeTrue();
});
