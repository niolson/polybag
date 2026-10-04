<?php

use App\DataTransferObjects\PackSlips\PackSlipQueueFilters;
use App\Enums\PackSlipQueueTab;
use App\Enums\ShipmentStatus;
use App\Models\Channel;
use App\Models\Client;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Services\PackSlips\PackSlipQueue;
use App\Services\SettingsService;

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

function queuedIds(?PackSlipQueueFilters $filters = null): array
{
    return app(PackSlipQueue::class)->notPrinted($filters ?? new PackSlipQueueFilters)
        ->pluck('shipments.id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

function printedShipment(array $attributes = []): Shipment
{
    return Shipment::factory()->create([
        'items_version' => 2,
        'pack_slip_items_version' => 2,
        'pack_slip_printed_at' => now(),
        ...$attributes,
    ]);
}

it('lists a never-printed Shipment', function (): void {
    $shipment = Shipment::factory()->create();

    expect(queuedIds())->toBe([$shipment->id]);
});

it('does not list a Shipment whose printed slip is current', function (): void {
    printedShipment();

    expect(queuedIds())->toBe([]);
});

it('lists a Shipment whose slip is out of date', function (): void {
    $shipment = printedShipment(['items_version' => 3]);

    expect(queuedIds())->toBe([$shipment->id]);
});

it('leaves off a Shipment in an in-progress pick batch, and reports it with its batch', function (): void {
    $shipment = Shipment::factory()->create();
    $batch = PickBatch::factory()->create();
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $shipment->id]);

    $leftOff = app(PackSlipQueue::class)->leftOffForPickBatches();

    expect(queuedIds())->toBe([])
        ->and($leftOff['count'])->toBe(1)
        ->and($leftOff['batches']->modelKeys())->toBe([$batch->id]);
});

it('lists a Shipment whose pick batch was cancelled', function (): void {
    $shipment = Shipment::factory()->create();
    PickBatchShipment::factory()->create([
        'pick_batch_id' => PickBatch::factory()->cancelled(),
        'shipment_id' => $shipment->id,
    ]);

    expect(queuedIds())->toBe([$shipment->id])
        ->and(app(PackSlipQueue::class)->leftOffForPickBatches()['count'])->toBe(0);
});

it('does not list a Shipment printed from a completed pick batch', function (): void {
    $shipment = printedShipment();
    PickBatchShipment::factory()->create([
        'pick_batch_id' => PickBatch::factory()->completed(),
        'shipment_id' => $shipment->id,
    ]);

    expect(queuedIds())->toBe([]);
});

it('does not list a shipped Shipment', function (): void {
    Shipment::factory()->create(['status' => ShipmentStatus::Shipped]);

    expect(queuedIds())->toBe([]);
});

it('lists nothing when picking is required before shipping, and says slips print from batches', function (): void {
    Shipment::factory()->create();
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    app(SettingsService::class)->set('require_picking_before_shipping', true, 'boolean');

    $queue = app(PackSlipQueue::class);

    expect($queue->printsFromPickBatches())->toBeTrue()
        ->and(queuedIds())->toBe([])
        ->and($queue->next(10))->toBe([]);
});

it('orders expedited first, then oldest first, counting no shipping method as standard', function (): void {
    $expedited = ShippingMethod::factory()->create(['is_expedited' => true]);
    $standard = ShippingMethod::factory()->create(['is_expedited' => false]);

    $oldStandard = Shipment::factory()->create(['shipping_method_id' => $standard->id, 'created_at' => now()->subDays(3)]);
    $noMethod = Shipment::factory()->withoutShippingMethod()->create(['created_at' => now()->subDays(2)]);
    $newExpedited = Shipment::factory()->create(['shipping_method_id' => $expedited->id, 'created_at' => now()->subDay()]);
    $newStandard = Shipment::factory()->create(['shipping_method_id' => $standard->id, 'created_at' => now()]);

    expect(queuedIds())->toBe([$newExpedited->id, $oldStandard->id, $noMethod->id, $newStandard->id]);
});

it('takes the next N by urgency whichever Client they belong to', function (): void {
    $clientA = Client::factory()->create(['name' => 'Acme']);
    $clientB = Client::factory()->create(['name' => 'Bolt']);
    $standard = ShippingMethod::factory()->create(['is_expedited' => false]);
    $expedited = ShippingMethod::factory()->create(['is_expedited' => true]);

    $backlog = collect(range(1, 4))->map(fn (int $days): Shipment => Shipment::factory()->create([
        'client_id' => $clientA->id,
        'shipping_method_id' => $standard->id,
        'created_at' => now()->subDays(10 + $days),
    ]));
    $rush = Shipment::factory()->create([
        'client_id' => $clientB->id,
        'shipping_method_id' => $expedited->id,
        'created_at' => now(),
    ]);

    $next = app(PackSlipQueue::class)->next(3);

    expect($next)->toHaveCount(3)
        ->and($next[0])->toBe($rush->id)
        ->and($backlog->pluck('id')->all())->toContain($next[1], $next[2]);
});

it('takes the next N within the filters, and fewer when fewer are waiting', function (): void {
    $amazon = Channel::factory()->create();
    $shipment = Shipment::factory()->create(['channel_id' => $amazon->id]);
    Shipment::factory()->count(2)->create();

    expect(app(PackSlipQueue::class)->next(25, new PackSlipQueueFilters(channelId: $amazon->id)))
        ->toBe([$shipment->id]);
});

it('filters by Client, Channel and Shipping Method', function (): void {
    $client = Client::factory()->create();
    $channel = Channel::factory()->create();
    $method = ShippingMethod::factory()->create();

    $byClient = Shipment::factory()->create(['client_id' => $client->id]);
    $byChannel = Shipment::factory()->create(['channel_id' => $channel->id]);
    $byMethod = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    expect(queuedIds(new PackSlipQueueFilters(clientId: $client->id)))->toBe([$byClient->id])
        ->and(queuedIds(new PackSlipQueueFilters(channelId: $channel->id)))->toBe([$byChannel->id])
        ->and(queuedIds(new PackSlipQueueFilters(shippingMethodId: $method->id)))->toBe([$byMethod->id]);
});

it('groups a run by Client name, keeping urgency order within each Client', function (): void {
    $zulu = Client::factory()->create(['name' => 'Zulu']);
    $alpha = Client::factory()->create(['name' => 'Alpha']);
    $expedited = ShippingMethod::factory()->create(['is_expedited' => true]);

    $zuluRush = Shipment::factory()->create(['client_id' => $zulu->id, 'shipping_method_id' => $expedited->id, 'created_at' => now()]);
    $alphaOld = Shipment::factory()->create(['client_id' => $alpha->id, 'created_at' => now()->subDays(5)]);
    $zuluOld = Shipment::factory()->create(['client_id' => $zulu->id, 'created_at' => now()->subDays(4)]);
    $alphaNew = Shipment::factory()->create(['client_id' => $alpha->id, 'created_at' => now()->subDay()]);

    $run = app(PackSlipQueue::class)->run([$zuluOld->id, $alphaNew->id, $zuluRush->id, $alphaOld->id]);

    expect($run->shipmentIds)->toBe([$alphaOld->id, $alphaNew->id, $zuluRush->id, $zuluOld->id]);
});

it('drops Shipments no longer open from a run', function (): void {
    $open = Shipment::factory()->create();
    $shipped = Shipment::factory()->create(['status' => ShipmentStatus::Shipped]);

    expect(app(PackSlipQueue::class)->run([$open->id, $shipped->id])->shipmentIds)->toBe([$open->id]);
});

function printedIds(?PackSlipQueueFilters $filters = null): array
{
    return app(PackSlipQueue::class)->printed($filters ?? new PackSlipQueueFilters)
        ->pluck('shipments.id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

it('lists on the Printed tab only open Shipments whose slip is current', function (): void {
    $current = printedShipment();
    printedShipment(['items_version' => 3]);
    Shipment::factory()->create();
    printedShipment(['status' => ShipmentStatus::Shipped]);

    expect(printedIds())->toBe([$current->id]);
});

it('orders the Printed tab newest printed first, keeping a run together in a stable order', function (): void {
    $older = printedShipment(['pack_slip_printed_at' => now()->subHour()]);
    $issuedAt = now()->subMinute();
    $runFirst = printedShipment(['pack_slip_printed_at' => now(), 'pack_slip_receipt_issued_at' => $issuedAt]);
    $runSecond = printedShipment(['pack_slip_printed_at' => now(), 'pack_slip_receipt_issued_at' => $issuedAt]);
    $earlierJob = printedShipment(['pack_slip_printed_at' => now(), 'pack_slip_receipt_issued_at' => $issuedAt->copy()->subSecond()]);

    expect(printedIds())->toBe([$runFirst->id, $runSecond->id, $earlierJob->id, $older->id]);
});

it('leaves off the Printed tab a Shipment in an in-progress pick batch, and reports it per tab', function (): void {
    $printed = printedShipment();
    $waiting = Shipment::factory()->create();
    $batch = PickBatch::factory()->create();
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $printed->id]);
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $waiting->id]);
    printedShipment();

    $leftOff = app(PackSlipQueue::class)->leftOffForPickBatches(tab: PackSlipQueueTab::Printed);

    expect(printedIds())->not->toContain($printed->id)
        ->and($leftOff['count'])->toBe(1)
        ->and($leftOff['batches']->modelKeys())->toBe([$batch->id]);
});

it('lists nothing on the Printed tab when picking is required before shipping', function (): void {
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    app(SettingsService::class)->set('require_picking_before_shipping', true, 'boolean');
    printedShipment();

    expect(printedIds())->toBe([]);
});

it('filters the Printed tab by Client, Channel and Shipping Method', function (): void {
    $client = Client::factory()->create();
    $channel = Channel::factory()->create();
    $method = ShippingMethod::factory()->create();
    $match = printedShipment(['client_id' => $client->id, 'channel_id' => $channel->id, 'shipping_method_id' => $method->id]);
    printedShipment(['client_id' => $client->id, 'channel_id' => $channel->id]);

    expect(printedIds(new PackSlipQueueFilters($client->id, $channel->id, $method->id)))->toBe([$match->id]);
});
