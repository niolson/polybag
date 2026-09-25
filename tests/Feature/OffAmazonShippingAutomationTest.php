<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\AmazonChannelType;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Http\Integrations\Amazon\Requests\PurchaseShipment;
use App\Jobs\GenerateLabelJob;
use App\Models\Carrier;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\ObservedService;
use App\Models\Package;
use App\Models\ServiceApproval;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * `amazon-shipping-external-orders/07`: batch ship and shipping rules buy
 * off-Amazon Amazon Shipping only for a service approved for orders from other
 * channels. An approval for Amazon orders does not cover it.
 */
beforeEach(function (): void {
    Cache::put('amazon_sp_api_access_token_'.md5('external-refresh-token'), 'external-access-token', 3600);

    $this->connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create([
        'name' => 'Amazon Shipping (3PL)',
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => ['refresh_token' => 'external-refresh-token'],
    ]);
    CarrierAccountScope::create(['data_source_id' => $this->connection->id]);

    $this->package = externalPackage(createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']));

    Saloon::fake([
        GetShippingRates::class => externalRatesResponse(),
        PurchaseShipment::class => MockResponse::make(['payload' => [
            'shipmentId' => 'amzn1.sid.external-1',
            'packageDocumentDetails' => [[
                'packageClientReferenceId' => '1',
                'trackingId' => 'TBA123456789000',
                'packageDocuments' => [[
                    'type' => 'LABEL',
                    'format' => 'PNG',
                    'contents' => base64_encode('EXTERNAL-LABEL-BYTES'),
                ]],
            ]],
        ]]),
    ]);
});

function approveAmazonShippingGround(Package $package, AmazonChannelType $channelType): void
{
    ServiceApproval::factory()->create([
        'channel_type' => $channelType,
        'external_carrier_id' => 'AMZN_US',
        'external_service_id' => 'std-us-swa-mfn',
        'client_id' => $package->shipment->client_id,
    ]);
}

function batchShip(Package $package): LabelBatchItem
{
    $item = LabelBatchItem::factory()->create([
        'label_batch_id' => LabelBatch::factory()->create(['user_id' => User::factory()->create()->id])->id,
        'shipment_id' => $package->shipment_id,
        'package_id' => $package->id,
        'status' => LabelBatchItemStatus::Pending,
    ]);

    (new GenerateLabelJob($item->id, 'pdf', null))->handle();

    return $item->fresh();
}

it('batch ships a non-Amazon order on an Amazon Shipping service approved for other channels', function (): void {
    approveAmazonShippingGround($this->package, AmazonChannelType::External);

    $item = batchShip($this->package);
    $package = $this->package->fresh();

    expect($item->status)->toBe(LabelBatchItemStatus::Success)
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->tracking_number)->toBe('TBA123456789000')
        ->and($package->postage_data_source_id)->toBe($this->connection->id);

    Saloon::assertSent(fn (object $request): bool => $request instanceof GetShippingRates && $request->channelType() === 'EXTERNAL');
    Saloon::assertSent(PurchaseShipment::class);
});

it('does not batch ship an Amazon Shipping service nobody approved for other channels', function (): void {
    $item = batchShip($this->package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toContain('approved for automated purchase');

    Saloon::assertNotSent(PurchaseShipment::class);
});

it('does not spend an approval for Amazon orders on an order from another channel', function (): void {
    approveAmazonShippingGround($this->package, AmazonChannelType::Amazon);

    $item = batchShip($this->package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toContain('Amazon Shipping Ground (via amazon, orders from other channels)')
        ->and($item->error_message)->toContain('Amazon Approvals');

    Saloon::assertNotSent(PurchaseShipment::class);
});

it('lets a shipping rule exclude an off-Amazon service even when it is approved', function (): void {
    approveAmazonShippingGround($this->package, AmazonChannelType::External);

    // Mapped, so a rule can name it: rules name catalog services, and the
    // offer carries the mapped service code.
    $ground = CarrierService::factory()->create(['service_code' => 'AMAZON_SHIPPING_GROUND']);
    ObservedService::factory()->mapped($ground)->create([
        'external_carrier_id' => 'AMZN_US',
        'external_service_id' => 'std-us-swa-mfn',
    ]);
    ShippingRule::factory()->excludeService()->create([
        'shipping_method_id' => $this->package->shipment->shipping_method_id,
        'carrier_service_id' => $ground->id,
    ]);

    $item = batchShip($this->package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toBe('No shipping rates available for this package.');

    Saloon::assertNotSent(PurchaseShipment::class);
});

/**
 * *Amazon Buy Shipping, any*: selects among the offers Amazon quotes
 * (`amazon-buy-shipping/19`, `carrier-catalog-reset/07`).
 */
function ruleNamingAmazon(Package $package, ShippingRuleAction $action = ShippingRuleAction::UseService): void
{
    ShippingRule::factory()->source(ShippingRuleSource::Amazon)->anyService()->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => $action,
    ]);
}

/**
 * A direct carrier on the same shipping method, quoting cheaper than Amazon,
 * which a rule naming Amazon must never buy.
 */
function cheaperDirectRate(Package $package): void
{
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Ground',
        'service_code' => 'GROUND',
        'active' => true,
    ]);
    $package->shipment->shippingMethod->carrierServices()->attach($service->id);

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse(carrier: 'MockCarrier', serviceCode: 'GROUND', serviceName: 'Ground', price: 1.00),
    ]));
    $adapter->shouldNotReceive('createShipment');

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

it('buys the approved Amazon offer under a shipping rule naming Amazon, though another source is cheaper', function (): void {
    approveAmazonShippingGround($this->package, AmazonChannelType::External);
    ruleNamingAmazon($this->package);
    cheaperDirectRate($this->package);

    $item = batchShip($this->package);
    $package = $this->package->fresh();

    expect($item->status)->toBe(LabelBatchItemStatus::Success)
        ->and($package->tracking_number)->toBe('TBA123456789000')
        ->and($package->postage_data_source_id)->toBe($this->connection->id);

    Saloon::assertSent(PurchaseShipment::class);
});

it('withholds and names an unapproved Amazon offer under a shipping rule naming Amazon, and buys nothing else', function (): void {
    ruleNamingAmazon($this->package);
    cheaperDirectRate($this->package);

    $item = batchShip($this->package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toContain('approved for automated purchase')
        ->and($item->error_message)->toContain('Amazon Shipping Ground');

    Saloon::assertNotSent(PurchaseShipment::class);
});

it('pre-selects the Amazon offer on the Ship page under a shipping rule naming Amazon', function (): void {
    ruleNamingAmazon($this->package);
    cheaperDirectRate($this->package);

    $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

    expect($options->rateOptions)->toHaveCount(2)
        ->and($options->rateOptions[0]['carrier'])->toBe('MockCarrier')
        ->and($options->rateOptions[$options->selectedRateIndex]['serviceName'])->toBe('Amazon Shipping Ground');
});

it('lets a shipping rule excluding Amazon drop its offers, even an approved one', function (): void {
    approveAmazonShippingGround($this->package, AmazonChannelType::External);
    ruleNamingAmazon($this->package, ShippingRuleAction::ExcludeService);

    expect(app(PackageShippingWorkflow::class)->prepareRates($this->package)->rateOptions)->toBe([]);

    $item = batchShip($this->package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toBe('No shipping rates available for this package.');

    Saloon::assertNotSent(PurchaseShipment::class);
});
