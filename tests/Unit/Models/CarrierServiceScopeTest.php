<?php

use App\Models\Carrier;
use App\Models\CarrierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('scopeActive returns only active services', function (): void {
    $carrier = Carrier::factory()->create();
    CarrierService::factory()->create(['carrier_id' => $carrier->id, 'active' => true]);
    CarrierService::factory()->create(['carrier_id' => $carrier->id, 'active' => false]);

    $active = CarrierService::active()->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->active)->toBeTrue();
});

it('scopeWithActiveCarrier returns only services with active carriers', function (): void {
    $activeCarrier = Carrier::factory()->create(['active' => true]);
    $inactiveCarrier = Carrier::factory()->create(['active' => false]);

    CarrierService::factory()->create(['carrier_id' => $activeCarrier->id, 'active' => true]);
    CarrierService::factory()->create(['carrier_id' => $inactiveCarrier->id, 'active' => true]);

    $services = CarrierService::withActiveCarrier()->get();

    expect($services)->toHaveCount(1)
        ->and($services->first()->carrier_id)->toBe($activeCarrier->id);
});

it('chains active and withActiveCarrier scopes together', function (): void {
    $activeCarrier = Carrier::factory()->create(['active' => true]);
    $inactiveCarrier = Carrier::factory()->create(['active' => false]);

    // Active service, active carrier - should be included
    CarrierService::factory()->create(['carrier_id' => $activeCarrier->id, 'active' => true]);
    // Inactive service, active carrier - excluded by scopeActive
    CarrierService::factory()->create(['carrier_id' => $activeCarrier->id, 'active' => false]);
    // Active service, inactive carrier - excluded by scopeWithActiveCarrier
    CarrierService::factory()->create(['carrier_id' => $inactiveCarrier->id, 'active' => true]);

    $services = CarrierService::active()->withActiveCarrier()->get();

    expect($services)->toHaveCount(1)
        ->and($services->first()->carrier_id)->toBe($activeCarrier->id)
        ->and($services->first()->active)->toBeTrue();
});

it('clears cache on save', function (): void {
    Cache::put('carrier_services_active', 'cached-value', 3600);

    $carrier = Carrier::factory()->create();
    CarrierService::factory()->create(['carrier_id' => $carrier->id]);

    expect(Cache::has('carrier_services_active'))->toBeFalse();
});

it('clears cache on delete', function (): void {
    $carrier = Carrier::factory()->create();
    $service = CarrierService::factory()->create(['carrier_id' => $carrier->id]);

    Cache::put('carrier_services_active', 'cached-value', 3600);

    $service->delete();

    expect(Cache::has('carrier_services_active'))->toBeFalse();
});
