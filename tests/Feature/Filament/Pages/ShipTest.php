<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Filament\Pages\Ship;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\SettingsService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

function createShippablePackage(): Package
{
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['barcode' => '1234567890123']);

    // Create a shipping method with active carrier services
    $carrier = Carrier::factory()->usps()->create();
    $carrierService = CarrierService::factory()->uspsGroundAdvantage()->create(['carrier_id' => $carrier->id]);
    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($carrierService->id);

    $shipment = Shipment::factory()->create(['shipping_method_id' => $shippingMethod->id]);
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    return Package::create([
        'shipment_id' => $shipment->id,
        'box_size_id' => $boxSize->id,
        'weight' => 2.0,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);
}

function registerMockAdapter(ShipResponse $response): void
{
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('getCarrierName')->andReturn('USPS');
    $adapter->shouldReceive('isConfigured')->andReturn(true);
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect());
    $adapter->shouldReceive('createShipment')->andReturn($response);

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);
}

it('applies the selected rate index from the workflow to the form on mount', function (): void {
    $package = createShippablePackage();

    app()->instance(PackageShippingWorkflow::class, new class implements PackageShippingWorkflow
    {
        public function prepareRates(Package $package): PackageShippingOptions
        {
            return new PackageShippingOptions(
                rateOptions: [
                    0 => [
                        'carrier' => 'USPS',
                        'serviceCode' => 'PRIORITY_MAIL',
                        'serviceName' => 'Priority Mail',
                        'price' => 4.25,
                        'deliveryCommitment' => '1-3 Business Days',
                        'deliveryDate' => null,
                        'transitTime' => null,
                        'metadata' => [],
                    ],
                ],
                rateOptionLabels: [0 => '[USPS] Priority Mail'],
                rateOptionDescriptions: [0 => '$4.25 — 1-3 Business Days'],
                deliverByDate: null,
                allRatesLate: false,
                selectedRateIndex: 0,
            );
        }

        public function ship(Package $package, PackageShippingRequest $request): PackageShippingResult
        {
            return PackageShippingResult::failed('Unused', 'Unused');
        }

        public function autoShip(Package $package, PackageAutoShippingRequest $request): PackageShippingResult
        {
            return PackageShippingResult::failed('Unused', 'Unused');
        }
    });

    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);

    $component->assertFormFieldExists('rateOptions')
        ->assertSet('data.rateOptions', 0);
});

it('dispatches print-label event when suppress_printing is off', function (): void {
    $package = createShippablePackage();

    registerMockAdapter(ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('PDF content'),
        labelOrientation: 'portrait',
    ));

    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);

    // Set rate options manually since we can't easily mock the rate fetch in mount
    $component->set('rateOptions', [
        0 => [
            'carrier' => 'USPS',
            'serviceCode' => 'USPS_GROUND_ADVANTAGE',
            'serviceName' => 'USPS Ground Advantage',
            'price' => 8.50,
            'deliveryCommitment' => '2-5 Business Days',
            'deliveryDate' => null,
            'transitTime' => null,
            'metadata' => [
                'mailClass' => 'USPS_GROUND_ADVANTAGE',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
                'destinationEntryFacilityType' => 'NONE',
            ],
        ],
    ]);
    $component->set('formRateOptionLabels', [0 => '[USPS] USPS Ground Advantage']);
    $component->set('formRateOptionDescriptions', [0 => '$8.50 - 2-5 Business Days']);
    $component->fillForm(['rateOptions' => 0]);

    $component->call('ship')
        ->assertDispatched('print-label');

    $package->refresh();
    expect($package->shipped_by_user_id)->toBe(auth()->id());
});

it('does not dispatch print-label event when suppress_printing is on', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $package = createShippablePackage();

    registerMockAdapter(ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('PDF content'),
        labelOrientation: 'portrait',
    ));

    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);

    $component->set('rateOptions', [
        0 => [
            'carrier' => 'USPS',
            'serviceCode' => 'USPS_GROUND_ADVANTAGE',
            'serviceName' => 'USPS Ground Advantage',
            'price' => 8.50,
            'deliveryCommitment' => '2-5 Business Days',
            'deliveryDate' => null,
            'transitTime' => null,
            'metadata' => [
                'mailClass' => 'USPS_GROUND_ADVANTAGE',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
                'destinationEntryFacilityType' => 'NONE',
            ],
        ],
    ]);
    $component->set('formRateOptionLabels', [0 => '[USPS] USPS Ground Advantage']);
    $component->set('formRateOptionDescriptions', [0 => '$8.50 - 2-5 Business Days']);
    $component->fillForm(['rateOptions' => 0]);

    $component->call('ship')
        ->assertNotDispatched('print-label')
        ->assertNotified();
});
