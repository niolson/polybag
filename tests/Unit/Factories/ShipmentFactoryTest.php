<?php

use App\Enums\Deliverability;
use App\Models\Shipment;

it('creates a basic shipment', function (): void {
    $shipment = Shipment::factory()->create();

    expect($shipment)->toBeInstanceOf(Shipment::class)
        ->and($shipment->shipment_reference)->not->toBeNull()
        ->and($shipment->address1)->not->toBeNull()
        ->and($shipment->city)->not->toBeNull()
        ->and($shipment->state)->not->toBeNull()
        ->and($shipment->zip)->not->toBeNull()
        ->and($shipment->country)->toBe('US')
        ->and($shipment->shipped)->toBeFalsy(); // shipped defaults to null/false
});

it('creates a validated shipment', function (): void {
    $shipment = Shipment::factory()->validated()->create();

    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->validation_message)->toBe('Address confirmed deliverable')
        ->and($shipment->validated_address1)->not->toBeNull();
});

it('creates an undeliverable shipment', function (): void {
    $shipment = Shipment::factory()->undeliverable()->create();

    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::No)
        ->and($shipment->validation_message)->toBe('Address found but not confirmed as deliverable');
});

it('creates a maybe deliverable shipment', function (): void {
    $shipment = Shipment::factory()->maybeDeliverable()->create();

    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Maybe)
        ->and($shipment->validation_message)->toBe('Primary address confirmed, secondary number missing');
});

it('creates a shipped shipment', function (): void {
    $shipment = Shipment::factory()->shipped()->create();

    expect($shipment->shipped)->toBeTrue();
});

it('creates an international shipment', function (): void {
    $shipment = Shipment::factory()->international()->create();

    expect($shipment->country)->not->toBe('US')
        ->and($shipment->country)->toBeIn(['CA', 'MX', 'GB', 'DE', 'FR', 'AU', 'JP']);
});

it('creates a residential shipment', function (): void {
    $shipment = Shipment::factory()->residential()->create();

    expect($shipment->residential)->toBeTrue()
        ->and($shipment->validated_residential)->toBeTrue();
});

it('creates a commercial shipment', function (): void {
    $shipment = Shipment::factory()->commercial()->create();

    expect($shipment->residential)->toBeFalse()
        ->and($shipment->validated_residential)->toBeFalse()
        ->and($shipment->company)->not->toBeNull();
});

it('creates a shipment without shipping method', function (): void {
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

    expect($shipment->shipping_method_id)->toBeNull();
});

it('can chain multiple states', function (): void {
    $shipment = Shipment::factory()
        ->validated()
        ->residential()
        ->shipped()
        ->create();

    expect($shipment->checked)->toBeTrue()
        ->and($shipment->deliverability)->toBe(Deliverability::Yes)
        ->and($shipment->residential)->toBeTrue()
        ->and($shipment->shipped)->toBeTrue();
});
