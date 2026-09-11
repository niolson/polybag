<?php

use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\SourceEnvironment;
use App\Http\Integrations\Amazon\Requests\CancelAmazonShipment;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\GetShipmentTracking;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Http\Integrations\Amazon\Requests\PurchaseShipment;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\ObservedService;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShippingOffer;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\PackageShipping\EloquentPackageShippingWorkflow;
use App\Services\PostageSources\ObservedServiceMapper;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\PostageSourceDispatcher;
use App\Services\SettingsService;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\TrackingService;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * The eligible half of a `getRates` reply, shaped from the production capture in
 * `.scratch/amazon-shipping-v2/` — two carriers, priced independently, with the
 * document and value-added-service detail that varies between them.
 *
 * OnTrac is the one that matters most: a carrier we hold no row for, no account
 * with and no adapter, offered at the lowest price and with no Confirmation
 * group at all.
 *
 * @return array<int, array<string, mixed>>
 */
function amazonEligibleRates(): array
{
    return [
        [
            'rateId' => '083bd8f2-960d-43a6-83ca-af5d4c578842',
            'carrierId' => 'ONTRAC',
            'carrierName' => 'OnTrac',
            'serviceId' => 'ONTRAC_MFN_GROUND',
            'serviceName' => 'OnTrac Ground',
            'totalCharge' => ['unit' => 'USD', 'value' => 5.79],
            'requiresAdditionalInputs' => false,
            'promise' => ['deliveryWindow' => ['start' => '2026-09-08T06:59:59Z', 'end' => '2026-09-08T06:59:59Z']],
            'availableValueAddedServiceGroups' => [],
            'supportedDocumentSpecifications' => amazonDocumentSpecifications(),
        ],
        [
            'rateId' => '1f5c0d51-6b2b-4bd1-9d3e-0b6b0f4b7a41',
            'carrierId' => 'UPS',
            'carrierName' => 'UPS',
            'serviceId' => 'UPS_PTP_NEXT_DAY_AIR_SAVER',
            'serviceName' => 'UPS Next Day Air Saver®',
            'totalCharge' => ['unit' => 'USD', 'value' => 16.06],
            'requiresAdditionalInputs' => false,
            'promise' => ['deliveryWindow' => ['start' => '2026-09-06T06:59:59Z', 'end' => '2026-09-06T06:59:59Z']],
            'availableValueAddedServiceGroups' => [[
                'groupId' => 'VAS_GROUP_ID_CONFIRMATION',
                'groupDescription' => 'Confirmation',
                'isRequired' => true,
                'valueAddedServices' => [
                    ['id' => 'SIGNATURE_CONFIRMATION', 'name' => 'Signature confirmation', 'cost' => ['unit' => 'USD', 'value' => 6.25]],
                    ['id' => 'NO_CONFIRMATION', 'name' => 'No confirmation', 'cost' => ['unit' => 'USD', 'value' => 0.0]],
                ],
            ]],
            'supportedDocumentSpecifications' => amazonDocumentSpecifications(),
        ],
    ];
}

/**
 * Every rate in the production capture offered PDF twice — letter and 4x6 — plus
 * ZPL at 300 DPI and a PNG our print path cannot use.
 *
 * The print options are the captured ones, not a simplification: file joining
 * is offered as `[true]` only, and both PDF sizes make a `PACKSLIP` mandatory
 * beside the `LABEL` (UPS's letter size says `RECEIPT` instead). A version of
 * this fixture with `LABEL` alone let 22 tests pass against a purchase body
 * Amazon refuses — `10`.
 *
 * @return array<int, array<string, mixed>>
 */
function amazonDocumentSpecifications(): array
{
    return [
        [
            'format' => 'PDF',
            'size' => ['width' => 8.5, 'length' => 11.0, 'unit' => 'INCH'],
            'printOptions' => [['supportedDPIs' => [], 'supportedPageLayouts' => ['LEFT', 'RIGHT'], 'supportedFileJoiningOptions' => [true], 'supportedDocumentDetails' => [['name' => 'PACKSLIP', 'isMandatory' => true], ['name' => 'LABEL', 'isMandatory' => true]]]],
        ],
        [
            'format' => 'ZPL',
            'size' => ['width' => 4.0, 'length' => 6.0, 'unit' => 'INCH'],
            'printOptions' => [['supportedDPIs' => [300], 'supportedPageLayouts' => ['LEFT'], 'supportedFileJoiningOptions' => [true], 'supportedDocumentDetails' => [['name' => 'LABEL', 'isMandatory' => true]]]],
        ],
        [
            'format' => 'PNG',
            'size' => ['width' => 4.0, 'length' => 6.0, 'unit' => 'INCH'],
            'printOptions' => [['supportedDPIs' => [], 'supportedPageLayouts' => ['LEFT'], 'supportedFileJoiningOptions' => [true], 'supportedDocumentDetails' => [['name' => 'LABEL', 'isMandatory' => true]]]],
        ],
        [
            'format' => 'PDF',
            'size' => ['width' => 4.0, 'length' => 6.0, 'unit' => 'INCH'],
            'printOptions' => [['supportedDPIs' => [], 'supportedPageLayouts' => ['LEFT'], 'supportedFileJoiningOptions' => [true], 'supportedDocumentDetails' => [['name' => 'PACKSLIP', 'isMandatory' => true], ['name' => 'LABEL', 'isMandatory' => true]]]],
        ],
    ];
}

/**
 * A USPS offer as captured on 2026-09-11 for a Guam order: the Confirmation
 * group is required, and it offers no `NO_CONFIRMATION` — its free option is
 * `DELIVERY_CONFIRMATION`. The vocabulary UPS's group uses for "nothing" does
 * not exist here, which is what the first live purchase found (`10`).
 *
 * @param  array<int, array<string, mixed>>|null  $confirmationOptions
 * @return array<string, mixed>
 */
function amazonUspsRate(?array $confirmationOptions = null): array
{
    return [
        'rateId' => '7c0e4b2a-3d1f-4e7b-9a8c-2f6d5e4c3b2a',
        'carrierId' => 'USPS',
        'carrierName' => 'USPS',
        'serviceId' => 'USPS_PTP_GAH',
        'serviceName' => 'USPS Ground Advantage (1 - 70 lb)',
        'totalCharge' => ['unit' => 'USD', 'value' => 10.05],
        'requiresAdditionalInputs' => false,
        'promise' => ['deliveryWindow' => ['start' => '2026-09-18T06:59:59Z', 'end' => '2026-09-20T06:59:59Z']],
        'availableValueAddedServiceGroups' => [[
            'groupId' => 'VAS_GROUP_ID_CONFIRMATION',
            'groupDescription' => 'Confirmation',
            'isRequired' => true,
            'valueAddedServices' => $confirmationOptions ?? [
                ['id' => 'SIGNATURE_CONFIRMATION', 'name' => 'Signature confirmation', 'cost' => ['unit' => 'USD', 'value' => 4.15]],
                ['id' => 'DELIVERY_CONFIRMATION', 'name' => 'Delivery confirmation', 'cost' => ['unit' => 'USD', 'value' => 0]],
            ],
        ]],
        'supportedDocumentSpecifications' => amazonDocumentSpecifications(),
    ];
}

/**
 * @param  array<int, array<string, mixed>>|null  $rates
 * @param  array<int, array<string, mixed>>|null  $ineligibleRates
 */
function amazonRatesResponse(?array $rates = null, ?array $ineligibleRates = null): MockResponse
{
    return MockResponse::make(['payload' => [
        'requestToken' => 'amzn1.rq.ca171d6e-d13b-4973-993b-6461775',
        'rates' => $rates ?? amazonEligibleRates(),
        // `01`: the reason code is `UNKNOWN` on every entry Amazon returns, so
        // identity is the only thing worth harvesting from this array.
        'ineligibleRates' => $ineligibleRates ?? [[
            'carrierId' => '4PX',
            'carrierName' => '4PX',
            'serviceId' => '4PX_GLOBAL_EXPRESS_PRI_LI',
            'serviceName' => 'AMZ-4PX Global Express Priority-Li',
            'ineligibilityReasons' => [['code' => 'UNKNOWN', 'message' => 'Not an eligible ship method for this order.']],
        ]],
    ]]);
}

function amazonPurchaseResponse(string $shipmentId = 'amzn1.sid.abc123', string $format = 'ZPL'): MockResponse
{
    return MockResponse::make(['payload' => [
        'shipmentId' => $shipmentId,
        'promise' => ['deliveryWindow' => ['start' => '2026-09-08T06:59:59Z', 'end' => '2026-09-08T06:59:59Z']],
        'packageDocumentDetails' => [[
            'packageClientReferenceId' => '1',
            'trackingId' => 'D10012345678901',
            'packageDocuments' => [[
                'type' => 'LABEL',
                'format' => $format,
                'contents' => base64_encode('LABEL-BYTES'),
            ]],
        ]],
    ]]);
}

function amazonBuyShippingSource(): DataSource
{
    return DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Buy Shipping',
        'active' => true,
        'settings' => [
            'channel_name' => 'Amazon',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'amazon_order_id' => 'amazon_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
}

/**
 * A packed, unshipped parcel on an Amazon order — one line, identified the way
 * an import identifies it.
 */
function amazonBuyShippingPackage(DataSource $source, array $packageAttributes = []): Package
{
    $shipment = Shipment::factory()->create([
        'data_source_id' => $source->id,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ]);

    $package = Package::factory()->create([
        'shipment_id' => $shipment->id,
        'weight' => 0.75,
        'length' => 9.0,
        'width' => 6.0,
        'height' => 2.0,
        ...$packageAttributes,
    ]);

    $product = Product::factory()->create(['weight' => 0.5]);
    $shipmentItem = $shipment->shipmentItems()->create([
        'product_id' => $product->id,
        'source_item_id' => 'AMAZON-ITEM-123',
        'quantity' => 1,
        'value' => 13.99,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package->fresh();
}

function amazonAdapter(): AmazonBuyShippingAdapter
{
    return new AmazonBuyShippingAdapter;
}

beforeEach(function (): void {
    Setting::updateOrCreate(['key' => 'require_mfa'], ['value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    Cache::put('amazon_sp_api_access_token_'.md5('test-refresh-token'), 'test-access-token', 3600);

    $this->source = amazonBuyShippingSource();
    $this->package = amazonBuyShippingPackage($this->source);
});

it('quotes every eligible offer under the carrier Amazon named for it', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $rates = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);

    expect($rates->pluck('carrier')->all())->toBe(['OnTrac', 'UPS'])
        ->and($rates->pluck('serviceCode')->all())->toBe(['ONTRAC_MFN_GROUND', 'UPS_PTP_NEXT_DAY_AIR_SAVER'])
        ->and($rates->first()->price)->toBe(5.79)
        ->and($rates->first()->serviceName)->toBe('OnTrac Ground')
        ->and($rates->first()->deliveryDate)->toBe('2026-09-08T06:59:59Z');
});

it('sends a getRates body that conforms to the published Shipping v2 schema', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);

    Saloon::assertSent(function (GetShippingRates $request): bool {
        $body = $request->body()->all();

        assertMatchesSpApiSchema($body, 'GetRatesRequest', 'shippingV2');

        return $body['channelDetails'] === [
            'channelType' => 'AMAZON',
            'amazonOrderDetails' => ['orderId' => '111-2222222-3333333'],
        ]
            && $body['packages'][0]['packageClientReferenceId'] === (string) $this->package->id
            && $body['packages'][0]['items'][0]['itemIdentifier'] === 'AMAZON-ITEM-123'
            && $request->headers()->get('x-amzn-shipping-business-id') === 'AmazonShipping_US';
    });
});

it('keeps the tokens that can spend money out of the rate and in the offer', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $rates = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);
    $offer = ShippingOffer::where('public_id', $rates->first()->offerId)->firstOrFail();

    expect($rates)->toHaveCount(2)
        ->and($rates->pluck('offerId')->filter()->unique())->toHaveCount(2)
        ->and($offer->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and($offer->postage_data_source_id)->toBe($this->source->id)
        ->and($offer->carrier)->toBe('OnTrac')
        ->and($offer->purchase_context['rateId'])->toBe('083bd8f2-960d-43a6-83ca-af5d4c578842')
        ->and($offer->purchase_context['requestToken'])->toBe('amzn1.rq.ca171d6e-d13b-4973-993b-6461775')
        ->and($offer->expires_at)->not->toBeNull()
        // Amazon publishes no expiry, so the window is tracked from our side and
        // deliberately closes inside Amazon's ten minutes.
        ->and($offer->expires_at->lessThan(now()->addMinutes(10)))->toBeTrue()
        // The one field that can buy a label never reaches browser state.
        ->and($rates->first()->toArray())->not->toHaveKey('purchase_context')
        ->and($offer->toArray())->not->toHaveKey('purchase_context');
});

it('tags every rate with the discovered service it is an offer of', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $rates = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);
    $identity = $rates->first()->observedService;

    expect($identity)->not->toBeNull()
        ->and($identity->source)->toBe('amazon')
        ->and($identity->environment)->toBe(SourceEnvironment::Production)
        ->and($identity->externalCarrierId)->toBe('ONTRAC')
        ->and($identity->externalServiceId)->toBe('ONTRAC_MFN_GROUND')
        ->and($rates->every(fn (RateResponse $rate): bool => $rate->observedService !== null))->toBeTrue();
});

it('records the ineligible catalog alongside what was on offer, and authors nothing', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $carriersBefore = Carrier::count();
    $servicesBefore = CarrierService::count();

    amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);

    expect(ObservedService::count())->toBe(3)
        ->and(ObservedService::where('external_carrier_id', 'ONTRAC')->value('last_eligible_at'))->not->toBeNull()
        ->and(ObservedService::where('external_carrier_id', '4PX')->value('last_eligible_at'))->toBeNull()
        ->and(ObservedService::where('external_carrier_id', '4PX')->value('external_service_name'))
        ->toBe('AMZ-4PX Global Express Priority-Li')
        // ADR-0003 decision 2: promotion into the authored catalog is a human act.
        ->and(Carrier::count())->toBe($carriersBefore)
        ->and(CarrierService::count())->toBe($servicesBefore);
});

it('names a rate by the carrier service somebody mapped it to', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    // Seen once so there is an identity to map, then mapped by hand.
    amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);

    $usps = Carrier::firstOrCreate(['name' => 'USPS']);
    $groundAdvantage = $usps->carrierServices()->create([
        'name' => 'Ground Advantage',
        'service_code' => 'USPS_GROUND_ADVANTAGE',
    ]);

    app(ObservedServiceMapper::class)->map(
        ObservedService::where('external_carrier_id', 'ONTRAC')->firstOrFail(),
        $groundAdvantage,
    );

    $rates = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);

    expect($rates->first()->carrier)->toBe('USPS')
        ->and($rates->first()->serviceCode)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($rates->first()->serviceName)->toBe('Ground Advantage')
        // The identity is unchanged by naming it: approval is still keyed on
        // what Amazon called it.
        ->and($rates->first()->observedService->externalServiceId)->toBe('ONTRAC_MFN_GROUND');
});

it('drops an offer that cannot honour a hard-required signature, and keeps the one that can', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $request = RateRequest::fromPackage($this->package)
        ->withSpecialServiceCodes(['signature_required']);

    $rates = amazonAdapter()->getRates($request, []);

    // OnTrac offers no Confirmation group at all; UPS offers one.
    expect($rates->pluck('carrier')->all())->toBe(['UPS']);
});

it('drops an offer it could not print, before any money is spent', function (): void {
    $pngOnly = amazonEligibleRates();
    $pngOnly[0]['supportedDocumentSpecifications'] = [[
        'format' => 'PNG',
        'size' => ['width' => 4.0, 'length' => 6.0, 'unit' => 'INCH'],
        'printOptions' => [['supportedDPIs' => [], 'supportedPageLayouts' => ['LEFT'], 'supportedFileJoiningOptions' => [true], 'supportedDocumentDetails' => [['name' => 'LABEL', 'isMandatory' => true]]]],
    ]];

    Saloon::fake([GetShippingRates::class => amazonRatesResponse($pngOnly)]);

    $rates = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);

    expect($rates->pluck('carrier')->all())->toBe(['UPS']);
});

it('buys the offer that was chosen and records what Amazon called the shipment', function (): void {
    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse(),
        PurchaseShipment::class => amazonPurchaseResponse(),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'zpl',
        labelDpi: 300,
    ));

    $package = $this->package->fresh();

    expect($result->success)->toBeTrue()
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->tracking_number)->toBe('D10012345678901')
        // The carrier of record is who is carrying it; the postage source is who
        // sold it. ADR-0002's split, on one row.
        ->and($package->carrier)->toBe('OnTrac')
        ->and($package->service)->toBe('OnTrac Ground')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and((float) $package->cost)->toBe(5.79)
        ->and($package->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and($package->postage_data_source_id)->toBe($this->source->id)
        ->and($package->carrier_account_id)->toBeNull()
        ->and($package->label_format)->toBe('zpl')
        ->and($package->metadata['amazon_shipment_id'])->toBe('amzn1.sid.abc123')
        ->and($package->metadata['amazon_carrier_id'])->toBe('ONTRAC')
        // The offer is spent and settled against Amazon's own identifier, not
        // against the weaker tracking-number backstop.
        ->and(ShippingOffer::where('public_id', $rate->offerId)->value('purchase_reference'))
        ->toBe('amzn1.sid.abc123');

    Saloon::assertSent(function (PurchaseShipment $request) use ($rate): bool {
        $body = $request->body()->all();

        assertMatchesSpApiSchema($body, 'PurchaseShipmentRequest', 'shippingV2');

        return $body['rateId'] === '083bd8f2-960d-43a6-83ca-af5d4c578842'
            && $body['requestToken'] === 'amzn1.rq.ca171d6e-d13b-4973-993b-6461775'
            && $body['requestedDocumentSpecification']['format'] === 'ZPL'
            && $body['requestedDocumentSpecification']['size'] === ['width' => 4.0, 'length' => 6.0, 'unit' => 'INCH']
            && $body['requestedDocumentSpecification']['dpi'] === 300
            // ZPL carries the label alone, and joining is the only option
            // the rate published — `false` is refused (`10`).
            && $body['requestedDocumentSpecification']['requestedDocumentTypes'] === ['LABEL']
            && $body['requestedDocumentSpecification']['needFileJoining'] === true
            // The idempotency key is the offer, which is what lets an
            // unanswered purchase be asked about instead of repeated.
            && $request->headers()->get('x-amzn-IdempotencyKey') === $rate->offerId;
    });
});

it('asks for a 4x6 label even where the rate also offers letter size', function (): void {
    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse(),
        PurchaseShipment::class => amazonPurchaseResponse(format: 'PDF'),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'pdf',
    ));

    Saloon::assertSent(function (PurchaseShipment $request): bool {
        $spec = $request->body()->all()['requestedDocumentSpecification'];

        return $spec['format'] === 'PDF'
            && $spec['size'] === ['width' => 4.0, 'length' => 6.0, 'unit' => 'INCH']
            // PDF is offered with no DPI list, so none is asked for.
            && ! array_key_exists('dpi', $spec)
            // Every PDF print option makes the pack slip mandatory, and a
            // request naming the label alone is refused — so it is asked
            // for, joined, which is the only joining option published.
            && $spec['requestedDocumentTypes'] === ['PACKSLIP', 'LABEL']
            && $spec['needFileJoining'] === true;
    });
});

it('does not take a lone document that Amazon did not call a label', function (): void {
    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse(),
        PurchaseShipment::class => MockResponse::make(['payload' => [
            'shipmentId' => 'amzn1.sid.packsliponly',
            'packageDocumentDetails' => [[
                'packageClientReferenceId' => '1',
                'trackingId' => 'D10012345678901',
                'packageDocuments' => [['type' => 'PACKSLIP', 'format' => 'PDF', 'contents' => base64_encode('PACKSLIP-BYTES')]],
            ]],
        ]]),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'pdf',
    ));

    // Paid for, so the failure names the shipment that can void it — but a
    // pack slip is never recorded and printed as the label.
    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('amzn1.sid.packsliponly')
        ->and($this->package->fresh()->label_data)->toBeNull()
        ->and(ShippingOffer::where('public_id', $rate->offerId)->value('purchase_reference'))->toBe('amzn1.sid.packsliponly');
});

it('leaves a document unjoined only where the rate allows it', function (): void {
    $unjoined = amazonEligibleRates();
    $unjoined[0]['supportedDocumentSpecifications'][3]['printOptions'][0]['supportedFileJoiningOptions'] = [false, true];

    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse($unjoined),
        PurchaseShipment::class => amazonPurchaseResponse(format: 'PDF'),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'pdf',
    ));

    Saloon::assertSent(fn (PurchaseShipment $request): bool => $request->body()->all()['requestedDocumentSpecification']['needFileJoining'] === false);
});

it('buys the confirmation a shipment requires, and an explicit refusal when it requires none', function (): void {
    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse(),
        PurchaseShipment::class => amazonPurchaseResponse(),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])
        ->firstWhere('carrier', 'UPS');

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'zpl',
    ));

    // The Confirmation group is `isRequired`, and nothing was asked for, so the
    // honest and cheapest answer is the explicit "no".
    Saloon::assertSent(fn (PurchaseShipment $request): bool => $request->body()->all()['requestedValueAddedServices']
        === [['id' => 'NO_CONFIRMATION']]);
});

it('answers a required group with its cheapest option when it offers no refusal', function (): void {
    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse([amazonUspsRate()]),
        PurchaseShipment::class => amazonPurchaseResponse(),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    expect($rate)->not->toBeNull();

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'zpl',
    ));

    // USPS's group has no NO_CONFIRMATION; leaving it unanswered, or naming an
    // option it does not offer, is refused by Amazon. Delivery confirmation is
    // free, so it is what "nothing asked for" buys here.
    Saloon::assertSent(function (PurchaseShipment $request): bool {
        $body = $request->body()->all();

        assertMatchesSpApiSchema($body, 'PurchaseShipmentRequest', 'shippingV2');

        return $body['requestedValueAddedServices'] === [['id' => 'DELIVERY_CONFIRMATION']];
    });
});

it('prefers the least demanding option when a required group offers several for free', function (): void {
    // Priority Mail Express, as captured: a signature at $0 listed first.
    $expressLike = amazonUspsRate([
        ['id' => 'SIGNATURE_CONFIRMATION', 'name' => 'Signature confirmation', 'cost' => ['unit' => 'USD', 'value' => 0]],
        ['id' => 'DELIVERY_CONFIRMATION', 'name' => 'Delivery confirmation', 'cost' => ['unit' => 'USD', 'value' => 0]],
    ]);

    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse([$expressLike]),
        PurchaseShipment::class => amazonPurchaseResponse(),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'zpl',
    ));

    // Free is not harmless: a signature nobody asked for is a parcel that
    // comes back when nobody is home.
    Saloon::assertSent(fn (PurchaseShipment $request): bool => $request->body()->all()['requestedValueAddedServices']
        === [['id' => 'DELIVERY_CONFIRMATION']]);
});

it('drops an offer whose required group can only be answered with a surcharge nobody asked for', function (): void {
    $paidOnly = amazonUspsRate([
        ['id' => 'SIGNATURE_CONFIRMATION', 'name' => 'Signature confirmation', 'cost' => ['unit' => 'USD', 'value' => 4.15]],
        ['id' => 'ADULT_SIGNATURE_CONFIRMATION', 'name' => 'Adult signature confirmation', 'cost' => ['unit' => 'USD', 'value' => 9.35]],
    ]);

    Saloon::fake([GetShippingRates::class => amazonRatesResponse([...amazonEligibleRates(), $paidOnly])]);

    $rates = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), []);

    // Buying it would have meant a $4.15 signature the quoted price did not
    // include; the offers beside it are unaffected.
    expect($rates->pluck('carrier')->all())->toBe(['OnTrac', 'UPS']);
});

it('keeps a paid-only required group when the shipment asked for that service', function (): void {
    $paidOnly = amazonUspsRate([
        ['id' => 'SIGNATURE_CONFIRMATION', 'name' => 'Signature confirmation', 'cost' => ['unit' => 'USD', 'value' => 4.15]],
    ]);

    Saloon::fake([GetShippingRates::class => amazonRatesResponse([$paidOnly])]);

    $request = RateRequest::fromPackage($this->package)->withSpecialServiceCodes(['signature_required']);

    expect(amazonAdapter()->getRates($request, [])->pluck('carrier')->all())->toBe(['USPS']);
});

it('records the resolution Amazon generated, not the one the device asked for', function (): void {
    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse(),
        PurchaseShipment::class => amazonPurchaseResponse(),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    // The device prints at 203; this rate publishes ZPL at 300 and nothing else.
    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'zpl',
        labelDpi: 203,
    ));

    // Recording 203 against 300 DPI bytes would print the label at the wrong
    // physical size, and the package is what a reprint reads.
    expect($this->package->fresh()->label_dpi)->toBe(300);

    Saloon::assertSent(fn (PurchaseShipment $request): bool => $request->body()->all()['requestedDocumentSpecification']['dpi'] === 300);
});

it('buys on the Amazon account that quoted the offer, not the one the shipment now points at', function (): void {
    Cache::put('amazon_sp_api_access_token_'.md5('test-refresh-token'), 'token-for-account-a', 3600);
    Cache::put('amazon_sp_api_access_token_'.md5('other-refresh-token'), 'token-for-account-b', 3600);

    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse(),
        PurchaseShipment::class => amazonPurchaseResponse(),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    // The shipment is re-pointed at a second Amazon account between quote and
    // purchase. Account A's requestToken and rateId mean nothing to account B.
    $accountB = amazonBuyShippingSource();
    $accountB->update(['secret_settings' => [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'other-refresh-token',
    ]]);
    $this->package->shipment->update(['data_source_id' => $accountB->id]);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package->fresh(), new PackageShippingRequest(
        selectedRate: $rate,
    ));

    expect($result->success)->toBeTrue()
        ->and($this->package->fresh()->postage_data_source_id)->toBe($this->source->id);

    Saloon::assertSent(fn (PurchaseShipment $request, $response): bool => $response
        ->getPendingRequest()
        ->headers()
        ->get('x-amz-access-token') === 'token-for-account-a');
});

it('refuses rather than buying when the account that quoted the offer is gone', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    $this->source->update(['active' => false]);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package->fresh(), new PackageShippingRequest(
        selectedRate: $rate,
    ));

    expect($result->success)->toBeFalse()
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Unshipped)
        // Amazon answered nothing because it was never asked, so the offer
        // resolves as declined and the package is free to be quoted again.
        ->and(ShippingOffer::where('public_id', $rate->offerId)->value('purchase_failed_at'))->not->toBeNull();
});

it('skips a settled confirmation even when the credentials to send one are gone', function (): void {
    $package = amazonShippedPackage($this->source);

    // Rotated out, or revoked, after the label was bought. There is nothing
    // left to send, so an export that failed here would strand a correctly
    // shipped package as permanently unexported.
    $this->source->update(['secret_settings' => []]);

    expect(app(PackageExportService::class)->exportPackage($package)->success)->toBeTrue()
        ->and($package->fresh()->exported)->toBeTrue();

    Saloon::assertNothingSent();
});

it('refuses to buy an Amazon rate that carries no offer behind it', function (): void {
    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: new RateResponse(
            carrier: AmazonBuyShippingAdapter::SOURCE_NAME,
            serviceCode: 'ONTRAC_MFN_GROUND',
            serviceName: 'OnTrac Ground',
            price: 0.01,
        ),
    ));

    expect($result->success)->toBeFalse()
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);

    Saloon::assertNothingSent();
});

it('re-quotes instead of failing the packer when the chosen offer has expired', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    ShippingOffer::where('public_id', $rate->offerId)->update(['expires_at' => now()->subMinute()]);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
    ));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Rate Expired')
        // What the Ship page acts on: a fresh quote is the whole remedy, so it
        // produces one rather than telling a packer to.
        ->and($result->requiresRequote)->toBeTrue()
        ->and($result->leavePackageIntact)->toBeTrue()
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('never re-quotes automatically for an offer that was already spent', function (): void {
    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse(),
        PurchaseShipment::class => amazonPurchaseResponse(),
    ]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $rate));

    // A second attempt on the same offer. A label exists, so re-quoting would
    // invite a second one — somebody has to look first.
    $result = app(EloquentPackageShippingWorkflow::class)->ship(
        $this->package->fresh(),
        new PackageShippingRequest(selectedRate: $rate),
    );

    expect($result->success)->toBeFalse()
        ->and($result->requiresRequote)->toBeFalse();
});

it('recovers a purchase whose reply never arrived instead of buying a second label', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();
    $offer = ShippingOffer::where('public_id', $rate->offerId)->firstOrFail();

    // Spent, with nothing heard back: the state a timeout leaves behind, and the
    // one that used to block the package for good.
    $offer->forceFill(['consumed_at' => now()])->save();

    Saloon::fake([
        GetShippingRates::class => amazonRatesResponse(),
        PurchaseShipment::class => amazonPurchaseResponse(),
    ]);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
    ));

    $package = $this->package->fresh();

    expect($result->success)->toBeTrue()
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->tracking_number)->toBe('D10012345678901')
        ->and($offer->fresh()->purchase_reference)->toBe('amzn1.sid.abc123');

    // One quote and one purchase. The recovery *is* the retry, recognized by
    // the idempotency key — a second `purchaseShipment` would be a second label.
    Saloon::assertSentCount(2);
});

it('keeps the package blocked when the source cannot say whether a label exists', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();
    ShippingOffer::where('public_id', $rate->offerId)->update(['consumed_at' => now()]);

    Saloon::fake([
        PurchaseShipment::class => MockResponse::make([
            'errors' => [['code' => 'TOKEN_EXPIRED', 'message' => 'The request token has expired.']],
        ], 400),
    ]);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(
        selectedRate: $rate,
    ));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Earlier Purchase Unresolved')
        ->and($result->requiresRequote)->toBeFalse()
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('voids through Amazon with the shipment id Amazon issued', function (): void {
    Saloon::fake([CancelAmazonShipment::class => MockResponse::make(['payload' => []])]);

    $package = amazonShippedPackage($this->source);

    $response = app(PostageSourceDispatcher::class)->voidLabel($package);

    expect($response->success)->toBeTrue();

    Saloon::assertSent(fn (CancelAmazonShipment $request): bool => $request->resolveEndpoint()
        === '/shipping/v2/shipments/amzn1.sid.abc123/cancel');
});

it('tracks through Amazon with the carrier identifier it recorded', function (): void {
    Saloon::fake([GetShipmentTracking::class => MockResponse::make(['payload' => [
        'trackingId' => 'D10012345678901',
        'alternateLegTrackingId' => null,
        'promisedDeliveryDate' => '2026-09-08T06:59:59Z',
        'summary' => ['status' => 'OutForDelivery'],
        'eventHistory' => [
            ['eventCode' => 'PickupDone', 'eventTime' => '2026-09-06T15:00:00Z', 'location' => ['city' => 'Kent', 'stateOrRegion' => 'WA', 'countryCode' => 'US']],
            ['eventCode' => 'OutForDelivery', 'eventTime' => '2026-09-08T13:00:00Z', 'location' => ['city' => 'Seattle', 'stateOrRegion' => 'WA', 'countryCode' => 'US']],
        ],
    ]])]);

    $package = amazonShippedPackage($this->source);

    $response = app(TrackingService::class)->refreshPackage($package);

    expect($response->success)->toBeTrue()
        ->and($response->status->value)->toBe('out_for_delivery')
        ->and($response->events)->toHaveCount(2)
        // Newest first, and located from Amazon's own vocabulary.
        ->and($response->events[0]->description)->toBe('Out for delivery')
        ->and($response->events[0]->location)->toBe('Seattle, WA, US');

    Saloon::assertSent(fn (GetShipmentTracking $request): bool => $request->query()->all() === [
        'trackingId' => 'D10012345678901',
        'carrierId' => 'ONTRAC',
    ]);
});

it('never puts an Amazon-bought parcel on a manifest of ours', function (): void {
    expect(app(PostageSourceDispatcher::class)->supportsPackageManifest(amazonShippedPackage($this->source)))
        ->toBeFalse();
});

it('does not confirm the Amazon order a second time for a Buy Shipping label', function (): void {
    Saloon::fake([ConfirmShipment::class => MockResponse::make([], 204)]);

    $package = amazonShippedPackage($this->source);

    $result = app(PackageExportService::class)->exportPackage($package);

    // The export succeeded — there was simply nothing left to tell Amazon. A
    // manual confirm here 400s on a Ship+ order and would mark the export
    // permanently failed for a package that shipped correctly.
    expect($result->success)->toBeTrue()
        ->and($package->fresh()->exported)->toBeTrue();

    Saloon::assertNothingSent();
});

it('still confirms the Amazon order for a label bought somewhere else', function (): void {
    Saloon::fake([ConfirmShipment::class => MockResponse::make([], 204)]);

    $package = amazonShippedPackage($this->source, [
        'carrier' => 'USPS',
        'postage_source' => PostageSource::CarrierAccount,
        'postage_data_source_id' => null,
        'metadata' => null,
    ]);

    expect(app(PackageExportService::class)->exportPackage($package)->success)->toBeTrue();

    Saloon::assertSent(ConfirmShipment::class);
});

/**
 * A parcel shipped on Amazon Buy Shipping: OnTrac carrying it, Amazon having
 * sold the postage, and the two Amazon identifiers everything afterwards is
 * keyed on.
 */
function amazonShippedPackage(DataSource $source, array $attributes = []): Package
{
    $package = amazonBuyShippingPackage($source, [
        'status' => PackageStatus::Shipped,
        'shipped_at' => now(),
        'tracking_number' => 'D10012345678901',
        'carrier' => 'OnTrac',
        'service' => 'OnTrac Ground',
        'service_evidence' => ServiceEvidence::Confirmed,
        'cost' => 5.79,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => $source->id,
        'metadata' => [
            AmazonBuyShippingAdapter::SHIPMENT_ID_KEY => 'amzn1.sid.abc123',
            AmazonBuyShippingAdapter::CARRIER_ID_KEY => 'ONTRAC',
        ],
        ...$attributes,
    ]);

    return $package->fresh();
}

it('holds an offer nothing can be asked to buy any more', function (): void {
    Saloon::fake([GetShippingRates::class => amazonRatesResponse()]);

    $rate = amazonAdapter()->getRates(RateRequest::fromPackage($this->package), [])->first();

    // The source is re-pointed at a driver that sells no postage between the
    // quote and the purchase. Falling back to the carrier here would buy the
    // label on an account of ours that never quoted this price.
    $this->source->update(['source_type' => 'App\\Services\\ShipmentImport\\Sources\\DatabaseSource']);
    app(OfferStore::class);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($this->package->fresh(), new PackageShippingRequest(
        selectedRate: $rate,
    ));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Rate Not Purchasable')
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);
});
