<?php

use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\CancelAmazonShipment;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\GetShipmentTracking;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Http\Integrations\Amazon\Requests\PurchaseShipment;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\CarrierAccountScope;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\ManifestService;
use App\Services\PackageShipping\EloquentPackageShippingWorkflow;
use App\Services\PostageSources\PostageSourceDispatcher;
use App\Services\ShippingRateService;
use App\Services\TrackingService;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `amazon-shipping-external-orders/06`: an off-Amazon Amazon Shipping Offer is
 * bought, printed, tracked and voided through the connection that sold it — the
 * scoped Amazon connection, never the Shopify connection the order came from.
 */
beforeEach(function (): void {
    Cache::put('amazon_sp_api_access_token_'.md5('external-refresh-token'), 'external-access-token', 3600);

    $this->connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create([
        'name' => 'Amazon Shipping (3PL)',
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => ['refresh_token' => 'external-refresh-token'],
    ]);
    CarrierAccountScope::create(['data_source_id' => $this->connection->id]);

    $this->shopify = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);
    $this->package = externalPackage($this->shopify);
    $this->package->shipment->update([
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/6006'],
    ]);
});

/**
 * What the sandbox answered `01`'s `EXTERNAL` purchases with: one `LABEL`, no
 * pack slip, and an Amazon Logistics `TBA…` tracking ID.
 */
function externalPurchaseResponse(string $format = 'PNG'): MockResponse
{
    return MockResponse::make(['payload' => [
        'shipmentId' => 'amzn1.sid.external-1',
        'promise' => ['deliveryWindow' => ['start' => '2026-09-24T07:00:00Z', 'end' => '2026-09-25T06:59:59Z']],
        'packageDocumentDetails' => [[
            'packageClientReferenceId' => '1',
            'trackingId' => 'TBA123456789000',
            'packageDocuments' => [[
                'type' => 'LABEL',
                'format' => $format,
                'contents' => base64_encode('EXTERNAL-LABEL-BYTES'),
            ]],
        ]],
    ]]);
}

/**
 * Quote the Package through rate shopping, as the Ship page does, and return
 * the Amazon Shipping rate.
 */
function quoteExternalRate(Package $package): RateResponse
{
    return app(ShippingRateService::class)->getShippingRates($package->id)
        ->firstOrFail(fn (RateResponse $rate): bool => $rate->carrier === 'Amazon Shipping');
}

function sentByConnection(PendingRequest $request, DataSource $connection): bool
{
    $connector = $request->getConnector();

    return $connector instanceof AmazonSpApiConnector && $connector->dataSourceId() === $connection->id;
}

it('buys a Shopify order\'s Amazon Shipping label on the scoped connection and records that connection as its postage source', function (): void {
    Saloon::fake([
        GetShippingRates::class => externalRatesResponse(),
        PurchaseShipment::class => externalPurchaseResponse(),
    ]);

    $rate = quoteExternalRate($this->package);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'pdf',
    ));

    $package = $this->package->fresh();

    expect($result->success)->toBeTrue()
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->tracking_number)->toBe('TBA123456789000')
        ->and($package->carrier)->toBe('Amazon Shipping')
        ->and($package->carrierOfRecordName())->toBe('Amazon Shipping')
        ->and($package->service)->toBe('Amazon Shipping Ground')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and((float) $package->cost)->toBe(7.9)
        ->and($package->postage_source)->toBe(PostageSource::PostageDataSource)
        // The connection that sold the Offer — not the Shopify import source,
        // which would send tracking and voids to Shopify.
        ->and($package->postage_data_source_id)->toBe($this->connection->id)
        ->and($package->postage_data_source_id)->not->toBe($this->shopify->id)
        ->and($package->carrier_account_id)->toBeNull()
        ->and($package->metadata[AmazonBuyShippingAdapter::SHIPMENT_ID_KEY])->toBe('amzn1.sid.external-1')
        ->and($package->metadata[AmazonBuyShippingAdapter::CARRIER_ID_KEY])->toBe('AMZN_US')
        ->and($package->metadata[AmazonBuyShippingAdapter::SERVICE_ID_KEY])->toBe('std-us-swa-mfn')
        ->and($package->activeLabel->postage_data_source_id)->toBe($this->connection->id)
        ->and($package->activeLabel->source_label_reference)->toBe('amzn1.sid.external-1')
        ->and(ShippingOffer::where('public_id', $rate->offerId)->value('purchase_reference'))
        ->toBe('amzn1.sid.external-1');

    Saloon::assertSent(fn (PurchaseShipment $request, $response): bool => sentByConnection($response->getPendingRequest(), $this->connection)
        && $request->body()->all()['rateId'] === 'b1a4a1f0-0c4f-4a47-9d2e-5c6f0a1e7a11'
        && $request->body()->all()['requestToken'] === 'amzn1.rq.external-request-token');
});

it('buys the label alone, unjoined, in each format the sandbox offered', function (string $workstationFormat, ?int $dpi, array $offered, string $bought, ?int $boughtDpi, string $recorded): void {
    $rate = amazonShippingGroundRate();
    $rate['supportedDocumentSpecifications'] = array_values(array_filter(
        $rate['supportedDocumentSpecifications'],
        fn (array $spec): bool => in_array($spec['format'], $offered, true),
    ));

    Saloon::fake([
        GetShippingRates::class => externalRatesResponse([$rate]),
        PurchaseShipment::class => externalPurchaseResponse($bought),
    ]);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: quoteExternalRate($this->package),
        labelFormat: $workstationFormat,
        labelDpi: $dpi,
    ));

    $package = $this->package->fresh();

    expect($result->success)->toBeTrue()
        ->and($package->label_format)->toBe($recorded)
        ->and($package->label_dpi)->toBe($boughtDpi)
        ->and(base64_decode($package->label_data))->toBe('EXTERNAL-LABEL-BYTES');

    Saloon::assertSent(function (PurchaseShipment $request) use ($bought, $boughtDpi): bool {
        $body = $request->body()->all();
        $spec = $body['requestedDocumentSpecification'];

        assertMatchesSpApiSchema($body, 'PurchaseShipmentRequest', 'shippingV2');

        return $spec['format'] === $bought
            && $spec['size'] === ['width' => 4.0, 'length' => 6.0, 'unit' => 'INCH']
            && ($spec['dpi'] ?? null) === $boughtDpi
            // `01`: every `EXTERNAL` print option offers joining `[false]`
            // only, and the label as its only document — no pack slip.
            && $spec['needFileJoining'] === false
            && $spec['requestedDocumentTypes'] === ['LABEL'];
    });
})->with([
    'PDF workstation gets PNG' => ['pdf', null, ['PNG', 'ZPL', 'PDF'], 'PNG', null, 'image'],
    'ZPL at 203 DPI' => ['zpl', 203, ['PNG', 'ZPL', 'PDF'], 'ZPL', 203, 'zpl'],
    'ZPL at 300 DPI' => ['zpl', 300, ['PNG', 'ZPL', 'PDF'], 'ZPL', 300, 'zpl'],
    'PDF when nothing else is offered' => ['pdf', null, ['PDF'], 'PDF', null, 'pdf'],
]);

it('reprints from the label bought, without asking Amazon and without touching the tracking ID', function (): void {
    $packer = User::factory()->create()->fresh();

    Saloon::fake([
        GetShippingRates::class => externalRatesResponse(),
        PurchaseShipment::class => externalPurchaseResponse(),
    ]);

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: quoteExternalRate($this->package),
        userId: $packer->id,
    ));

    // `01`: the sandbox's `getShipmentDocuments` returned a different tracking
    // ID from the purchase's. A reprint sends the stored bytes, so there is no
    // second answer to overwrite the first with.
    $result = app(PackageLabelWorkflow::class)->labelForReprint($this->package->fresh(), $packer);

    expect($result->success)->toBeTrue()
        ->and(base64_decode($result->printRequest->label))->toBe('EXTERNAL-LABEL-BYTES')
        ->and($this->package->fresh()->tracking_number)->toBe('TBA123456789000');

    // The quote and the purchase, and nothing for the reprint.
    Saloon::assertSentCount(2);
});

it('tracks through the connection that sold the label, even after its scope is gone', function (): void {
    Saloon::fake([
        GetShippingRates::class => externalRatesResponse(),
        PurchaseShipment::class => externalPurchaseResponse(),
    ]);

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: quoteExternalRate($this->package),
    ));

    // Provenance, not the current scope, decides who is asked.
    CarrierAccountScope::where('data_source_id', $this->connection->id)->delete();

    Saloon::fake([GetShipmentTracking::class => MockResponse::make(['payload' => [
        'trackingId' => 'TBA123456789000',
        'summary' => ['status' => 'InTransit'],
        'eventHistory' => [
            ['eventCode' => 'PickupDone', 'eventTime' => '2026-09-24T15:00:00Z', 'location' => ['city' => 'Kent', 'stateOrRegion' => 'WA', 'countryCode' => 'US']],
        ],
    ]])]);

    $response = app(TrackingService::class)->refreshPackage($this->package->fresh());

    expect($response->success)->toBeTrue()
        ->and($response->status->value)->toBe('in_transit');

    Saloon::assertSent(fn (GetShipmentTracking $request, $response): bool => sentByConnection($response->getPendingRequest(), $this->connection)
        && $request->query()->all() === ['trackingId' => 'TBA123456789000', 'carrierId' => 'AMZN_US']);
});

it('voids through the connection that sold the label, keeps the Label as history and returns the Package to unshipped', function (): void {
    Saloon::fake([
        GetShippingRates::class => externalRatesResponse(),
        PurchaseShipment::class => externalPurchaseResponse(),
    ]);

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: quoteExternalRate($this->package),
    ));

    Saloon::fake([CancelAmazonShipment::class => MockResponse::make(['payload' => []])]);

    $result = app(PackageLabelWorkflow::class)->voidLabel($this->package->fresh());

    $package = $this->package->fresh();
    $label = $package->labels()->sole();

    expect($result->success)->toBeTrue()
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->activeLabel)->toBeNull()
        ->and(AmazonBuyShippingAdapter::shipmentIdFor($package))->toBeNull()
        ->and($label->isVoided())->toBeTrue()
        ->and($label->tracking_number)->toBe('TBA123456789000')
        ->and($label->postage_data_source_id)->toBe($this->connection->id);

    Saloon::assertSent(fn (CancelAmazonShipment $request, $response): bool => sentByConnection($response->getPendingRequest(), $this->connection)
        && $request->resolveEndpoint() === '/shipping/v2/shipments/amzn1.sid.external-1/cancel');
});

it('reports the Amazon Shipping tracking number to the Shopify order as its fulfillment', function (): void {
    $this->shopify->update(['settings' => [...$this->shopify->settings, 'export_enabled' => true]]);

    Saloon::fake([
        GetShippingRates::class => externalRatesResponse(),
        PurchaseShipment::class => externalPurchaseResponse(),
        GraphQL::class => MockResponse::make(['data' => ['fulfillmentCreate' => [
            'fulfillment' => ['id' => 'gid://shopify/Fulfillment/6006', 'status' => 'SUCCESS'],
            'userErrors' => [],
        ]]]),
    ]);

    // The queue is synchronous under test, so shipping runs the export.
    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: quoteExternalRate($this->package),
    ));

    expect($this->package->fresh()->exported)->toBeTrue();

    Saloon::assertSent(function (GraphQL $request): bool {
        $fulfillment = $request->body()->all()['variables']['fulfillment'] ?? null;

        return $fulfillment !== null
            && $fulfillment['lineItemsByFulfillmentOrder'] === [['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/6006']]
            && $fulfillment['trackingInfo'] === ['company' => 'Amazon Shipping', 'number' => 'TBA123456789000'];
    });

    // Amazon has no order to confirm: the order is Shopify's.
    Saloon::assertNotSent(ConfirmShipment::class);
});

it('keeps Amazon Shipping labels off End of Day manifests', function (): void {
    Saloon::fake([
        GetShippingRates::class => externalRatesResponse(),
        PurchaseShipment::class => externalPurchaseResponse(),
    ]);

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: quoteExternalRate($this->package),
    ));

    $package = $this->package->fresh();

    // Amazon Shipping tenders the parcel on Amazon's account; a SCAN form of
    // ours would claim a handover we did not make.
    expect(app(PostageSourceDispatcher::class)->supportsPackageManifest($package))->toBeFalse()
        ->and(app(ManifestService::class)->getUnmanifestedPackages()->flatten()->pluck('id'))->not->toContain($package->id)
        ->and(app(ManifestService::class)->createManifest('USPS', collect([$package]))->success)->toBeFalse();
});

it('recovers an off-Amazon purchase whose reply never arrived on the connection that quoted it', function (): void {
    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $rate = quoteExternalRate($this->package);
    $offer = ShippingOffer::where('public_id', $rate->offerId)->firstOrFail();

    // Spent, with nothing heard back — what a timeout leaves behind.
    $offer->forceFill(['consumed_at' => now()])->save();

    Saloon::fake([PurchaseShipment::class => externalPurchaseResponse()]);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
    ));

    $package = $this->package->fresh();

    expect($result->success)->toBeTrue()
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->postage_data_source_id)->toBe($this->connection->id)
        ->and($offer->fresh()->purchase_reference)->toBe('amzn1.sid.external-1');

    // One quote and one purchase. The recovery *is* the retry, recognised by
    // its idempotency key — a second `purchaseShipment` would be a second label.
    Saloon::assertSentCount(2);
    Saloon::assertSent(fn (PurchaseShipment $request, $response): bool => sentByConnection($response->getPendingRequest(), $this->connection)
        && $request->headers()->get('x-amzn-IdempotencyKey') === $rate->offerId);
});
