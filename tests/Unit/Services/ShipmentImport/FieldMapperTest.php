<?php

use App\Services\ShipmentImport\FieldMapper;

it('maps shipment fields using configured mapping', function (): void {
    $mapper = new FieldMapper([
        'shipment' => [
            'order_number' => 'shipment_reference',
            'ship_to_name' => 'name',
            'ship_to_address1' => 'street_address',
        ],
    ]);

    $result = $mapper->mapShipment([
        'order_number' => 'ORD-001',
        'ship_to_name' => 'John Doe',
        'ship_to_address1' => '123 Main St',
    ]);

    expect($result)->toBe([
        'shipment_reference' => 'ORD-001',
        'name' => 'John Doe',
        'street_address' => '123 Main St',
    ]);
});

it('maps shipment item fields', function (): void {
    $mapper = new FieldMapper([
        'shipment_item' => [
            'item_sku' => 'sku',
            'qty' => 'quantity',
        ],
    ]);

    $result = $mapper->mapShipmentItem([
        'item_sku' => 'ABC123',
        'qty' => 5,
    ]);

    expect($result)->toBe([
        'sku' => 'ABC123',
        'quantity' => 5,
    ]);
});

it('skips fields not present in external data', function (): void {
    $mapper = new FieldMapper([
        'shipment' => [
            'order_number' => 'shipment_reference',
            'tracking' => 'tracking_number',
        ],
    ]);

    $result = $mapper->mapShipment([
        'order_number' => 'ORD-001',
        // 'tracking' is absent
    ]);

    expect($result)->toBe([
        'shipment_reference' => 'ORD-001',
    ]);
});

it('returns empty array when no mapping configured', function (): void {
    $mapper = new FieldMapper([]);

    expect($mapper->mapShipment(['foo' => 'bar']))->toBe([]);
    expect($mapper->mapShipmentItem(['foo' => 'bar']))->toBe([]);
});

it('handles stdClass input', function (): void {
    $mapper = new FieldMapper([
        'shipment' => [
            'order_number' => 'shipment_reference',
        ],
    ]);

    $data = new stdClass;
    $data->order_number = 'ORD-002';

    $result = $mapper->mapShipment($data);

    expect($result)->toBe([
        'shipment_reference' => 'ORD-002',
    ]);
});

it('handles array input', function (): void {
    $mapper = new FieldMapper([
        'shipment' => [
            'order_number' => 'shipment_reference',
        ],
    ]);

    $result = $mapper->mapShipment(['order_number' => 'ORD-003']);

    expect($result)->toBe([
        'shipment_reference' => 'ORD-003',
    ]);
});

it('handles empty mapping arrays', function (): void {
    $mapper = new FieldMapper([
        'shipment' => [],
        'shipment_item' => [],
    ]);

    expect($mapper->mapShipment(['foo' => 'bar']))->toBe([]);
    expect($mapper->mapShipmentItem(['foo' => 'bar']))->toBe([]);
});
