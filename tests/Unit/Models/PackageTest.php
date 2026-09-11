<?php

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\ShipmentStatus;
use App\Enums\SpecialServiceSource;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAlias;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\PackageSpecialService;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\SpecialService;
use App\Models\User;

it('marks a package as shipped from ShipResponse', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create();
    $carrierAccount = CarrierAccount::factory()->create();

    $response = ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('PDF content'),
        labelOrientation: 'portrait',
        carrierAccountId: $carrierAccount->id,
    );

    $package->markShipped($response, PostageSource::CarrierAccount);
    $package->refresh();

    expect($package->tracking_number)->toBe('9400111899223456789012')
        ->and((float) $package->cost)->toBe(8.50)
        ->and($package->carrier)->toBe('USPS')
        ->and($package->service)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($package->label_data)->toBe(base64_encode('PDF content'))
        ->and($package->label_orientation)->toBe('portrait')
        ->and($package->carrier_account_id)->toBe($carrierAccount->id)
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->shipped_at)->not->toBeNull()
        ->and($package->shipment->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('preserves the raw carrier and snapshots its normalized identity at ship time', function (): void {
    $usps = Carrier::factory()->usps()->create();
    $alias = CarrierAlias::factory()->for($usps)->create(['alias' => 'US Postal Service']);
    $package = Package::factory()->create();

    $package->markShipped(ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'US Postal Service',
        service: 'Ground Advantage',
    ), PostageSource::CarrierAccount);

    expect($package->carrier)->toBe('US Postal Service')
        ->and($package->normalized_carrier_id)->toBe($usps->id)
        ->and($package->normalizedCarrier->is($usps))->toBeTrue();

    $alias->update(['alias' => 'Postal Service']);

    expect($package->refresh()->carrier)->toBe('US Postal Service')
        ->and($package->normalized_carrier_id)->toBe($usps->id);
});

it('ships with a null normalized identity when the raw carrier is unrecognized', function (): void {
    $package = Package::factory()->create();

    $package->markShipped(ShipResponse::success(
        trackingNumber: 'TRACKING-1',
        cost: 8.50,
        carrier: 'Uncatalogued Carrier',
        service: 'Ground',
    ), PostageSource::CarrierAccount);

    expect($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->carrier)->toBe('Uncatalogued Carrier')
        ->and($package->normalized_carrier_id)->toBeNull();
});

it('names the carrier of record by its normalized identity, falling back to the raw value', function (): void {
    $usps = Carrier::factory()->usps()->create();
    $package = Package::factory()->shipped()->create([
        'carrier' => 'US Postal Service',
        'normalized_carrier_id' => $usps->id,
    ]);
    $unmapped = Package::factory()->shipped()->create([
        'carrier' => 'Poste Italiane',
        'normalized_carrier_id' => null,
    ]);

    expect($package->carrierOfRecordName())->toBe('USPS')
        ->and($unmapped->carrierOfRecordName())->toBe('Poste Italiane')
        ->and(Package::factory()->create()->carrierOfRecordName())->toBeNull();
});

it('records a direct purchase as bought on the carrier account', function (): void {
    $package = Package::factory()->create();
    $carrierAccount = CarrierAccount::factory()->create();

    $package->markShipped(ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        carrierAccountId: $carrierAccount->id,
    ), PostageSource::CarrierAccount);

    $package->refresh();

    expect($package->postage_source)->toBe(PostageSource::CarrierAccount)
        ->and($package->carrier_account_id)->toBe($carrierAccount->id)
        ->and($package->postage_data_source_id)->toBeNull();
});

it('stores a customs document the purchase returned beside the label', function (): void {
    $package = Package::factory()->create();

    $package->markShipped(new ShipResponse(
        success: true,
        trackingNumber: '9400111899223456789012',
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('label'),
        customsFormData: base64_encode('commercial-invoice'),
    ), PostageSource::CarrierAccount);

    expect(base64_decode($package->refresh()->customs_form_data))->toBe('commercial-invoice');
});

it('clears the customs document when the label is voided', function (): void {
    // A commercial invoice names both parties and their tax IDs. A voided label
    // must not leave one behind on a package that is going to be shipped again.
    $package = Package::factory()->withCustomsForm()->create();

    $package->clearShipping();

    expect($package->refresh()->customs_form_data)->toBeNull()
        ->and($package->label_data)->toBeNull();
});

it('records a sales-channel purchase against the data source that sold the postage', function (): void {
    $package = Package::factory()->create();
    $dataSource = DataSource::factory()->create();

    $package->markShipped(new ShipResponse(
        success: true,
        trackingNumber: '9400111899223456789012',
        carrier: 'Shopify',
        service: 'USPS',
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: $dataSource->id,
    ), PostageSource::PostageDataSource);

    $package->refresh();

    expect($package->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and($package->postage_data_source_id)->toBe($dataSource->id)
        ->and($package->postageDataSource->is($dataSource))->toBeTrue()
        ->and($package->carrier_account_id)->toBeNull();
});

it('identifies Shopify-bought postage by its source rather than its carrier', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => null,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory()->shopify(),
    ]);

    expect($package->isShopifyShipped())->toBeTrue()
        ->and($package->isAmazonShipped())->toBeFalse();
});

it('does not mistake an Amazon Buy Shipping label for a Shopify one', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'Amazon Shipping',
        'carrier_account_id' => null,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory()->amazon(),
    ]);

    expect($package->isAmazonShipped())->toBeTrue()
        ->and($package->isShopifyShipped())->toBeFalse();
});

it('does not identify a direct purchase as Shopify-bought from its legacy carrier value', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'Shopify',
        'postage_source' => PostageSource::CarrierAccount,
    ]);

    expect($package->isShopifyShipped())->toBeFalse();
});

it('refuses to ship with a postage source its pointers contradict', function (PostageSource $postageSource, ShipResponse $response): void {
    $package = Package::factory()->create();

    expect(fn () => $package->markShipped($response, $postageSource))
        ->toThrow(InvalidArgumentException::class);

    // Nothing half-written: the package is still waiting to be shipped.
    expect($package->refresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($package->postage_source)->toBeNull()
        ->and($package->tracking_number)->toBeNull();
})->with([
    'carrier account claimed alongside a data source pointer' => fn (): array => [
        PostageSource::CarrierAccount,
        new ShipResponse(
            success: true,
            trackingNumber: 'T1',
            carrier: 'USPS',
            postageDataSourceId: DataSource::factory()->create()->id,
        ),
    ],
    'data source claimed with no data source named' => fn (): array => [
        PostageSource::PostageDataSource,
        new ShipResponse(success: true, trackingNumber: 'T2', carrier: 'USPS'),
    ],
    'data source claimed alongside a carrier account pointer' => fn (): array => [
        PostageSource::PostageDataSource,
        new ShipResponse(
            success: true,
            trackingNumber: 'T3',
            carrier: 'USPS',
            carrierAccountId: CarrierAccount::factory()->create()->id,
            postageDataSourceId: DataSource::factory()->create()->id,
        ),
    ],
]);

it('records a direct carrier purchase as a confirmed service', function (): void {
    $package = Package::factory()->create();

    $package->markShipped(ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.45,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
    ), PostageSource::CarrierAccount);

    $package->refresh();

    expect($package->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($package->confirmedService())->toBe('USPS_GROUND_ADVANTAGE')
        ->and($package->requested_service)->toBeNull()
        ->and($package->service_inference_method)->toBeNull()
        ->and($package->service_ruleset_version)->toBeNull();
});

it('keeps what was asked for on a package whose service stays unknown', function (): void {
    $package = Package::factory()->create();
    $dataSource = DataSource::factory()->create();

    $package->markShipped(new ShipResponse(
        success: true,
        trackingNumber: '9400111899223456789012',
        carrier: 'DHL eCommerce',
        service: null,
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: $dataSource->id,
        requestedService: 'USPS Ground Advantage',
        serviceEvidence: ServiceEvidence::Unknown,
    ), PostageSource::PostageDataSource);

    $package->refresh();

    expect($package->service)->toBeNull()
        ->and($package->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->requested_service)->toBe('USPS Ground Advantage')
        // The preference is audit metadata. It never becomes the service value.
        ->and($package->confirmedService())->toBeNull();
});

it('records how an inferred service was derived', function (): void {
    $package = Package::factory()->create();

    $package->markShipped(new ShipResponse(
        success: true,
        trackingNumber: '9400111899223456789012',
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        serviceEvidence: ServiceEvidence::Inferred,
        serviceInferenceMethod: 'tracking_number_prefix',
        serviceRulesetVersion: '2026.09.1',
    ), PostageSource::CarrierAccount);

    $package->refresh();

    expect($package->service)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Inferred)
        ->and($package->service_inference_method)->toBe('tracking_number_prefix')
        ->and($package->service_ruleset_version)->toBe('2026.09.1')
        // Ours, not the carrier's — so nothing outward-facing may carry it.
        ->and($package->confirmedService())->toBeNull();
});

it('refuses to ship with service evidence its service value contradicts', function (ShipResponse $response): void {
    $package = Package::factory()->create();

    expect(fn () => $package->markShipped($response, PostageSource::CarrierAccount))
        ->toThrow(InvalidArgumentException::class);

    // Nothing half-written: the package is still waiting to be shipped.
    expect($package->refresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($package->service)->toBeNull()
        ->and($package->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->tracking_number)->toBeNull();
})->with([
    'confirmed with nothing to confirm' => fn (): ShipResponse => new ShipResponse(
        success: true,
        trackingNumber: 'E1',
        carrier: 'USPS',
        service: null,
        serviceEvidence: ServiceEvidence::Confirmed,
    ),
    'unknown recorded as a service value anyway' => fn (): ShipResponse => new ShipResponse(
        success: true,
        trackingNumber: 'E2',
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        serviceEvidence: ServiceEvidence::Unknown,
    ),
    'inferred with nothing inferred' => fn (): ShipResponse => new ShipResponse(
        success: true,
        trackingNumber: 'E3',
        carrier: 'USPS',
        service: null,
        serviceEvidence: ServiceEvidence::Inferred,
        serviceInferenceMethod: 'tracking_number_prefix',
        serviceRulesetVersion: '2026.09.1',
    ),
    'inferred without a reproducible method' => fn (): ShipResponse => new ShipResponse(
        success: true,
        trackingNumber: 'E4',
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        serviceEvidence: ServiceEvidence::Inferred,
        serviceRulesetVersion: '2026.09.1',
    ),
    'inferred without the ruleset that produced it' => fn (): ShipResponse => new ShipResponse(
        success: true,
        trackingNumber: 'E5',
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        serviceEvidence: ServiceEvidence::Inferred,
        serviceInferenceMethod: 'tracking_number_prefix',
    ),
    'inference detail on a service nobody inferred' => fn (): ShipResponse => new ShipResponse(
        success: true,
        trackingNumber: 'E6',
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        serviceEvidence: ServiceEvidence::Confirmed,
        serviceInferenceMethod: 'tracking_number_prefix',
    ),
]);

it('gives a voided package its service evidence back to unknown', function (): void {
    $package = Package::factory()->shipped()->create([
        'requested_service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'tracking_number_prefix',
        'service_ruleset_version' => '2026.09.1',
    ]);

    $package->clearShipping();

    expect($package->refresh()->service)->toBeNull()
        ->and($package->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->requested_service)->toBeNull()
        ->and($package->service_inference_method)->toBeNull()
        ->and($package->service_ruleset_version)->toBeNull();
});

it('gives a voided package its provenance back to nothing', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier_account_id' => CarrierAccount::factory(),
    ]);

    $package->clearShipping();

    expect($package->refresh()->postage_source)->toBeNull()
        ->and($package->postage_data_source_id)->toBeNull()
        ->and($package->normalized_carrier_id)->toBeNull();
});

it('clears all shipping fields', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create([
        'carrier_account_id' => CarrierAccount::factory(),
    ]);

    $package->clearShipping();
    $package->refresh();

    expect($package->tracking_number)->toBeNull()
        ->and($package->carrier_account_id)->toBeNull()
        ->and($package->carrier)->toBeNull()
        ->and($package->service)->toBeNull()
        ->and($package->cost)->toBeNull()
        ->and($package->label_data)->toBeNull()
        ->and($package->label_orientation)->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->shipped_at)->toBeNull()
        ->and($package->shipped_by_user_id)->toBeNull()
        ->and($package->shipment->fresh()->status)->toBe(ShipmentStatus::Open);
});

it('sets shipped_by_user_id when provided', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create();
    $user = User::factory()->create();

    $response = ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('PDF content'),
        labelOrientation: 'portrait',
    );

    $package->markShipped($response, PostageSource::CarrierAccount, $user->id);
    $package->refresh();

    expect($package->shipped_by_user_id)->toBe($user->id)
        ->and($package->shippedBy->id)->toBe($user->id);
});

it('preserves dimension fields when clearing shipping', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create([
        'weight' => 5.00,
        'height' => 10.00,
        'width' => 8.00,
        'length' => 6.00,
    ]);

    $package->clearShipping();
    $package->refresh();

    expect((float) $package->weight)->toBe(5.00)
        ->and((float) $package->height)->toBe(10.00)
        ->and((float) $package->width)->toBe(8.00)
        ->and((float) $package->length)->toBe(6.00);
});

it('records product-required special services with Product source', function (): void {
    $alcohol = SpecialService::create([
        'code' => 'alcohol',
        'name' => 'Alcohol',
        'scope' => 'package',
        'category' => 'compliance',
        'requires_value' => false,
        'active' => true,
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $package->markShipped(ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'FedEx',
        service: 'FEDEX_GROUND',
        labelData: base64_encode('PDF content'),
        appliedServices: ['alcohol'],
    ), PostageSource::CarrierAccount);

    $applied = PackageSpecialService::where('package_id', $package->id)
        ->where('special_service_id', $alcohol->id)
        ->first();

    expect($applied)->not->toBeNull()
        ->and($applied->source)->toBe(SpecialServiceSource::Product)
        ->and($applied->source_reference)->toBe((string) $product->id);
});

it('records applied services from inactive-compliance packages with System source', function (): void {
    // Product flag present but service inactive: not product-required, so an
    // applied code (e.g. carrier default) falls through to System attribution
    $alcohol = SpecialService::create([
        'code' => 'alcohol',
        'name' => 'Alcohol',
        'scope' => 'package',
        'category' => 'compliance',
        'requires_value' => false,
        'active' => false,
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $package->markShipped(ShipResponse::success(
        trackingNumber: '9400111899223456789013',
        cost: 8.50,
        carrier: 'FedEx',
        service: 'FEDEX_GROUND',
        labelData: base64_encode('PDF content'),
        appliedServices: ['alcohol'],
    ), PostageSource::CarrierAccount);

    $applied = PackageSpecialService::where('package_id', $package->id)
        ->where('special_service_id', $alcohol->id)
        ->first();

    expect($applied)->not->toBeNull()
        ->and($applied->source)->toBe(SpecialServiceSource::System)
        ->and($applied->source_reference)->toBeNull();
});
