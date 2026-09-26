<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Pages\Pack;
use App\Filament\Pages\Ship;
use App\Filament\Resources\ShipmentResource;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Widgets\ExceptionsWidget;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ShipmentSeeder;
use Database\Seeders\ShippingMethodSeeder;
use Livewire\Livewire;

/**
 * `carrier-catalog-reset/16`: a shipment with no shipping method can be
 * packed, but no label is bought for it until one is chosen.
 */
function unmethodedPackage(): Package
{
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

    return Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
}

/**
 * @return array{0: Shipment, 1: array<int, array<string, mixed>>}
 */
function unmethodedShipmentToPack(): array
{
    $product = Product::factory()->create(['barcode' => '1234567890123']);
    $shipment = Shipment::factory()->withoutShippingMethod()->create();
    $item = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    return [$shipment, [[
        'id' => $item->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'packed' => 1,
        'barcode' => '1234567890123',
        'description' => $product->description,
        'transparency' => false,
        'transparency_codes' => [],
    ]]];
}

function workflowRatingOnce(): void
{
    $workflow = Mockery::mock(PackageShippingWorkflow::class);
    $workflow->shouldReceive('prepareRates')->once()->andReturn(new PackageShippingOptions(
        rateOptions: [0 => [
            'carrier' => 'USPS',
            'serviceCode' => 'USPS_GROUND_ADVANTAGE',
            'serviceName' => 'Ground Advantage',
            'price' => 5.25,
            'deliveryCommitment' => null,
            'deliveryDate' => null,
            'transitTime' => null,
            'metadata' => [],
        ]],
        rateOptionLabels: [0 => '[USPS] Ground Advantage'],
        rateOptionDescriptions: [0 => '$5.25'],
        deliverByDate: null,
        allRatesLate: false,
    ));
    app()->instance(PackageShippingWorkflow::class, $workflow);
}

describe('the Ship page', function (): void {
    it('shows the problem and a method picker instead of rates', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $package = unmethodedPackage();

        $workflow = Mockery::mock(PackageShippingWorkflow::class);
        $workflow->shouldNotReceive('prepareRates');
        app()->instance(PackageShippingWorkflow::class, $workflow);

        Livewire::test(Ship::class, ['package_id' => $package->id])
            ->assertSet('rateOptions', [])
            ->assertSee('Shipping Method Required')
            ->assertSee('Use this method')
            ->assertDontSee('Select Shipping Rate');
    });

    it('saves the chosen method to the shipment and rates the package with it', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $package = unmethodedPackage();
        $method = ShippingMethod::factory()->create(['name' => 'Ground']);
        workflowRatingOnce();

        Livewire::test(Ship::class, ['package_id' => $package->id])
            ->set('shippingMethodId', $method->id)
            ->call('assignShippingMethod')
            ->assertHasNoErrors()
            ->assertNotified('Shipping Method Set')
            ->assertCount('rateOptions', 1)
            ->assertSee('Select Shipping Rate');

        expect($package->shipment->fresh()->shipping_method_id)->toBe($method->id);
    });

    it('refuses an inactive method', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $package = unmethodedPackage();
        $method = ShippingMethod::factory()->create(['active' => false]);

        Livewire::test(Ship::class, ['package_id' => $package->id])
            ->set('shippingMethodId', $method->id)
            ->call('assignShippingMethod')
            ->assertHasErrors(['shippingMethodId']);

        expect($package->shipment->fresh()->shipping_method_id)->toBeNull();
    });

    it('offers no picker to someone who may not edit the shipment', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::User]));
        $package = unmethodedPackage();
        $method = ShippingMethod::factory()->create();

        Livewire::test(Ship::class, ['package_id' => $package->id])
            ->assertSee('Ask a manager')
            ->assertDontSee('Use this method')
            ->set('shippingMethodId', $method->id)
            ->call('assignShippingMethod')
            ->assertForbidden();

        expect($package->shipment->fresh()->shipping_method_id)->toBeNull();
    });
});

describe('the Pack page', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'auto_ship_enabled' => false]));
    });

    it('packs the shipment and says the method is missing', function (): void {
        [$shipment, $packingItems] = unmethodedShipmentToPack();
        $box = BoxSize::factory()->create();

        Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
            ->assertNotified('No Shipping Method')
            ->assertSee('No shipping method')
            ->call('ship', $packingItems, $box->id, '1.5', '10', '8', '6', false)
            ->assertRedirect();

        $package = Package::where('shipment_id', $shipment->id)->sole();

        expect($package->status)->toBe(PackageStatus::Unshipped);
    });

    it('sends an auto-shipping packer to the Ship page to choose a method, keeping the package', function (): void {
        auth()->user()->update(['auto_ship_enabled' => true]);
        [$shipment, $packingItems] = unmethodedShipmentToPack();
        $box = BoxSize::factory()->create();

        $component = Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
            ->call('ship', $packingItems, $box->id, '1.5', '10', '8', '6', true)
            ->assertNotified('Shipping Method Required');

        $package = Package::where('shipment_id', $shipment->id)->sole();

        $component->assertRedirect('/ship/'.$package->id);
        expect($package->status)->toBe(PackageStatus::Unshipped);
    });
});

describe('the Shipments list', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('filters to open shipments that need a shipping method', function (): void {
        $needs = Shipment::factory()->withoutShippingMethod()->create();
        $hasOne = Shipment::factory()->create();
        $shipped = Shipment::factory()->withoutShippingMethod()->create(['status' => ShipmentStatus::Shipped]);

        Livewire::test(ListShipments::class)
            ->filterTable('needs_shipping_method')
            ->assertCanSeeTableRecords([$needs])
            ->assertCanNotSeeTableRecords([$hasOne, $shipped]);
    });

    it('flags an open shipment with no method', function (): void {
        Shipment::factory()->withoutShippingMethod()->create();

        Livewire::test(ListShipments::class)->assertSee('Needs shipping method');
    });

    it('counts them on the Exceptions widget and links to the filtered list', function (): void {
        Shipment::factory()->count(2)->withoutShippingMethod()->create();
        Shipment::factory()->withoutShippingMethod()->create(['status' => ShipmentStatus::Shipped]);

        expect(Shipment::query()->needingShippingMethod()->count())->toBe(2);

        Livewire::test(ExceptionsWidget::class)
            ->assertSee('Needs Shipping Method')
            ->assertSee(ShipmentResource::needsShippingMethodUrl(), escape: false);
    });
});

it('leaves no seeded shipment without a shipping method', function (): void {
    $this->seed([ClientSeeder::class, ReferenceDataSeeder::class, ShippingMethodSeeder::class, ShipmentSeeder::class]);

    expect(Shipment::count())->toBeGreaterThan(0)
        ->and(Shipment::whereNull('shipping_method_id')->count())->toBe(0)
        ->and(Shipment::where('country', '!=', 'US')->whereHas('shippingMethod', fn ($query) => $query->where('name', 'International Economy'))->count())
        ->toBe(Shipment::where('country', '!=', 'US')->count());
});
