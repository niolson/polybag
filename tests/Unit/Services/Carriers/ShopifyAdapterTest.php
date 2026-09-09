<?php

use App\Contracts\BlindPurchaseSource;
use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Exceptions\ShopifyDeclaredWeightException;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\Carrier;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\Carriers\ShopifyAdapter;
use Database\Seeders\CarrierSeeder;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new ShopifyAdapter;
});

it('is configured only while an active Shopify data source exists', function (): void {
    expect($this->adapter->isConfigured())->toBeFalse();

    $source = createShopifyDataSource();

    expect($this->adapter->isConfigured())->toBeTrue();

    $source->update(['active' => false]);

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('does not quote at all', function (): void {
    // ADR-0003 decision 6: a source that cannot state a carrier, a service or a
    // price has no business returning something shaped like a rate.
    expect($this->adapter)->not->toBeInstanceOf(CarrierAdapterInterface::class)
        ->and($this->adapter)->toBeInstanceOf(BlindPurchaseSource::class)
        ->and(method_exists($this->adapter, 'getRates'))->toBeFalse()
        ->and(method_exists($this->adapter, 'resolvePreSelectedRate'))->toBeFalse();
});

it('advertises catalogued selections as priceless offers for a Shopify-sourced package', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    allowBlindPurchase($package);

    $offers = $this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto', 'usps:usps_ground_advantage']);

    expect($offers)->toHaveCount(2)
        ->and($offers->pluck('serviceCode')->all())->toBe(['auto', 'usps:usps_ground_advantage'])
        ->and($offers->every(fn (BlindPurchaseOffer $offer): bool => $offer->source === 'Shopify'))->toBeTrue()
        ->and($offers->first()->sourceLabel)->toBe('Shopify Shipping')
        ->and($offers->first()->selectionLabel)->toBe("Shopify's choice")
        ->and($offers->first()->postageDataSourceId)->toBe($package->shipment->data_source_id);
});

it('advertises nothing for a client that has not opted into blind purchase', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto']))->toBeEmpty();
});

it('advertises nothing for a package that has no Shopify fulfillment order', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage(['metadata' => []]);
    allowBlindPurchase($package);

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto']))->toBeEmpty();
});

it('advertises nothing once the Shopify data source is deactivated', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    allowBlindPurchase($package);
    $package->shipment->dataSource->update(['active' => false]);

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package->fresh()), ['auto']))->toBeEmpty();
});

it('advertises nothing once another package on the shipment has shipped', function (): void {
    // One fulfillment order buys one label, and the fulfillment order is
    // recorded on the shipment — so the second package would ask Shopify to
    // fulfill what it has already fulfilled.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    allowBlindPurchase($package);
    Package::factory()->shipped()->create(['shipment_id' => $package->shipment_id]);

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto']))->toBeEmpty();
});

it('still advertises when the shipment\'s other label was voided', function (): void {
    // Voiding reopens the fulfillment order, so there is a label to buy again.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    allowBlindPurchase($package);
    Package::factory()->shipped()->create([
        'shipment_id' => $package->shipment_id,
        'status' => PackageStatus::Void,
    ]);

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto']))->toHaveCount(1);
});

it('advertises nothing while a sibling package holds a label bought by an earlier attempt', function (): void {
    // The dangerous case, and the one status alone misses: Shopify has sold the
    // label and the shop has been charged, but the download failed, so the
    // sibling is still `Unshipped`. The marker is the only evidence.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    allowBlindPurchase($package);
    Package::factory()->create([
        'shipment_id' => $package->shipment_id,
        'status' => PackageStatus::Unshipped,
        'metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1'],
    ]);

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto']))->toBeEmpty();
});

it('advertises nothing while a sibling package has a purchase still in flight', function (): void {
    // Persisted the moment Shopify accepts the mutation, before any label
    // exists. A purchase nobody has resolved yet is still a purchase.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    allowBlindPurchase($package);
    Package::factory()->create([
        'shipment_id' => $package->shipment_id,
        'status' => PackageStatus::Unshipped,
        'metadata' => ['shopify_purchase_result_id' => 'gid://shopify/ShippingLabelPurchaseResult/1'],
    ]);

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto']))->toBeEmpty();
});

it('advertises again once a voided sibling has had its purchase markers cleared', function (): void {
    // What `ShopifyFulfillmentSynchronizer::applyVoid()` leaves behind: void
    // status, no markers, fulfillment order reopened at Shopify.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    allowBlindPurchase($package);
    Package::factory()->shipped()->create([
        'shipment_id' => $package->shipment_id,
        'status' => PackageStatus::Void,
        'metadata' => ['shopify_tracking_company' => 'USPS'],
    ]);

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto']))->toHaveCount(1);
});

it('still advertises when the shipment\'s other package is an open draft', function (): void {
    // Two drafts is a packing state, not a purchase collision.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    allowBlindPurchase($package);
    Package::factory()->create([
        'shipment_id' => $package->shipment_id,
        'status' => PackageStatus::Unshipped,
    ]);

    expect($this->adapter->blindPurchaseOffers(RateRequest::fromPackage($package), ['auto']))->toHaveCount(1);
});

it('refuses to buy from a request that carries a rate instead of a blind purchase', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    $response = $this->adapter->createShipment(ShipRequest::fromPackageAndRate($package, new RateResponse(
        carrier: 'Shopify',
        serviceCode: 'auto',
        serviceName: "Shopify's choice",
        price: 0.0,
        priceUnknown: true,
    )));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('blind purchase');
});

it('offers only the Ground Saver tier that matches the package weight', function (float $weight, array $expected): void {
    // UPS quotes exactly one of 92/93 for a parcel, by weight. A blind offer has
    // no rate to filter, so advertising both would show the packer two lines
    // with nothing to choose between them, one certain to fail after the
    // purchase is confirmed.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['weight' => $weight]);
    allowBlindPurchase($package);

    $offers = $this->adapter->blindPurchaseOffers(
        RateRequest::fromPackage($package->fresh()),
        ['ups_shipping:92', 'ups_shipping:93', 'usps:GroundAdvantage'],
    );

    expect($offers->pluck('serviceCode')->all())->toBe($expected);
})->with([
    'under a pound' => [0.3, ['ups_shipping:92', 'usps:GroundAdvantage']],
    'exactly a pound goes to the upper tier' => [1.0, ['ups_shipping:93', 'usps:GroundAdvantage']],
    'over a pound' => [5.0, ['ups_shipping:93', 'usps:GroundAdvantage']],
]);

it('withdraws both Ground Saver tiers when the package has no weight', function (): void {
    // Nothing can be bought without a weight — Shopify answers TOTAL_WEIGHT_ZERO
    // — so guessing a tier would only move the failure later.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['weight' => 0]);
    allowBlindPurchase($package);

    $offers = $this->adapter->blindPurchaseOffers(
        RateRequest::fromPackage($package->fresh()),
        ['ups_shipping:92', 'ups_shipping:93', 'usps:GroundAdvantage'],
    );

    expect($offers->pluck('serviceCode')->all())->toBe(['usps:GroundAdvantage']);
});

it('gives the two Ground Saver tiers labels a packer can tell apart', function (): void {
    $this->seed(CarrierSeeder::class);

    $names = Carrier::query()
        ->where('name', ShopifyAdapter::CARRIER_NAME)
        ->firstOrFail()
        ->carrierServices()
        ->whereIn('service_code', ['ups_shipping:92', 'ups_shipping:93'])
        ->pluck('name', 'service_code');

    expect($names->get('ups_shipping:92'))->not->toBe($names->get('ups_shipping:93'))
        ->and($names->get('ups_shipping:92'))->toContain('under 1 lb')
        ->and($names->get('ups_shipping:93'))->toContain('1 lb and over');
});

it('splits a service code into the parts Shopify selects a rate with', function (): void {
    // Confirmed pairs, not invented ones: Shopify finds no rate for
    // `usps_ground_advantage` and one for `GroundAdvantage`, and reads UPS's
    // numeric codes as they stand.
    expect($this->adapter->splitServiceCode('usps:GroundAdvantage'))->toBe(['usps', 'GroundAdvantage'])
        ->and($this->adapter->splitServiceCode('ups_shipping:92'))->toBe(['ups_shipping', '92'])
        ->and($this->adapter->splitServiceCode('auto'))->toBe([null, null])
        ->and($this->adapter->splitServiceCode('usps:'))->toBe([null, null]);
});

it('buys a label and reports the format Shopify chose', function (string $shopifyFormat, string $expectedFormat): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased($shopifyFormat)),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('9400111899223197428490')
        ->and($response->labelFormat)->toBe($expectedFormat)
        ->and(base64_decode($response->labelData))->toBe('LABEL-BYTES')
        ->and($response->carrier)->toBe('USPS');
})->with([
    'PDF' => ['PDF', 'pdf'],
    'ZPL' => ['ZPL', 'zpl'],
]);

it('records no cost, because Shopify never reports what a label cost', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->cost)->toBeNull();

    $package->markShipped($response, $response->postageSource);

    expect($package->refresh()->cost)->toBeNull();
});

it('records the postage as bought through the shipment\'s Shopify data source', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->postageSource)->toBe(PostageSource::PostageDataSource)
        ->and($response->postageDataSourceId)->toBe($package->shipment->data_source_id)
        ->and($response->carrierAccountId)->toBeNull();

    $package->markShipped($response, $response->postageSource);
    $package->refresh();

    expect($package->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and($package->postage_data_source_id)->toBe($package->shipment->data_source_id)
        ->and($package->carrier_account_id)->toBeNull();
});

it('sends the chosen carrier and service as a preferred rate selection', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $this->adapter->createShipment(shopifyShipRequest($package, 'usps:usps_ground_advantage'));

    Saloon::assertSent(function (GraphQL $request): bool {
        $selection = $request->body()->all()['variables']['input']['preferredRateSelection'] ?? null;

        return $selection === ['carrierCode' => 'usps', 'serviceCode' => 'usps_ground_advantage'];
    });
});

it('leaves the rate to Shopify when the auto service is chosen', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $this->adapter->createShipment(shopifyShipRequest($package));

    Saloon::assertSent(function (GraphQL $request): bool {
        $body = $request->body()->all();

        return ! isset($body['variables']['input']) || ! array_key_exists('preferredRateSelection', $body['variables']['input']);
    });
});

it('reports the validation error when Shopify rejects the purchase', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make([
            'data' => [
                'shippingLabelPurchase' => [
                    'shippingLabelPurchaseResult' => null,
                    'userErrors' => [[
                        'field' => null,
                        'code' => 'TERMS_OF_SERVICE_NOT_ACCEPTED',
                        'message' => 'Shopify Shipping terms of service have not been accepted.',
                    ]],
                ],
            ],
        ]),
    ]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('terms of service');
});

it('reports the carrier failure when the purchase job fails', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make([
            'data' => [
                'node' => [
                    'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
                    'status' => 'PURCHASE_FAILED',
                    'done' => true,
                    'errors' => [['code' => 'CARRIER_NOT_AVAILABLE', 'message' => 'The carrier is not available for this label.']],
                    'shippingLabels' => [],
                ],
            ],
        ]),
    ]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('carrier is not available');
});

it('refuses to buy a label for a shipment that did not come from Shopify', function (): void {
    seedShopifyCarrierServices();
    $package = Package::factory()->create(['shipment_id' => Shipment::factory()->create()]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('active Shopify data source');
});

it('records the carrier Shopify actually picked, not the one that was asked for', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        // Asked for USPS; Shopify bought DHL eCommerce, a carrier PolyBag has
        // no account with and no catalogued service for.
        MockResponse::make(purchasePurchased('PDF', 'DHL eCommerce')),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package, 'usps:usps_ground_advantage'));

    expect($response->carrier)->toBe('DHL eCommerce')
        ->and($response->metadata['shopify_tracking_company'])->toBe('DHL eCommerce')
        ->and($response->metadata['shopify_requested_service_code'])->toBe('usps:usps_ground_advantage');

    $package->markShipped($response, $response->postageSource);
    $package->refresh();

    expect($package->carrier)->toBe('DHL eCommerce')
        ->and($package->isShopifyShipped())->toBeTrue()
        ->and($package->metadata['shopify_shipping_label_id'])->toBe('gid://shopify/ShippingLabel/1');
});

it('translates the carrier code Shopify reports into a carrier name', function (): void {
    seedShopifyCarrierServices();
    $ups = Carrier::factory()->ups()->create();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        // `trackingInfo.company` on a ShippingLabel is Shopify's own carrier
        // code, not a carrier name. Recorded raw it reached a shipped package as
        // `ups_shipping` and normalized to nothing, leaving a UPS parcel with no
        // carrier of record.
        MockResponse::make(purchasePurchased('PDF', 'ups_shipping')),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->carrier)->toBe('UPS')
        // The raw string stays in metadata: it is the record of what Shopify said.
        ->and($response->metadata['shopify_tracking_company'])->toBe('ups_shipping');

    $package->markShipped($response, $response->postageSource);

    expect($package->refresh()->normalizedCarrier?->is($ups))->toBeTrue();
});

it('leaves a carrier Shopify names rather than codes alone', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased('PDF', 'DHL eCommerce')),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    expect($this->adapter->createShipment(shopifyShipRequest($package))->carrier)->toBe('DHL eCommerce');
});

it('leaves the service unknown and keeps the selection as a requested preference', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package, 'usps:usps_ground_advantage'));

    expect($response->service)->toBeNull()
        ->and($response->serviceEvidence)->toBe(ServiceEvidence::Unknown)
        ->and($response->requestedService)->toBe('USPS Ground Advantage');

    $package->markShipped($response, $response->postageSource);

    expect($package->refresh()->service)->toBeNull()
        ->and($package->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->requested_service)->toBe('USPS Ground Advantage')
        // Nothing to publish outward: a preference is not a purchase.
        ->and($package->confirmedService())->toBeNull();
});

it('records no requested preference when the rate was left to Shopify', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->requestedService)->toBeNull()
        ->and($response->serviceEvidence)->toBe(ServiceEvidence::Unknown);
});

it('uses the requested carrier code when Shopify omits the tracking company', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased('PDF', null)),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package, 'usps:usps_ground_advantage'));

    expect($response->carrier)->toBe('USPS');
});

it('leaves the carrier unknown when Shopify omits it for an automatic purchase', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased('PDF', null)),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->carrier)->toBeNull();
});

it('keeps the metadata a package already carried when recording a Shopify label', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['metadata' => ['packed_by_station' => 'bench-3']]);

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));
    $package->markShipped($response, $response->postageSource);

    expect($package->refresh()->metadata)
        ->toHaveKey('packed_by_station', 'bench-3')
        ->toHaveKey('shopify_shipping_label_id');
});

it('keeps a purchase that Shopify completed when the label download fails', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('gone', 500)]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    // Shopify charged for this label and created the fulfillment. Losing the
    // ID here would mean paying twice to ship one parcel.
    expect($response->success)->toBeFalse()
        ->and($package->refresh()->metadata['shopify_shipping_label_id'])->toBe('gid://shopify/ShippingLabel/1')
        ->and($package->metadata['shopify_label_document_url'])->not->toBeNull();
});

it('records the purchase before polling, so a timeout leaves something to resume', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    config(['services.shopify.label_poll_attempts' => 1, 'services.shopify.label_poll_interval_ms' => 0]);

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        // Still pending when the attempts run out.
        MockResponse::make(['data' => ['node' => [
            'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
            'status' => 'PENDING_PURCHASE', 'done' => false, 'errors' => [], 'shippingLabels' => [],
        ]]]),
    ]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    // Shopify is buying a label right now. Without this marker the retry would
    // send a second purchase mutation.
    expect($response->success)->toBeFalse()
        ->and($package->refresh()->metadata['shopify_purchase_result_id'])
        ->toBe('gid://shopify/ShippingLabelPurchaseResult/1');
});

it('resumes an in-flight purchase rather than buying a second label', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['metadata' => ['shopify_purchase_result_id' => 'gid://shopify/ShippingLabelPurchaseResult/1']]);

    Saloon::fake([MockResponse::make(purchasePurchased())]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('9400111899223197428490');

    Saloon::assertSentCount(1);
    Saloon::assertSent(fn (GraphQL $request): bool => ! str_contains($request->body()->all()['query'], 'shippingLabelPurchase'));

    // Superseded by the label ID, so it can never be resumed a second time.
    expect($package->refresh()->metadata)->not->toHaveKey('shopify_purchase_result_id');
});

it('allows a fresh purchase after Shopify reports the job failed', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(['data' => ['node' => [
            'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
            'status' => 'PURCHASE_FAILED', 'done' => true,
            'errors' => [['code' => 'CARRIER_NOT_AVAILABLE', 'message' => 'The carrier is not available for this label.']],
            'shippingLabels' => [],
        ]]]),
    ]);

    $this->adapter->createShipment(shopifyShipRequest($package));

    // No label was bought, so nothing should be resumed on the next attempt.
    expect($package->refresh()->metadata)->not->toHaveKey('shopify_purchase_result_id');
});

it('recovers the label already bought instead of buying a second one', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1']]);

    Saloon::fake([
        MockResponse::make(['data' => ['shippingLabel' => purchasePurchased()['data']['node']['shippingLabels'][0]]]),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('9400111899223197428490')
        ->and(base64_decode($response->labelData))->toBe('LABEL-BYTES');

    // One read, no purchase.
    Saloon::assertSentCount(1);
    Saloon::assertSent(function (GraphQL $request): bool {
        return ! str_contains($request->body()->all()['query'], 'shippingLabelPurchase');
    });
});

it('refuses to buy again when a bought label can no longer be read', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1']]);

    Saloon::fake([MockResponse::make(['data' => ['shippingLabel' => null]])]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('already bought')
        ->and($response->errorMessage)->toContain('Check the order in Shopify');
});

/**
 * Shopify builds the customs declaration from its own catalogue and refuses a
 * label whose `totalWeight` falls below the sum of it, reporting the refusal as
 * `UNKNOWN_ERROR` after the box is taped shut. The numbers throughout are the
 * ones from package 207: a 0.15 lb box against 1.76 + 0.53 lb of declared goods.
 */
it('withholds an international purchase whose box weighs less than Shopify declares', function (): void {
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(0.15);

    Saloon::fake([MockResponse::make(declaredItemWeights([[1.76, 1], [0.53, 1]]))]);

    $withheld = withheldPurchase(fn () => $this->adapter->createShipment(shopifyShipRequest($package)));

    expect($withheld->declaredWeight)->toBe(2.29)
        ->and($withheld->packageWeight)->toBe(0.15)
        ->and($withheld->getMessage())->toContain('2.29 lb')
        ->and($withheld->getMessage())->toContain('0.15 lb')
        // The fix is somewhere else, and the message has to say where.
        ->and($withheld->getMessage())->toContain('Shopify admin')
        ->and($withheld->getMessage())->toContain('not from the scale');

    // Withheld, not attempted: a purchase that fails closes the fulfillment
    // order and forces a repoint (issue `18`), which is the cost of finding
    // this out from Shopify instead.
    Saloon::assertSent(fn (GraphQL $request): bool => ! str_contains($request->body()->all()['query'], 'shippingLabelPurchase'));
});

it('buys at the declared weight when the box falls short by less than the tolerance', function (): void {
    // Physically a packed box outweighs its contents, so a hundredth of a pound
    // the wrong way is the scale, not the catalogue — and Shopify will not take
    // the reading either way.
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(2.28);

    Saloon::fake([
        MockResponse::make(declaredItemWeights([[1.76, 1], [0.53, 1]])),
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    expect($this->adapter->createShipment(shopifyShipRequest($package))->success)->toBeTrue();

    Saloon::assertSent(fn (GraphQL $request): bool => sentTotalWeight($request) === 2.29);
});

it('sends the scale weight when the box outweighs what Shopify declares', function (): void {
    // The relation is `>=`, not `==`, and it had to be — fragile goods carry a
    // lot of packaging. Confirmed by purchase on shipment 6763: 6.11 lb of
    // declared goods in an 8.5 lb box bought `PURCHASED` on the first attempt.
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(3.4);

    Saloon::fake([
        MockResponse::make(declaredItemWeights([[1.76, 1], [0.53, 1]])),
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $this->adapter->createShipment(shopifyShipRequest($package));

    Saloon::assertSent(fn (GraphQL $request): bool => sentTotalWeight($request) === 3.4);
});

it('multiplies the declared unit weight by the quantity being fulfilled', function (): void {
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(1.0);

    Saloon::fake([MockResponse::make(declaredItemWeights([[0.5, 3]]))]);

    expect(withheldPurchase(fn () => $this->adapter->createShipment(shopifyShipRequest($package)))->declaredWeight)
        ->toBe(1.5);
});

it('sends the scale weight unchanged once the operator has insisted', function (): void {
    // Nothing is over-declared by insisting. What it buys is the case PolyBag
    // cannot see: a catalogue corrected between the refusal and the retry.
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(0.15);

    Saloon::fake([
        MockResponse::make(declaredItemWeights([[1.76, 1], [0.53, 1]])),
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $request = shopifyShipRequest($package)->withDeclaredWeightOverride();

    expect($this->adapter->createShipment($request)->success)->toBeTrue();

    Saloon::assertSent(fn (GraphQL $r): bool => sentTotalWeight($r) === 0.15);
});

it('never asks what Shopify will declare for a domestic purchase', function (): void {
    // No customs declaration, no item weights to contradict — which is why this
    // went unseen until the first international label.
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['weight' => 0.15]);

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    expect($this->adapter->createShipment(shopifyShipRequest($package->fresh()))->success)->toBeTrue();

    Saloon::assertNotSent(fn (GraphQL $request): bool => str_contains($request->body()->all()['query'], 'ShopifyDeclaredItemWeight'));
});

it('does not withhold a purchase on the strength of a line-item page it could not finish reading', function (): void {
    // A truncated page is no answer rather than a smaller sum. Grounding
    // shipments over a paging limit would be a worse failure than the one this
    // check exists to prevent.
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(0.15);

    Saloon::fake([
        MockResponse::make(declaredItemWeights([[1.76, 1]], hasNextPage: true)),
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    expect($this->adapter->createShipment(shopifyShipRequest($package))->success)->toBeTrue();

    Saloon::assertSent(fn (GraphQL $r): bool => sentTotalWeight($r) === 0.15);
});

it('falls back to the order-time weight when the live catalogue is denied', function (): void {
    // A token without `read_products` cannot traverse `lineItem.variant`, and
    // Shopify nulls the field rather than the response. The snapshot rides on
    // the same request precisely so the check survives that, degraded rather
    // than gone.
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(0.15);

    Saloon::fake([
        MockResponse::make(declaredItemWeights([[1.76, 1], [0.53, 1]], deniedLive: true)),
    ]);

    expect(withheldPurchase(fn () => $this->adapter->createShipment(shopifyShipRequest($package)))->declaredWeight)
        ->toBe(2.29);
});

it('never fails a purchase because it could not read what Shopify will declare', function (): void {
    // This read decides whether to *withhold*, so a failure to make it must
    // cost the check and nothing else. Throwing would turn a missing scope into
    // a failed purchase on every international label — the same failure, in the
    // same place, as the defect the check exists to prevent.
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(0.15);

    Saloon::fake([
        MockResponse::make(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]]),
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    expect($this->adapter->createShipment(shopifyShipRequest($package))->success)->toBeTrue();

    Saloon::assertSent(fn (GraphQL $r): bool => sentTotalWeight($r) === 0.15);
});

it('prefers the live catalogue weight over the order-time snapshot', function (): void {
    // The reason both are asked for. A merchant who corrects a product weight
    // after a refusal has to be able to retry successfully, and the snapshot
    // still names the weight the order was placed at.
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(3.0);

    Saloon::fake([
        // Catalogue corrected down to 1.0; the order still remembers 9.0.
        MockResponse::make(declaredItemWeights([[1.0, 1]], snapshot: [9.0])),
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    expect($this->adapter->createShipment(shopifyShipRequest($package))->success)->toBeTrue();

    Saloon::assertSent(fn (GraphQL $r): bool => sentTotalWeight($r) === 3.0);
});

it('counts a variant with no measured weight as declaring nothing', function (): void {
    seedShopifyCarrierServices();
    $package = internationalShopifyPackage(0.15);

    Saloon::fake([
        MockResponse::make(declaredItemWeights([[null, 1], [null, 2]])),
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    expect($this->adapter->createShipment(shopifyShipRequest($package))->success)->toBeTrue();
});

function seedShopifyCarrierServices(): void
{
    $carrier = Carrier::firstOrCreate(['name' => 'Shopify']);

    foreach ([
        'auto' => "Shopify's choice",
        'usps:usps_ground_advantage' => 'USPS Ground Advantage',
        'usps:GroundAdvantage' => "Shopify's USPS Ground Advantage",
        'ups_shipping:92' => "Shopify's UPS Ground Saver (under 1 lb)",
        'ups_shipping:93' => "Shopify's UPS Ground Saver (1 lb and over)",
    ] as $code => $name) {
        $carrier->carrierServices()->firstOrCreate(['service_code' => $code], ['name' => $name]);
    }
}

function shopifyPackage(array $shipmentAttributes = []): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shipment = Shipment::factory()->create(array_merge([
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ], $shipmentAttributes));

    return Package::factory()->create(['shipment_id' => $shipment->id]);
}

function shopifyShipRequest(Package $package, string $serviceCode = 'auto'): ShipRequest
{
    return ShipRequest::fromPackageAndBlindOffer($package, new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Shopify Shipping',
        serviceCode: $serviceCode,
        selectionLabel: $serviceCode === 'auto' ? "Shopify's choice" : 'USPS Ground Advantage',
    ));
}

/** @return array<string, mixed> */
function purchaseAccepted(): array
{
    return [
        'data' => [
            'shippingLabelPurchase' => [
                'shippingLabelPurchaseResult' => [
                    'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
                    'status' => 'PENDING_PURCHASE',
                    'done' => false,
                    'errors' => [],
                ],
                'userErrors' => [],
            ],
        ],
    ];
}

/** @return array<string, mixed> */
function purchasePurchased(string $format = 'PDF', ?string $company = 'USPS'): array
{
    return [
        'data' => [
            'node' => [
                'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
                'status' => 'PURCHASED',
                'done' => true,
                'errors' => [],
                'shippingLabels' => [[
                    'id' => 'gid://shopify/ShippingLabel/1',
                    'trackingInfo' => [
                        'company' => $company,
                        'number' => '9400111899223197428490',
                        'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111899223197428490',
                    ],
                    'shippingDocuments' => [[
                        'documentType' => 'LABEL',
                        'format' => $format,
                        'url' => 'https://cdn.shopify.test/labels/1.'.strtolower($format),
                    ]],
                ]],
            ],
        ],
    ];
}

/**
 * Run a purchase that should be withheld, and hand back the refusal so its
 * numbers can be read. Fails loudly if the purchase went ahead.
 */
function withheldPurchase(Closure $attempt): ShopifyDeclaredWeightException
{
    try {
        $attempt();
    } catch (ShopifyDeclaredWeightException $e) {
        return $e;
    }

    throw new RuntimeException('The purchase was not withheld.');
}

function internationalShopifyPackage(float $weight): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shipment = Shipment::factory()->international()->create([
        'data_source_id' => $source->id,
        'country' => 'CA',
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);

    return Package::factory()->create(['shipment_id' => $shipment->id, 'weight' => $weight]);
}

/**
 * What Shopify will declare for a fulfillment order, as `[unit weight in
 * pounds, quantity]` pairs. A null weight is a variant Shopify has never been
 * told the weight of.
 *
 * `$snapshot` is the order-time `FulfillmentOrderLineItem.weight` for the same
 * items, defaulting to whatever the live catalogue says. `$deniedLive` drops
 * the `lineItem.variant` traversal the way a token without `read_products`
 * does — nulled data beside an errors array, not a dead response.
 *
 * @param  list<array{0: float|null, 1: int}>  $items
 * @param  list<float|null>|null  $snapshot
 * @return array<string, mixed>
 */
function declaredItemWeights(
    array $items,
    bool $hasNextPage = false,
    ?array $snapshot = null,
    bool $deniedLive = false,
): array {
    $pounds = fn (?float $value): ?array => $value === null ? null : ['value' => $value, 'unit' => 'POUNDS'];

    $payload = [
        'data' => [
            'fulfillmentOrder' => [
                'id' => 'gid://shopify/FulfillmentOrder/12345',
                'lineItems' => [
                    'pageInfo' => ['hasNextPage' => $hasNextPage],
                    'nodes' => array_map(fn (array $item, int $index): array => [
                        'remainingQuantity' => $item[1],
                        'weight' => $pounds($snapshot === null ? $item[0] : ($snapshot[$index] ?? null)),
                        'lineItem' => $deniedLive ? null : [
                            'variant' => [
                                'inventoryItem' => [
                                    'measurement' => ['weight' => $pounds($item[0])],
                                ],
                            ],
                        ],
                    ], $items, array_keys($items)),
                ],
            ],
        ],
    ];

    if ($deniedLive) {
        $payload['errors'] = [[
            'message' => 'Access denied for variant field. Required access: `read_products` access scope.',
            'extensions' => ['code' => 'ACCESS_DENIED'],
        ]];
    }

    return $payload;
}

/** The `totalWeight` a purchase mutation carried, or null for any other request. */
function sentTotalWeight(GraphQL $request): ?float
{
    $body = $request->body()->all();

    if (! str_contains($body['query'], 'shippingLabelPurchase')) {
        return null;
    }

    return $body['variables']['input']['totalWeight']['value'] ?? null;
}
