<?php

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\HazmatClass;
use App\Exceptions\MissingDeclaredValueException;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\SpecialService;
use App\Services\SpecialServiceResolver;

function resolverSpecialService(string $code, bool $active = true): SpecialService
{
    return SpecialService::create([
        'code' => $code,
        'name' => ucwords(str_replace('_', ' ', $code)),
        'scope' => 'package',
        'category' => 'compliance',
        'requires_value' => false,
        'active' => $active,
    ]);
}

it('resolves product compliance codes keyed by triggering product id', function (): void {
    resolverSpecialService('alcohol');
    resolverSpecialService('dry_ice');

    $package = Package::factory()->create();
    $alcoholProduct = Product::factory()->create(['contains_alcohol' => true]);
    $dryIceProduct = Product::factory()->create(['hazmat_class' => HazmatClass::DryIce]);
    PackageItem::factory()->for($package)->create(['product_id' => $alcoholProduct->id]);
    PackageItem::factory()->for($package)->create(['product_id' => $dryIceProduct->id]);

    $codes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package);

    expect($codes->all())->toBe([
        'alcohol' => $alcoholProduct->id,
        'dry_ice' => $dryIceProduct->id,
    ]);
});

it('ignores product compliance codes whose special service is inactive', function (): void {
    resolverSpecialService('alcohol', active: false);
    resolverSpecialService('dry_ice');

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'contains_alcohol' => true,
        'hazmat_class' => HazmatClass::DryIce,
    ]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $codes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package);

    expect($codes->all())->toBe(['dry_ice' => $product->id]);
});

it('ignores product compliance codes with no matching special service row', function (): void {
    $package = Package::factory()->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $codes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package);

    expect($codes)->toBeEmpty();
});

it('groups shipping method codes by pivot mode and ignores available mode', function (): void {
    $required = resolverSpecialService('signature_required');
    $default = resolverSpecialService('saturday_delivery');
    $available = resolverSpecialService('hold_at_location');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($required->id, ['mode' => 'required']);
    $shippingMethod->specialServices()->attach($default->id, ['mode' => 'default']);
    $shippingMethod->specialServices()->attach($available->id, ['mode' => 'available']);

    $byMode = app(SpecialServiceResolver::class)->methodCodesByMode($shippingMethod);

    expect($byMode['required'])->toBe(['signature_required'])
        ->and($byMode['default'])->toBe(['saturday_delivery']);
});

it('returns empty mode groups without a shipping method', function (): void {
    $byMode = app(SpecialServiceResolver::class)->methodCodesByMode(null);

    expect($byMode)->toBe(['required' => [], 'default' => []]);
});

it('merges shipping method and product codes for a package', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');
    resolverSpecialService('alcohol');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $codes = app(SpecialServiceResolver::class)->resolveForPackage($package);

    expect($codes)->toContain('saturday_delivery')
        ->and($codes)->toContain('alcohol')
        ->and($codes)->toHaveCount(2);
});

it('ignores inactive services attached to a shipping method', function (): void {
    $active = resolverSpecialService('saturday_delivery');
    $stale = resolverSpecialService('signature_required', active: false);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($stale->id, ['mode' => 'required']);
    $shippingMethod->specialServices()->attach($active->id, ['mode' => 'default']);

    $byMode = app(SpecialServiceResolver::class)->methodCodesByMode($shippingMethod);

    expect($byMode['required'])->toBeEmpty()
        ->and($byMode['default'])->toBe(['saturday_delivery']);
});

it('strips default codes not scoped for the selected rate carrier service', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $fedex = Carrier::factory()->fedex()->create();
    $ground = CarrierService::factory()->fedexGround()->for($fedex)->create();
    $overnight = CarrierService::factory()->for($fedex)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();

    $resolver = app(SpecialServiceResolver::class);

    // Ground is scoped out for Saturday — the code must not reach the purchase request
    expect($resolver->resolveForPackageAndRate($package, fedexRateFor('FEDEX_GROUND')))->toBeEmpty()
        // Overnight is scoped in — the code survives
        ->and($resolver->resolveForPackageAndRate($package, fedexRateFor('PRIORITY_OVERNIGHT')))->toBe(['saturday_delivery']);
});

it('keeps default codes unscoped for the selected rate carrier', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $usps = Carrier::factory()->usps()->create();
    CarrierService::factory()->uspsGroundAdvantage()->for($usps)->create();

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();

    $rate = new RateResponse(
        carrier: 'USPS',
        serviceCode: 'USPS_GROUND_ADVANTAGE',
        serviceName: 'USPS Ground Advantage',
        price: 8.50,
    );

    // No Saturday scope rows exist for any USPS service — unrestricted
    expect(app(SpecialServiceResolver::class)->resolveForPackageAndRate($package, $rate))
        ->toBe(['saturday_delivery']);
});

it('leaves an Amazon offer its defaults whatever the mapped service is scoped to', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $fedex = Carrier::factory()->fedex()->create();
    CarrierService::factory()->fedexGround()->for($fedex)->create();
    $overnight = CarrierService::factory()->for($fedex)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();

    // Mapped onto FedEx Ground, which the catalog scopes Saturday away from.
    $rate = new RateResponse(
        carrier: 'FedEx',
        serviceCode: 'FEDEX_GROUND',
        serviceName: 'FedEx Ground',
        price: 9.00,
        observedService: new ObservedServiceIdentity('amazon', 'FEDEX', 'FEDEX_PTP_GROUND'),
    );

    // Catalog scoping binds direct offers only (ADR-0006 decision 10).
    expect(app(SpecialServiceResolver::class)->resolveForPackageAndRate($package, $rate))
        ->toBe(['saturday_delivery'])
        ->and(app(SpecialServiceResolver::class)->resolveForPackageAndRate($package, fedexRateFor('FEDEX_GROUND')))
        ->toBeEmpty();
});

it('applies restricted_countries when resolving for a selected rate', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $fedex = Carrier::factory()->fedex()->create();
    $overnight = CarrierService::factory()->for($fedex)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id, ['restricted_countries' => ['CA']]);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['country' => 'US']);
    $package = Package::factory()->for($shipment)->create();

    expect(app(SpecialServiceResolver::class)->resolveForPackageAndRate($package, fedexRateFor('PRIORITY_OVERNIGHT')))
        ->toBeEmpty();
});

it('never strips required codes when resolving for a selected rate', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $fedex = Carrier::factory()->fedex()->create();
    CarrierService::factory()->fedexGround()->for($fedex)->create();
    $overnight = CarrierService::factory()->for($fedex)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'required']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();

    // Required codes pass through untouched — rate shopping already excluded
    // carrier services that can't satisfy them
    expect(app(SpecialServiceResolver::class)->resolveForPackageAndRate($package, fedexRateFor('FEDEX_GROUND')))
        ->toBe(['saturday_delivery']);
});

it('drops signature_required when adult_signature_required is also resolved', function (): void {
    $signature = resolverSpecialService('signature_required');
    $adult = resolverSpecialService('adult_signature_required');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($signature->id, ['mode' => 'required']);
    $shippingMethod->specialServices()->attach($adult->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();

    $codes = app(SpecialServiceResolver::class)->resolveForPackage($package);

    expect($codes)->toContain('adult_signature_required')
        ->and($codes)->not->toContain('signature_required');
});

it('auto-pairs adult signature with alcohol product compliance', function (): void {
    resolverSpecialService('alcohol');
    resolverSpecialService('adult_signature_required');

    $package = Package::factory()->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $codes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package);

    expect($codes->all())->toBe([
        'alcohol' => $product->id,
        'adult_signature_required' => $product->id,
    ]);
});

it('does not enforce adult signature for alcohol products while the alcohol service is inactive', function (): void {
    resolverSpecialService('alcohol', active: false);
    resolverSpecialService('adult_signature_required');

    $package = Package::factory()->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    // Deactivating alcohol must fully disable alcohol compliance — the paired
    // signature must not leak through just because it is active itself
    expect(app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package))->toBeEmpty();
});

it('does not pair adult signature with alcohol when the signature service is inactive', function (): void {
    resolverSpecialService('alcohol');
    resolverSpecialService('adult_signature_required', active: false);

    $package = Package::factory()->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $codes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package);

    expect($codes->all())->toBe(['alcohol' => $product->id]);
});

it('uses the shipment value as declared value for a single-package shipment', function (): void {
    $shipment = Shipment::factory()->create(['value' => 149.99]);
    $package = Package::factory()->for($shipment)->create();

    expect(app(SpecialServiceResolver::class)->declaredValueForPackage($package))->toBe(149.99);
});

it('falls back to the package item value sum when the shipment value is zero', function (): void {
    $shipment = Shipment::factory()->create(['value' => 0]);
    $package = Package::factory()->for($shipment)->create();
    $item = ShipmentItem::factory()->for($shipment)->create(['value' => 25.50]);
    PackageItem::factory()->for($package)->create(['shipment_item_id' => $item->id, 'quantity' => 2]);

    expect(app(SpecialServiceResolver::class)->declaredValueForPackage($package))->toBe(51.00);
});

it('uses the per-package item sum instead of the shipment value for multi-package shipments', function (): void {
    $shipment = Shipment::factory()->create(['value' => 1000.00]);
    $packageA = Package::factory()->for($shipment)->create();
    Package::factory()->for($shipment)->create();

    $item = ShipmentItem::factory()->for($shipment)->create(['value' => 40.00]);
    PackageItem::factory()->for($packageA)->create(['shipment_item_id' => $item->id, 'quantity' => 1]);

    expect(app(SpecialServiceResolver::class)->declaredValueForPackage($packageA))->toBe(40.00);
});

it('returns null declared value when neither shipment nor items carry a value', function (): void {
    $shipment = Shipment::factory()->create(['value' => null]);
    $package = Package::factory()->for($shipment)->create();
    $item = ShipmentItem::factory()->for($shipment)->create(['value' => 0]);
    PackageItem::factory()->for($package)->create(['shipment_item_id' => $item->id, 'quantity' => 3]);

    expect(app(SpecialServiceResolver::class)->declaredValueForPackage($package))->toBeNull();
});

it('builds declared value config and throws when no value is derivable', function (): void {
    $valued = Shipment::factory()->create(['value' => 200.00]);
    $valuedPackage = Package::factory()->for($valued)->create();

    expect(app(SpecialServiceResolver::class)->configForPackage($valuedPackage, ['declared_value']))
        ->toBe(['declared_value' => ['amount' => 200.00, 'currency' => 'USD']]);

    $unvalued = Shipment::factory()->create(['value' => null]);
    $unvaluedPackage = Package::factory()->for($unvalued)->create();

    expect(fn () => app(SpecialServiceResolver::class)->configForPackage($unvaluedPackage, ['declared_value']))
        ->toThrow(MissingDeclaredValueException::class);
});

it('returns empty config when declared_value is not resolved', function (): void {
    $shipment = Shipment::factory()->create(['value' => null]);
    $package = Package::factory()->for($shipment)->create();

    expect(app(SpecialServiceResolver::class)->configForPackage($package, ['saturday_delivery']))->toBe([]);
});

function fedexRateFor(string $serviceCode): RateResponse
{
    return new RateResponse(
        carrier: 'FedEx',
        serviceCode: $serviceCode,
        serviceName: $serviceCode,
        price: 20.00,
    );
}
