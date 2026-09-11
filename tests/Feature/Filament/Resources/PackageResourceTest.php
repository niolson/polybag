<?php

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\Role;
use App\Enums\ServiceCapability;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\Concerns\ConsultsCarrierPolicyForOffers;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Saloon\Http\Response;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('shows void action for shipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create();

    Livewire::test(ListPackages::class)
        ->assertActionVisible(TestAction::make('void')->table($package));
});

it('hides void action for unshipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create([
        'status' => PackageStatus::Unshipped,
        'tracking_number' => null,
        'carrier' => null,
    ]);

    Livewire::test(ListPackages::class)
        ->assertActionHidden(TestAction::make('void')->table($package));
});

it('keeps the Shopify void guidance visible when Shopify reports no carrier', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => null,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => createShopifyDataSource()->id,
    ]);

    Livewire::test(ListPackages::class)
        ->assertActionVisible(TestAction::make('void')->table($package))
        ->assertActionDisabled(TestAction::make('void')->table($package));
});

it('filters Shopify Shipping packages by postage source rather than carrier text', function (): void {
    $shopifyPackage = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => createShopifyDataSource()->id,
    ]);
    $directPackage = Package::factory()->usps()->create();

    Livewire::test(ListPackages::class)
        ->filterTable('carrier', 'shopify_shipping')
        ->assertCanSeeTableRecords([$shopifyPackage])
        ->assertCanNotSeeTableRecords([$directPackage]);
});

it('does not give an Amazon Buy Shipping label the Shopify void guidance', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'Amazon Shipping',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory()->amazon(),
    ]);

    Livewire::test(ListPackages::class)
        ->assertActionVisible(TestAction::make('void')->table($package))
        ->assertActionEnabled(TestAction::make('void')->table($package))
        ->assertSee('via Amazon Buy Shipping')
        ->assertDontSee('via Shopify Shipping');
});

it('filters Amazon Buy Shipping packages by postage source rather than carrier text', function (): void {
    $amazonPackage = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory()->amazon(),
    ]);
    $shopifyPackage = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => createShopifyDataSource()->id,
    ]);
    $directPackage = Package::factory()->create(['carrier' => 'UPS']);

    Livewire::test(ListPackages::class)
        ->filterTable('carrier', 'amazon_buy_shipping')
        ->assertCanSeeTableRecords([$amazonPackage])
        ->assertCanNotSeeTableRecords([$shopifyPackage, $directPackage]);
});

it('voids a label and clears shipping fields', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
        'service' => 'USPS Ground Advantage',
        'cost' => 8.50,
        'label_data' => 'base64labeldata',
        'label_orientation' => 'portrait',
    ]);

    $testAdapterClass = get_class(new class implements DirectCarrierAdapter
    {
        use ConsultsCarrierPolicyForOffers;

        public function getCarrierName(): string
        {
            return 'USPS';
        }

        public function getRates(RateRequest $request, array $serviceCodes): Collection
        {
            return collect();
        }

        public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
        {
            return null;
        }

        public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
        {
            return collect();
        }

        public function createShipment(ShipRequest $request): ShipResponse
        {
            return ShipResponse::failure('Not implemented');
        }

        public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
        {
            return CancelResponse::success('Label voided successfully.');
        }

        public function supportsTracking(): bool
        {
            return false;
        }

        public function trackShipment(Package $package): TrackShipmentResponse
        {
            return TrackShipmentResponse::unsupported();
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function supportsMultiPackage(): bool
        {
            return false;
        }

        public function supportsCarrierManifest(): bool
        {
            return true;
        }

        public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
        {
            return $rate;
        }

        public function serviceCapability(string $serviceCode): ServiceCapability
        {
            return ServiceCapability::NotImplemented;
        }

        public function declaredValueCap(): ?float
        {
            return null;
        }
    });

    app(CarrierRegistry::class)->register('USPS', $testAdapterClass);
    app(CarrierRegistry::class)->clearInstances();

    Livewire::test(ListPackages::class)
        ->callAction(TestAction::make('void')->table($package))
        ->assertNotified();

    $package->refresh();

    expect($package->tracking_number)->toBeNull()
        ->and($package->carrier)->toBeNull()
        ->and($package->service)->toBeNull()
        ->and($package->cost)->toBeNull()
        ->and($package->label_data)->toBeNull()
        ->and($package->label_orientation)->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->shipped_at)->toBeNull()
        ->and($package->shipped_by_user_id)->toBeNull()
        ->and($package->weight)->not->toBeNull()
        ->and($package->height)->not->toBeNull()
        ->and($package->width)->not->toBeNull()
        ->and($package->length)->not->toBeNull();
});

it('shows void action on view page for shipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('void');
});

it('hides void action on view page for unshipped packages', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create([
        'status' => PackageStatus::Unshipped,
        'tracking_number' => null,
        'carrier' => null,
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionHidden('void');
});

it('labels an Amazon Buy Shipping package on the view page without the Shopify notice', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'Amazon Shipping',
        'cost' => 4.76,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory()->amazon(),
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSee('Carrier (via Amazon Buy Shipping)')
        ->assertDontSee('chosen by Shopify')
        ->assertDontSee('Bought through Shopify Shipping');
});
