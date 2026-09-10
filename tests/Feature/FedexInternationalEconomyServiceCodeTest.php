<?php

use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ShippingMethod;

function runFedexInternationalEconomyCorrection(): void
{
    $migration = require database_path('migrations/2026_09_10_120000_correct_fedex_international_economy_service_code.php');

    $migration->up();
}

it('corrects the unbuyable FedEx International Economy service code', function (): void {
    $fedex = Carrier::factory()->fedex()->create();
    $service = CarrierService::create([
        'carrier_id' => $fedex->id,
        'service_code' => 'FEDEX_INTERNATIONAL_ECONOMY',
        'name' => 'FedEx International Economy®',
    ]);

    runFedexInternationalEconomyCorrection();
    runFedexInternationalEconomyCorrection();

    expect($service->refresh()->service_code)->toBe('INTERNATIONAL_ECONOMY')
        ->and($service->active)->toBeTrue();
});

it('keeps the shipping methods that offer it pointed at the same row', function (): void {
    $fedex = Carrier::factory()->fedex()->create();
    $service = CarrierService::create([
        'carrier_id' => $fedex->id,
        'service_code' => 'FEDEX_INTERNATIONAL_ECONOMY',
        'name' => 'FedEx International Economy®',
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service);

    runFedexInternationalEconomyCorrection();

    expect($method->refresh()->carrierServices->pluck('service_code')->all())
        ->toBe(['INTERNATIONAL_ECONOMY']);
});

it('retires rather than duplicates when the correct row already exists', function (): void {
    $fedex = Carrier::factory()->fedex()->create();
    $correct = CarrierService::create([
        'carrier_id' => $fedex->id,
        'service_code' => 'INTERNATIONAL_ECONOMY',
        'name' => 'FedEx International Economy®',
    ]);
    $stale = CarrierService::create([
        'carrier_id' => $fedex->id,
        'service_code' => 'FEDEX_INTERNATIONAL_ECONOMY',
        'name' => 'FedEx International Economy®',
    ]);

    runFedexInternationalEconomyCorrection();

    expect($stale->refresh()->active)->toBeFalse()
        ->and($correct->refresh()->active)->toBeTrue()
        ->and(CarrierService::where('carrier_id', $fedex->id)->where('service_code', 'INTERNATIONAL_ECONOMY')->count())->toBe(1);
});

it('leaves every other FedEx service alone', function (): void {
    $fedex = Carrier::factory()->fedex()->create();
    $priority = CarrierService::create([
        'carrier_id' => $fedex->id,
        'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY',
        'name' => 'FedEx International Priority®',
    ]);

    runFedexInternationalEconomyCorrection();

    expect($priority->refresh()->service_code)->toBe('FEDEX_INTERNATIONAL_PRIORITY');
});

it('does nothing on an install with no FedEx carrier', function (): void {
    expect(fn () => runFedexInternationalEconomyCorrection())->not->toThrow(Exception::class);
});
