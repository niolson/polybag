<?php

use App\Enums\TrackingStatus;
use App\Services\PostageSources\ShopifyPostageSource;

function shopifyFulfillment(array $attributes = []): array
{
    return array_merge([
        'id' => 'gid://shopify/Fulfillment/1',
        'status' => 'SUCCESS',
        'displayStatus' => 'IN_TRANSIT',
        'trackingInfo' => [['number' => '9400111899223197428490', 'company' => 'USPS']],
    ], $attributes);
}

it('maps every delivery status Shopify reports onto a tracking status', function (string $displayStatus, TrackingStatus $expected): void {
    $response = app(ShopifyPostageSource::class)
        ->trackingFrom(shopifyFulfillment(['displayStatus' => $displayStatus]));

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe($expected);
})->with([
    ['LABEL_PURCHASED', TrackingStatus::PreTransit],
    ['CONFIRMED', TrackingStatus::PreTransit],
    ['CARRIER_PICKED_UP', TrackingStatus::InTransit],
    ['IN_TRANSIT', TrackingStatus::InTransit],
    ['OUT_FOR_DELIVERY', TrackingStatus::OutForDelivery],
    ['DELIVERED', TrackingStatus::Delivered],
    // Shopify has no equivalent of TrackingStatus::Returned, so every unhappy
    // outcome it does report collapses into one bucket.
    ['ATTEMPTED_DELIVERY', TrackingStatus::Exception],
    ['DELAYED', TrackingStatus::Exception],
    ['NOT_DELIVERED', TrackingStatus::Exception],
]);

it('carries the delivery timestamps Shopify reports', function (): void {
    $response = app(ShopifyPostageSource::class)->trackingFrom(shopifyFulfillment([
        'displayStatus' => 'DELIVERED',
        'deliveredAt' => '2026-09-04T16:31:00Z',
        'estimatedDeliveryAt' => '2026-09-04T20:00:00Z',
    ]));

    expect($response->deliveredAt?->toIso8601String())->toBe('2026-09-04T16:31:00+00:00')
        ->and($response->estimatedDeliveryAt?->toIso8601String())->toBe('2026-09-04T20:00:00+00:00')
        ->and($response->statusLabel)->toBe('Delivered');
});

it('reads scan detail out of fulfillment events, newest first', function (): void {
    $response = app(ShopifyPostageSource::class)->trackingFrom(shopifyFulfillment([
        'events' => ['nodes' => [
            [
                'status' => 'CARRIER_PICKED_UP',
                'happenedAt' => '2026-09-02T09:00:00Z',
                'message' => null,
                'city' => 'Ankeny',
                'province' => 'IA',
                'zip' => '50021',
                'country' => 'US',
            ],
            [
                'status' => 'IN_TRANSIT',
                'happenedAt' => '2026-09-03T14:02:00Z',
                'message' => 'Arrived at regional facility',
                'city' => 'Des Moines',
                'province' => 'IA',
                'zip' => null,
                'country' => 'US',
            ],
        ]],
    ]));

    expect($response->events)->toHaveCount(2)
        ->and($response->events[0]->description)->toBe('Arrived at regional facility')
        ->and($response->events[0]->location)->toBe('Des Moines, IA, US')
        ->and($response->events[0]->status)->toBe(TrackingStatus::InTransit->value)
        // No message: the event status is the only description Shopify gives.
        ->and($response->events[1]->description)->toBe('Carrier picked up')
        ->and($response->events[1]->location)->toBe('Ankeny, IA, 50021, US');
});

it('treats an empty events connection as a normal answer, not a failure', function (): void {
    // Shopify documents no path by which it writes fulfillment events itself —
    // `fulfillmentEventCreate` is for apps and fulfillment services — so an empty
    // connection is the expected shape, and displayStatus stands on its own.
    $response = app(ShopifyPostageSource::class)->trackingFrom(shopifyFulfillment([
        'displayStatus' => 'IN_TRANSIT',
        'events' => ['nodes' => []],
    ]));

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::InTransit)
        ->and($response->events)->toBe([]);
});

it('records no status when no fulfillment could be matched', function (): void {
    $response = app(ShopifyPostageSource::class)->trackingFrom(null);

    expect($response->success)->toBeFalse()
        ->and($response->status)->toBeNull()
        ->and($response->message)->toContain('no fulfillment carrying this tracking number');
});

it('reports a voided label as untrackable rather than as a delivery status', function (): void {
    $response = app(ShopifyPostageSource::class)
        ->trackingFrom(shopifyFulfillment(['displayStatus' => 'LABEL_VOIDED']));

    expect($response->success)->toBeFalse()
        ->and($response->status)->toBeNull()
        ->and($response->message)->toContain('voided');
});

it('records no status for a display status it does not recognize', function (): void {
    $response = app(ShopifyPostageSource::class)
        ->trackingFrom(shopifyFulfillment(['displayStatus' => 'SOMETHING_NEW']));

    expect($response->success)->toBeFalse()
        ->and($response->status)->toBeNull();
});
