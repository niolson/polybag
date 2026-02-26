<?php

use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    app(CacheService::class)->clearAll();
});

it('caches box sizes', function (): void {
    BoxSize::factory()->count(3)->create();

    // First call should hit the database
    $boxSizes1 = app(CacheService::class)->getBoxSizes();
    expect($boxSizes1)->toHaveCount(3);

    // Second call should use cache
    $boxSizes2 = app(CacheService::class)->getBoxSizes();
    expect($boxSizes2)->toHaveCount(3);

    // Verify cache was used
    expect(Cache::has('box_sizes_all'))->toBeTrue();
});

it('returns box sizes formatted for packing', function (): void {
    BoxSize::factory()->create([
        'code' => 'BOX-A',
        'height' => 10,
        'width' => 8,
        'length' => 6,
    ]);

    $formatted = app(CacheService::class)->getBoxSizesForPacking();

    expect($formatted)->toHaveKey('BOX-A')
        ->and($formatted['BOX-A'])->toHaveKeys(['id', 'code', 'height', 'width', 'length'])
        ->and($formatted['BOX-A']['height'])->toBe('10.00')
        ->and($formatted['BOX-A']['width'])->toBe('8.00')
        ->and($formatted['BOX-A']['length'])->toBe('6.00');
});

it('caches active carrier services', function (): void {
    $carrier = Carrier::factory()->create(['active' => true]);
    CarrierService::factory()->count(2)->create([
        'carrier_id' => $carrier->id,
        'active' => true,
    ]);
    CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'active' => false,
    ]);

    $services = app(CacheService::class)->getActiveCarrierServices();

    // Only active services should be returned
    expect($services)->toHaveCount(2);
    expect(Cache::has('carrier_services_active'))->toBeTrue();
});

it('groups active carrier services by carrier', function (): void {
    $usps = Carrier::factory()->create(['name' => 'USPS', 'active' => true]);
    $fedex = Carrier::factory()->create(['name' => 'FedEx', 'active' => true]);

    CarrierService::factory()->count(2)->create(['carrier_id' => $usps->id, 'active' => true]);
    CarrierService::factory()->create(['carrier_id' => $fedex->id, 'active' => true]);

    $grouped = app(CacheService::class)->getActiveCarrierServicesByCarrier();

    expect($grouped)->toHaveKeys(['USPS', 'FedEx'])
        ->and($grouped['USPS'])->toHaveCount(2)
        ->and($grouped['FedEx'])->toHaveCount(1);
});

it('clears box sizes cache', function (): void {
    BoxSize::factory()->create();
    app(CacheService::class)->getBoxSizes();

    expect(Cache::has('box_sizes_all'))->toBeTrue();

    app(CacheService::class)->clearBoxSizesCache();

    expect(Cache::has('box_sizes_all'))->toBeFalse();
});

it('clears carrier services cache', function (): void {
    $carrier = Carrier::factory()->create(['active' => true]);
    CarrierService::factory()->create(['carrier_id' => $carrier->id, 'active' => true]);
    app(CacheService::class)->getActiveCarrierServices();

    expect(Cache::has('carrier_services_active'))->toBeTrue();

    app(CacheService::class)->clearCarrierServicesCache();

    expect(Cache::has('carrier_services_active'))->toBeFalse();
});

it('clears all caches', function (): void {
    BoxSize::factory()->create();
    $carrier = Carrier::factory()->create(['active' => true]);
    CarrierService::factory()->create(['carrier_id' => $carrier->id, 'active' => true]);

    app(CacheService::class)->getBoxSizes();
    app(CacheService::class)->getActiveCarrierServices();

    expect(Cache::has('box_sizes_all'))->toBeTrue();
    expect(Cache::has('carrier_services_active'))->toBeTrue();

    app(CacheService::class)->clearAll();

    expect(Cache::has('box_sizes_all'))->toBeFalse();
    expect(Cache::has('carrier_services_active'))->toBeFalse();
});

it('excludes services with inactive carriers', function (): void {
    $activeCarrier = Carrier::factory()->create(['active' => true]);
    $inactiveCarrier = Carrier::factory()->create(['active' => false]);

    CarrierService::factory()->create(['carrier_id' => $activeCarrier->id, 'active' => true]);
    CarrierService::factory()->create(['carrier_id' => $inactiveCarrier->id, 'active' => true]);

    $services = app(CacheService::class)->getActiveCarrierServices();

    // Only services from active carriers should be returned
    expect($services)->toHaveCount(1);
});
