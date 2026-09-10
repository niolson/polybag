<?php

use App\Models\Carrier;
use App\Models\Setting;
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

it('seeds every catalogued Shopify service as a code the adapter can select a rate with', function (): void {
    $this->seed(DatabaseSeeder::class);

    $adapter = new ShopifyAdapter;
    $codes = Carrier::query()
        ->where('name', ShopifyAdapter::CARRIER_NAME)
        ->firstOrFail()
        ->carrierServices()
        ->pluck('service_code')
        ->reject(fn (string $code): bool => $code === ShopifyAdapter::AUTO_SERVICE_CODE);

    expect($codes)->not->toBeEmpty();

    // A pair that does not split is not a preference Shopify can read -- it
    // silently degrades to letting Shopify choose, which is the one failure
    // mode a catalogued explicit service must not have.
    foreach ($codes as $code) {
        expect($adapter->splitServiceCode($code))->not->toBe([null, null], "service code {$code}");
    }
});

it('marks Shopify USPS services and only Ground Saver on its UPS side as PO Box / military capable', function (): void {
    $this->seed(DatabaseSeeder::class);

    $shopify = Carrier::query()->where('name', ShopifyAdapter::CARRIER_NAME)->firstOrFail();

    // Each carrier keeps its own vocabulary and Shopify passes it through, so
    // this catalog is deliberately in three alphabets: a PascalCase of
    // Shopify's own for USPS, UPS's numeric codes, and DHL's letter codes.
    expect($shopify->carrierServices()->where('service_code', 'usps:GroundAdvantage')->value('can_ship_to_po_boxes'))->toBeTrue()
        ->and($shopify->carrierServices()->where('service_code', 'usps:PriorityExpress')->value('can_ship_to_military_addresses'))->toBeTrue()
        ->and($shopify->carrierServices()->where('service_code', 'ups_shipping:92')->value('can_ship_to_po_boxes'))->toBeTrue()
        ->and($shopify->carrierServices()->where('service_code', 'ups_shipping:93')->value('can_ship_to_po_boxes'))->toBeTrue()
        ->and($shopify->carrierServices()->where('service_code', 'ups_shipping:03')->value('can_ship_to_po_boxes'))->toBeFalse()
        ->and($shopify->carrierServices()->where('service_code', 'ups_shipping:01')->value('can_ship_to_military_addresses'))->toBeFalse()
        ->and($shopify->carrierServices()->where('service_code', 'dhl_express:P')->value('can_ship_to_po_boxes'))->toBeFalse()
        ->and($shopify->carrierServices()->where('service_code', 'ups_shipping:07')->value('can_ship_to_po_boxes'))->toBeFalse();
});
