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
use App\Models\Package;
use App\Models\ServiceApproval;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * `carrier-catalog-reset/15`: Amazon Shipping sold to an order from another
 * channel is a direct rate. Batch ship buys it like UPS Ground, with no
 * approval, and a rule names it as *Direct, Amazon Shipping Ground*. This
 * replaces `amazon-shipping-external-orders/07`'s approval for other channels.
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

/**
 * A direct carrier on the same shipping method, quoting cheaper than Amazon
 * Shipping, which a rule naming Amazon Shipping Ground must never buy.
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
        new RateResponse(carrier: 'MockCarrier', serviceCode: 'GROUND', serviceName: 'Ground', price: 1.00, carrierServiceId: $service->id, carrierId: $carrier->id),
    ]));
    $adapter->shouldNotReceive('createShipment');

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

function ruleNamingAmazonShippingGround(Package $package, ShippingRuleAction $action = ShippingRuleAction::UseService): void
{
    ShippingRule::factory()->source($action === ShippingRuleAction::UseService ? ShippingRuleSource::Direct : ShippingRuleSource::Any)->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => $action,
        'carrier_service_id' => amazonShippingGround()->id,
    ]);
}

it('batch ships a non-Amazon order on Amazon Shipping Ground unattended, with no approval', function (): void {
    $item = batchShip($this->package);
    $package = $this->package->fresh();

    expect($item->status)->toBe(LabelBatchItemStatus::Success)
        ->and(ServiceApproval::count())->toBe(0)
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->carrier)->toBe(Carrier::AMAZON_SHIPPING)
        ->and($package->tracking_number)->toBe('TBA123456789000')
        ->and($package->postage_data_source_id)->toBe($this->connection->id);

    Saloon::assertSent(fn (object $request): bool => $request instanceof GetShippingRates && $request->channelType() === 'EXTERNAL');
    Saloon::assertSent(PurchaseShipment::class);
});

it('reads no approval for other channels, whichever channel it names', function (AmazonChannelType $channelType): void {
    approveAmazonShippingGround($this->package, $channelType);

    expect(batchShip($this->package)->status)->toBe(LabelBatchItemStatus::Success);
})->with([
    'Amazon orders' => AmazonChannelType::Amazon,
    'other channels' => AmazonChannelType::External,
]);

it('buys Amazon Shipping Ground under a Direct rule naming it, though another direct rate is cheaper', function (): void {
    ruleNamingAmazonShippingGround($this->package);
    cheaperDirectRate($this->package);

    $item = batchShip($this->package);
    $package = $this->package->fresh();

    expect($item->status)->toBe(LabelBatchItemStatus::Success)
        ->and($package->tracking_number)->toBe('TBA123456789000')
        ->and($package->postage_data_source_id)->toBe($this->connection->id);

    Saloon::assertSent(PurchaseShipment::class);
});

it('pre-selects Amazon Shipping Ground on the Ship page under a Direct rule naming it', function (): void {
    ruleNamingAmazonShippingGround($this->package);
    cheaperDirectRate($this->package);

    $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

    expect($options->rateOptions)->toHaveCount(2)
        ->and($options->rateOptions[0]['carrier'])->toBe('MockCarrier')
        ->and($options->rateOptions[$options->selectedRateIndex]['serviceName'])->toBe('Amazon Shipping Ground');
});

it('lets a shipping rule exclude Amazon Shipping Ground', function (): void {
    ruleNamingAmazonShippingGround($this->package, ShippingRuleAction::ExcludeService);

    expect(app(PackageShippingWorkflow::class)->prepareRates($this->package)->rateOptions)->toBe([]);

    $item = batchShip($this->package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toBe('No shipping rates available for this package.');

    Saloon::assertNotSent(PurchaseShipment::class);
});

it('is not dropped by a rule excluding Amazon Buy Shipping', function (): void {
    ShippingRule::factory()->source(ShippingRuleSource::Amazon)->anyService()->create([
        'shipping_method_id' => $this->package->shipment->shipping_method_id,
        'action' => ShippingRuleAction::ExcludeService,
    ]);

    expect(batchShip($this->package)->status)->toBe(LabelBatchItemStatus::Success);
    Saloon::assertSent(PurchaseShipment::class);
});
