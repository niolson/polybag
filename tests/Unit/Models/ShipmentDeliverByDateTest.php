<?php

use App\Models\Shipment;
use App\Models\ShippingMethod;
use Carbon\Carbon;

it('returns explicit deliver_by when set', function (): void {
    $deliverBy = Carbon::parse('2026-03-15');
    $shipment = Shipment::factory()->create(['deliver_by' => $deliverBy]);

    expect($shipment->getDeliverByDate()->toDateString())->toBe('2026-03-15');
});

it('calculates from commitment_days skipping weekends', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-02-23')); // Monday

    $method = ShippingMethod::factory()->create(['commitment_days' => 3]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    $deadline = $shipment->getDeliverByDate();

    // Monday + 3 business days = Thursday
    expect($deadline->toDateString())->toBe('2026-02-26');
});

it('skips weekends when calculating commitment days', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-02-26')); // Thursday

    $method = ShippingMethod::factory()->create(['commitment_days' => 3]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    $deadline = $shipment->getDeliverByDate();

    // Thursday + 3 business days = skips Sat/Sun = Tuesday March 3
    expect($deadline->toDateString())->toBe('2026-03-03');
});

it('returns null when no deadline is available', function (): void {
    $method = ShippingMethod::factory()->create(['commitment_days' => null]);
    $shipment = Shipment::factory()->create([
        'deliver_by' => null,
        'shipping_method_id' => $method->id,
    ]);

    expect($shipment->getDeliverByDate())->toBeNull();
});

it('returns null when no shipping method exists', function (): void {
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
        'deliver_by' => null,
    ]);

    expect($shipment->getDeliverByDate())->toBeNull();
});

it('prefers explicit deliver_by over commitment_days', function (): void {
    $deliverBy = Carbon::parse('2026-03-01');
    $method = ShippingMethod::factory()->create(['commitment_days' => 5]);
    $shipment = Shipment::factory()->create([
        'deliver_by' => $deliverBy,
        'shipping_method_id' => $method->id,
    ]);

    expect($shipment->getDeliverByDate()->toDateString())->toBe('2026-03-01');
});
