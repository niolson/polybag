<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\Contracts\RecoversUnresolvedPurchase;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Filament\Pages\Pack;
use App\Filament\Pages\Ship;
use App\Filament\Resources\ShipmentResource;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Notifications\LabelNotRecorded;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * A workflow that offers fixed options and buys nothing.
 */
function fixedOptionsWorkflow(PackageShippingOptions $options): PackageShippingWorkflow
{
    return new class($options) implements PackageShippingWorkflow
    {
        public function __construct(private PackageShippingOptions $options) {}

        public function prepareRates(Package $package): PackageShippingOptions
        {
            return $this->options;
        }

        public function ship(Package $package, PackageShippingRequest $request): PackageShippingResult
        {
            return PackageShippingResult::failed('Unused', 'Unused');
        }

        public function autoShip(Package $package, PackageAutoShippingRequest $request): PackageShippingResult
        {
            return PackageShippingResult::failed('Unused', 'Unused');
        }

        public function checkEarlierPurchases(Package $package, string $labelFormat = 'pdf', ?int $labelDpi = null, ?int $userId = null): ?PackageShippingResult
        {
            return null;
        }
    };
}

function clarityPackage(): Package
{
    $carrier = Carrier::factory()->usps()->create();
    $service = CarrierService::factory()->uspsGroundAdvantage()->create(['carrier_id' => $carrier->id]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);

    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $product = Product::factory()->create(['weight' => 1.0]);
    $shipmentItem = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id, 'quantity' => 1, 'transparency' => false]);

    $package = Package::create([
        'shipment_id' => $shipment->id,
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0, 'height' => 10, 'width' => 8, 'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);
    $package->packageItems()->create(['shipment_item_id' => $shipmentItem->id, 'product_id' => $product->id, 'quantity' => 1]);

    return $package;
}

function noRatesOptions(): PackageShippingOptions
{
    return new PackageShippingOptions(rateOptions: [], rateOptionLabels: [], rateOptionDescriptions: [], deliverByDate: null, allRatesLate: false);
}

it('names the shipment and recipient in the heading, with the way back to Pack and the Shipment', function (): void {
    $package = clarityPackage();
    $package->shipment->update(['shipment_reference' => 'ORD-2000', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'city' => 'Springfield', 'state_or_province' => 'IL', 'country' => 'US']);
    app()->instance(PackageShippingWorkflow::class, fixedOptionsWorkflow(noRatesOptions()));

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('Ship ORD-2000')
        ->assertSee('Ada Lovelace · Springfield, IL · Package '.$package->id)
        ->assertSee(Pack::getUrl(['shipment_id' => $package->shipment_id]), escape: false)
        ->assertSee(ShipmentResource::getUrl('view', ['record' => $package->shipment_id]), escape: false);
});

it('keeps Scan & Pack lit in the sidebar on the Ship page', function (): void {
    expect(Pack::getNavigationItemActiveRoutePattern())->toContain(Ship::getRouteName());
});

it('shows the price apart, the delivery in words, and lateness as a badge', function (): void {
    $package = clarityPackage();
    $rate = fn (string $code, float $price, array $extra = []): array => [
        ...(new RateResponse('USPS', $code, $code, $price, deliveryCommitment: 'MILITARY'))->toArray(),
        ...$extra,
    ];

    app()->instance(PackageShippingWorkflow::class, fixedOptionsWorkflow(new PackageShippingOptions(
        rateOptions: [
            0 => $rate('PRIORITY', 8.62, ['delivery' => 'late']),
            1 => $rate('GROUND', 6.10, ['delivery' => 'unknown']),
        ],
        rateOptionLabels: [0 => '[USPS] PRIORITY', 1 => '[USPS] GROUND'],
        rateOptionDescriptions: [0 => 'Delivers Mon, Oct 26', 1 => 'Military mail, no date'],
        deliverByDate: 'Fri, Oct 23',
        allRatesLate: false,
        unratedSources: ['UPS', 'FedEx'],
    )));

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSeeHtml('data-testid="rate-price"')
        ->assertSee('$8.62')
        ->assertSee('$6.10')
        ->assertSee('Delivers Mon, Oct 26')
        ->assertSee('Military mail, no date')
        ->assertSee('Late')
        ->assertSee('No delivery estimate')
        ->assertDontSee('LATE')
        ->assertSee('No rate from UPS or FedEx for this package.');
});

it('counts the items in a package, not its lines', function (): void {
    $package = clarityPackage();
    $line = $package->packageItems()->first();
    $line->shipmentItem->update(['quantity' => 2]);
    $line->update(['quantity' => 2]);
    $other = ShipmentItem::factory()->create(['shipment_id' => $package->shipment_id, 'product_id' => Product::factory()->create()->id, 'quantity' => 1]);
    $package->packageItems()->create(['shipment_item_id' => $other->id, 'product_id' => $other->product_id, 'quantity' => 1]);
    app()->instance(PackageShippingWorkflow::class, fixedOptionsWorkflow(noRatesOptions()));

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('2 lines, 3 items');
});

it('says a manual shipment has no items instead of an empty card', function (): void {
    $package = Package::create([
        'shipment_id' => Shipment::factory()->create()->id,
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0, 'height' => 10, 'width' => 8, 'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);

    app()->instance(PackageShippingWorkflow::class, fixedOptionsWorkflow(noRatesOptions()));

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('No items: manual shipment.')
        ->assertSee('0 items');
});

describe('a label sold but not recorded', function (): void {
    beforeEach(function (): void {
        // The carrier sells the label, then saving it fails: the column the
        // Label insert writes is missing.
        Schema::table('package_labels', fn (Blueprint $table) => $table->dropColumn('carrier_account_fingerprint'));

        $this->sold = ShipResponse::success(
            trackingNumber: 'TRACK123',
            cost: 8.50,
            carrier: 'USPS',
            service: 'USPS_GROUND_ADVANTAGE',
            labelData: base64_encode('label'),
        );

        $adapter = Mockery::mock(DirectCarrierAdapter::class, RecoversUnresolvedPurchase::class);
        $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
        $adapter->shouldReceive('getCarrierName')->andReturn('USPS');
        $adapter->shouldReceive('isConfigured')->andReturn(true);
        $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
        $adapter->shouldReceive('getRates')->andReturn(collect());
        $adapter->shouldReceive('createShipment')->once()->andReturn($this->sold);
        $adapter->shouldReceive('recoverPurchase')->andReturn($this->sold);
        app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

        $this->package = clarityPackage();
    });

    function selectQuotedRate($component, Package $package): void
    {
        $component->set('rateOptions', [
            0 => quotedDirectly($package, new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'USPS Ground Advantage', 8.50))->toArray(),
        ]);
        $component->set('selectedRateIndex', 0);
    }

    it('shows the failure on the page, with Buy still there to recover it', function (): void {
        $component = Livewire::test(Ship::class, ['package_id' => $this->package->id]);
        selectQuotedRate($component, $this->package);

        $component->call('ship')
            ->assertSet('purchaseError.title', 'Label Bought but Not Recorded')
            ->assertSeeHtml('data-testid="purchase-error"')
            ->assertSee('USPS sold label TRACK123, but PolyBag could not save it.')
            ->assertActionVisible('Ship');

        expect($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);
    });

    it('says on reload that a sold label is waiting and that Buy recovers it', function (): void {
        $component = Livewire::test(Ship::class, ['package_id' => $this->package->id]);
        selectQuotedRate($component, $this->package);
        $component->call('ship');

        Livewire::test(Ship::class, ['package_id' => $this->package->id])
            ->assertSet('purchaseError', null)
            ->assertSeeHtml('data-testid="unfinished-purchase"')
            ->assertSee('USPS sold label TRACK123 for this package that PolyBag did not save.')
            ->assertSee('Buy & Print Label asks USPS for that label first');
    });

    it('sends the bell notification once, however often recording fails', function (): void {
        $component = Livewire::test(Ship::class, ['package_id' => $this->package->id]);
        selectQuotedRate($component, $this->package);
        $component->call('ship');

        // The retry asks USPS for the same label, and saving fails again.
        $component->call('ship')
            ->assertSet('purchaseError.title', 'Label Bought but Not Recorded');

        expect(auth()->user()->notifications()->where('type', LabelNotRecorded::class)->count())->toBe(1);
    });
});
