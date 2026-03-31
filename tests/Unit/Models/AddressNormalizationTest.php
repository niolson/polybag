<?php

use App\Models\Location;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes shipment address fields on save', function (): void {
    $shipment = Shipment::factory()->create([
        'country' => 'United States',
        'state_or_province' => 'California',
        'validated_country' => 'Canada',
        'validated_state_or_province' => 'Ontario',
    ]);

    expect($shipment->refresh()->country)->toBe('US')
        ->and($shipment->state_or_province)->toBe('CA')
        ->and($shipment->validated_country)->toBe('CA')
        ->and($shipment->validated_state_or_province)->toBe('ON');
});

it('normalizes location address fields on save', function (): void {
    $location = Location::factory()->create([
        'country' => 'United States',
        'state_or_province' => 'Washington',
    ]);

    expect($location->refresh()->country)->toBe('US')
        ->and($location->state_or_province)->toBe('WA');
});
