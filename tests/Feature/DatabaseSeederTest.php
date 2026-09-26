<?php

use App\Enums\PostageSourceKind;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Setting;
use App\Models\SourceServiceMapping;
use App\Services\Carriers\ShopifyAdapter;
use Database\Seeders\DatabaseSeeder;

it('marks setup as complete when the database is seeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Setting::find('setup_complete')?->value)->toBeTrue()
        ->and(Setting::find('setup_wizard_step')?->value)->toBe(1);
});

it('includes tenant reference data when the database is seeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Carrier::where('name', 'USPS')->exists())->toBeTrue()
        ->and(Carrier::where('name', 'FedEx')->exists())->toBeTrue()
        ->and(Carrier::where('name', 'UPS')->exists())->toBeTrue();
});

it('seeds fedex carrier services with trademarked display names', function (): void {
    $this->seed(DatabaseSeeder::class);

    $fedex = Carrier::query()
        ->where('name', 'FedEx')
        ->firstOrFail();

    expect($fedex->carrierServices()->where('service_code', 'GROUND_HOME_DELIVERY')->value('name'))->toBe('FedEx Home Delivery®')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_GROUND')->value('name'))->toBe('FedEx Ground®')
        ->and($fedex->carrierServices()->where('service_code', 'SMART_POST')->value('name'))->toBe('FedEx Ground® Economy')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_INTERNATIONAL_PRIORITY')->value('name'))->toBe('FedEx International Priority®')
        ->and($fedex->carrierServices()->where('service_code', 'INTERNATIONAL_ECONOMY')->value('name'))->toBe('FedEx International Economy®')
        ->and($fedex->carrierServices()->where('service_code', 'PRIORITY_OVERNIGHT')->value('name'))->toBe('FedEx Priority Overnight®')
        ->and($fedex->carrierServices()->where('service_code', 'STANDARD_OVERNIGHT')->value('name'))->toBe('FedEx Standard Overnight®')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_2_DAY')->value('name'))->toBe('FedEx 2Day®')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_2_DAY_AM')->value('name'))->toBe('FedEx 2Day® A.M.')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_EXPRESS_SAVER')->value('name'))->toBe('FedEx Express Saver®');
});

it('marks every USPS service and only FedEx Ground Economy as PO Box / military capable', function (): void {
    $this->seed(DatabaseSeeder::class);

    $usps = Carrier::query()->where('name', 'USPS')->firstOrFail();

    expect($usps->carrierServices()->where('can_ship_to_po_boxes', true)->count())
        ->toBe($usps->carrierServices()->count())
        ->and($usps->carrierServices()->where('can_ship_to_military_addresses', true)->count())
        ->toBe($usps->carrierServices()->count());

    $fedex = Carrier::query()->where('name', 'FedEx')->firstOrFail();

    expect($fedex->carrierServices()->where('service_code', 'SMART_POST')->value('can_ship_to_po_boxes'))->toBeTrue()
        ->and($fedex->carrierServices()->where('service_code', 'SMART_POST')->value('can_ship_to_military_addresses'))->toBeTrue()
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_GROUND')->value('can_ship_to_po_boxes'))->toBeFalse()
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_GROUND')->value('can_ship_to_military_addresses'))->toBeFalse();
});

it('maps every Shopify code to a real catalog service, as a pair the adapter can select a rate with', function (): void {
    $this->seed(DatabaseSeeder::class);

    $adapter = new ShopifyAdapter;
    $mappings = SourceServiceMapping::query()
        ->where('source_kind', PostageSourceKind::Shopify)
        ->with('carrierService.carrier')
        ->get();

    expect($mappings)->toHaveCount(21)
        ->and(Carrier::where('name', 'Shopify')->exists())->toBeFalse()
        ->and(CarrierService::where('name', 'like', "Shopify's%")->exists())->toBeFalse();

    // A pair that does not split is not a preference Shopify can read -- it
    // silently degrades to letting Shopify choose, which is the one failure
    // mode an explicit service must not have.
    foreach ($mappings as $mapping) {
        $code = ShopifyAdapter::serviceCodeFromMapping($mapping);

        expect($adapter->splitServiceCode($code))->not->toBe([null, null], "service code {$code}")
            ->and($mapping->carrierService->carrier->name)->not->toBe('Shopify');
    }
});

it('maps the Shopify USPS international and DHL codes the oracle confirmed onto authored services', function (): void {
    $this->seed(DatabaseSeeder::class);

    $serviceFor = fn (string $carrierId, string $serviceId): ?CarrierService => SourceServiceMapping::query()
        ->forIdentity(PostageSourceKind::Shopify, $carrierId, $serviceId)
        ->first()
        ?->carrierService;

    // The full USPS product name in PascalCase, "Service" suffix included --
    // `FirstClassPackageInternational` and the international API's own
    // `PRIORITY_MAIL_INTERNATIONAL` both find no rate.
    expect($serviceFor('usps', 'FirstClassPackageInternationalService')?->service_code)->toBe('FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE')
        ->and($serviceFor('usps', 'PriorityMailInternational')?->service_code)->toBe('PRIORITY_MAIL_INTERNATIONAL')
        ->and($serviceFor('usps', 'PriorityMailExpressInternational')?->service_code)->toBe('PRIORITY_MAIL_EXPRESS_INTERNATIONAL')
        ->and($serviceFor('ups_shipping', '92')?->carrier->name)->toBe(Carrier::UPS)
        // DHL's own product code, so a direct integration later needs no remapping.
        ->and($serviceFor('dhl_express', 'P')?->carrier->name)->toBe(Carrier::DHL_EXPRESS)
        ->and($serviceFor('dhl_express', 'P')?->service_code)->toBe('P')
        ->and($serviceFor('dhl_express', 'P')?->can_ship_to_po_boxes)->toBeFalse();
});
