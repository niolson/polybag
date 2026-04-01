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
        'phone' => '+1 213-442-1463 ext. 26259',
    ]);

    expect($shipment->refresh()->country)->toBe('US')
        ->and($shipment->state_or_province)->toBe('CA')
        ->and($shipment->validated_country)->toBe('CA')
        ->and($shipment->validated_state_or_province)->toBe('ON')
        ->and($shipment->phone)->toBe('+1 213-442-1463 ext. 26259')
        ->and($shipment->phone_e164)->toBe('+12134421463')
        ->and($shipment->phone_extension)->toBe('26259');
});

it('normalizes location address fields on save', function (): void {
    $location = Location::factory()->create([
        'country' => 'United States',
        'state_or_province' => 'Washington',
        'phone' => '(425) 555-0100',
    ]);

    expect($location->refresh()->country)->toBe('US')
        ->and($location->state_or_province)->toBe('WA')
        ->and($location->phone)->toBe('(425) 555-0100')
        ->and($location->phone_e164)->toBe('+14255550100');
});
