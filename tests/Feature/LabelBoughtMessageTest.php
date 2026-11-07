<?php

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Filament\Pages\Ship;
use App\Filament\Support\LabelBoughtMessage;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Livewire\Livewire;

it('names the carrier by its logo and the service as the catalog does, in the Label Bought toast', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('getCarrierName')->andReturn('USPS');
    $adapter->shouldReceive('isConfigured')->andReturn(true);
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect());
    $adapter->shouldReceive('createShipment')->once()->andReturn(ShipResponse::success(
        trackingNumber: 'TRACK123',
        cost: 8.40,
        carrier: 'USPS',
        service: 'USPS Ground Advantage Machinable Single-piece',
        labelData: base64_encode('label'),
    ));
    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $carrier = Carrier::factory()->usps()->create();
    $service = CarrierService::factory()->uspsGroundAdvantage()->create(['carrier_id' => $carrier->id, 'name' => 'Ground Advantage']);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);

    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $product = Product::factory()->create(['weight' => 1.0]);
    $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id, 'quantity' => 1, 'transparency' => false]);
    $package = Package::create([
        'shipment_id' => $shipment->id,
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0, 'height' => 10, 'width' => 8, 'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);
    $package->packageItems()->create(['shipment_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 1]);

    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);
    $component->set('rateOptions', [
        0 => quotedDirectly($package, (new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'USPS Ground Advantage', 8.40))->withCatalogIdentity($carrier->id, $service->id))->toArray(),
    ]);
    // A real quote records the catalog service on the Offer; the helper does not.
    ShippingOffer::query()->update(['carrier_service_id' => $service->id]);
    $component->set('selectedRateIndex', 0);
    $component->call('ship');

    $toast = sentNotifications()->sole();

    expect($toast->getTitle())->toBe('Label Bought')
        ->and($toast->getBody())->toContain('usps-logo.svg', 'alt="USPS"', 'Ground Advantage · $8.40', 'Tracking TRACK123');
    expect($toast->getBody())->not->toContain('Machinable Single-piece');
});

it('names a carrier with no logo in words', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'OnTrac',
        'service' => 'Ground',
        'tracking_number' => 'ONTRAC1',
        'cost' => 7.25,
    ]);

    $message = LabelBoughtMessage::for($package);

    expect($message)->toContain('OnTrac', 'Ground · $7.25', 'Tracking ONTRAC1');
    expect($message)->not->toContain('<img');
});

it('says a blind Shopify purchase reported no service, and leaves out the unknown cost', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'service' => null,
        'cost' => null,
        'tracking_number' => 'SHOPPED1',
        'postage_source' => PostageSource::PostageDataSource,
    ]);

    $message = LabelBoughtMessage::for($package);

    expect($message)->toContain('usps-logo.svg', 'Service not reported', 'Tracking SHOPPED1');
    expect($message)->not->toContain('$');
});

it('escapes what a source reported', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => '<b>Courier</b>',
        'service' => '<script>x</script>',
        'tracking_number' => '<i>1</i>',
    ]);

    $message = LabelBoughtMessage::for($package);

    expect($message)->not->toContain('<script>', '<b>', '<i>');
});
