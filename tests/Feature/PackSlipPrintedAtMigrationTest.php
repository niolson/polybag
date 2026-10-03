<?php

use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\PackSlips\PackSlipReceipts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->migration = require database_path('migrations/2026_10_03_170611_move_pack_slip_printed_at_from_pick_batch_shipments_to_shipments.php');
    $this->migration->down();
});

afterEach(function (): void {
    if (Schema::hasColumn('pick_batch_shipments', 'pack_slip_printed_at')) {
        $this->migration->up();
    }
});

function batchMembership(Shipment $shipment, ?string $printedAt): void
{
    $membership = PickBatchShipment::factory()->create([
        'pick_batch_id' => PickBatch::factory()->create()->id,
        'shipment_id' => $shipment->id,
    ]);

    DB::table('pick_batch_shipments')->where('id', $membership->id)->update(['pack_slip_printed_at' => $printedAt]);
}

it('copies the latest batch print onto the Shipment, at its current items version, with no issue time or user', function (): void {
    $shipment = Shipment::factory()->create(['items_version' => 3]);
    batchMembership($shipment, '2026-09-01 10:00:00');
    batchMembership($shipment, '2026-09-02 10:00:00');
    batchMembership($shipment, null);

    $this->migration->up();

    $row = DB::table('shipments')->where('id', $shipment->id)->first();

    expect($row->pack_slip_printed_at)->toBe('2026-09-02 10:00:00')
        ->and($row->pack_slip_items_version)->toBe(3)
        ->and($row->pack_slip_receipt_issued_at)->toBeNull()
        ->and($row->pack_slip_printed_by_user_id)->toBeNull()
        ->and(Schema::hasColumn('pick_batch_shipments', 'pack_slip_printed_at'))->toBeFalse();
});

it('leaves a Shipment no batch printed unprinted', function (): void {
    $shipment = Shipment::factory()->create();
    batchMembership($shipment, null);

    $this->migration->up();

    expect($shipment->fresh()->hasPrintedPackSlip())->toBeFalse();
});

it('keeps a newer print made from the Shipment\'s own page', function (): void {
    $user = User::factory()->create();
    $shipment = Shipment::factory()->create();
    $receipts = app(PackSlipReceipts::class);
    $receipts->redeem($receipts->issue([$shipment->id], $user));
    $before = DB::table('shipments')->where('id', $shipment->id)->first();

    batchMembership($shipment, now()->subDay()->toDateTimeString());

    $this->migration->up();

    expect(DB::table('shipments')->where('id', $shipment->id)->first())->toEqual($before);
});

it('lets the next real receipt record over a carried-over print', function (): void {
    $user = User::factory()->create();
    $shipment = Shipment::factory()->create();
    batchMembership($shipment, '2026-09-02 10:00:00');

    $this->migration->up();

    $receipts = app(PackSlipReceipts::class);

    expect($receipts->redeem($receipts->issue([$shipment->id], $user))->recorded)->toBe(1)
        ->and($shipment->fresh()->pack_slip_printed_by_user_id)->toBe($user->id);
});
