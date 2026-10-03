<?php

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Exceptions\InvalidPackSlipReceiptException;
use App\Models\Shipment;
use App\Models\User;
use App\Services\GotenbergService;
use App\Services\PackSlips\PackSlipReceipts;
use App\Services\PackSlips\PackSlipRenderer;

beforeEach(function (): void {
    $this->user = User::factory()->create(['role' => Role::User]);
    $this->receipts = app(PackSlipReceipts::class);
});

function sealedReceiptFor(array $shipmentIds, User $user): string
{
    $receipts = app(PackSlipReceipts::class);

    return $receipts->seal($receipts->issue($shipmentIds, $user));
}

it('records the receipt\'s version, issue time, printed time and user', function (): void {
    $this->freezeTime();
    $shipment = Shipment::factory()->create();
    $shipment->forceFill(['items_version' => 3])->save();

    $receipt = $this->receipts->issue([$shipment->id], $this->user);

    $this->actingAs($this->user)
        ->postJson(route('pack-slips.printed'), ['receipt' => $this->receipts->seal($receipt)])
        ->assertOk()
        ->assertExactJson(['recorded' => 1, 'skipped' => 0]);

    $shipment->refresh();

    expect($shipment->pack_slip_items_version)->toBe(3)
        ->and($shipment->getRawOriginal('pack_slip_receipt_issued_at'))->toBe($receipt->issuedAt)
        ->and($shipment->pack_slip_printed_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($shipment->pack_slip_printed_by_user_id)->toBe($this->user->id)
        ->and($shipment->hasPrintedPackSlip())->toBeTrue()
        ->and($shipment->packSlipIsOutOfDate())->toBeFalse();
});

it('changes nothing on the Shipment but the pack slip print columns', function (): void {
    $shipment = Shipment::factory()->create();
    $before = $shipment->fresh()->getAttributes();

    $this->travel(5)->minutes();
    $this->receipts->redeem($this->receipts->issue([$shipment->id], $this->user));

    $after = $shipment->fresh()->getAttributes();
    $printColumns = ['pack_slip_items_version', 'pack_slip_receipt_issued_at', 'pack_slip_printed_at', 'pack_slip_printed_by_user_id'];

    expect(array_diff_key($after, array_flip($printColumns)))->toBe(array_diff_key($before, array_flip($printColumns)));
});

it('refuses a tampered receipt', function (): void {
    $shipment = Shipment::factory()->create();
    $token = sealedReceiptFor([$shipment->id], $this->user);

    $this->actingAs($this->user)
        ->postJson(route('pack-slips.printed'), ['receipt' => substr($token, 0, -4).'AAAA'])
        ->assertStatus(422);

    expect($shipment->fresh()->hasPrintedPackSlip())->toBeFalse();
});

it('refuses an expired receipt', function (): void {
    $shipment = Shipment::factory()->create();
    $token = sealedReceiptFor([$shipment->id], $this->user);

    $this->travel(PackSlipReceipts::LIFETIME_SECONDS + 1)->seconds();

    $this->actingAs($this->user)
        ->postJson(route('pack-slips.printed'), ['receipt' => $token])
        ->assertStatus(422)
        ->assertJsonPath('error', 'This pack slip receipt has expired. View or print the slips again.');

    expect($shipment->fresh()->hasPrintedPackSlip())->toBeFalse();
});

it('refuses a receipt issued to another user', function (): void {
    $shipment = Shipment::factory()->create();
    $token = sealedReceiptFor([$shipment->id], User::factory()->create());

    $this->actingAs($this->user)
        ->postJson(route('pack-slips.printed'), ['receipt' => $token])
        ->assertStatus(422);

    expect(fn () => $this->receipts->open($token, $this->user))
        ->toThrow(InvalidPackSlipReceiptException::class, 'another user');
    expect($shipment->fresh()->hasPrintedPackSlip())->toBeFalse();
});

it('requires authentication', function (): void {
    $this->postJson(route('pack-slips.printed'), ['receipt' => 'x'])->assertUnauthorized();
});

it('skips a Shipment that shipped between rendering and redemption', function (): void {
    $open = Shipment::factory()->create();
    $shipped = Shipment::factory()->create();
    $receipt = $this->receipts->issue([$open->id, $shipped->id], $this->user);

    $shipped->update(['status' => ShipmentStatus::Shipped]);

    $redemption = $this->receipts->redeem($receipt);

    expect($redemption->recorded)->toBe(1)
        ->and($redemption->skipped)->toBe(1)
        ->and($open->fresh()->hasPrintedPackSlip())->toBeTrue()
        ->and($shipped->fresh()->hasPrintedPackSlip())->toBeFalse();
});

it('skips a Shipment deleted between rendering and redemption', function (): void {
    $shipment = Shipment::factory()->create();
    $receipt = $this->receipts->issue([$shipment->id], $this->user);
    $shipment->delete();

    expect($this->receipts->redeem($receipt)->skipped)->toBe(1);
});

it('leaves the slip out of date when items change after the version was read', function (): void {
    $shipment = Shipment::factory()->create();
    $receipt = $this->receipts->issue([$shipment->id], $this->user);

    // An import lands while the slip is still rendering.
    Shipment::query()->whereKey($shipment->id)->increment('items_version');

    $this->receipts->redeem($receipt);

    expect($shipment->fresh()->packSlipIsOutOfDate())->toBeTrue();
});

it('changes nothing when the same receipt is redeemed twice', function (): void {
    $this->freezeTime();
    $shipment = Shipment::factory()->create();
    $receipt = $this->receipts->issue([$shipment->id], $this->user);

    $this->receipts->redeem($receipt);
    $first = $shipment->fresh()->pack_slip_printed_at;

    $this->travel(10)->minutes();

    expect($this->receipts->redeem($receipt)->recorded)->toBe(0)
        ->and($shipment->fresh()->pack_slip_printed_at->equalTo($first))->toBeTrue();
});

it('keeps a newer print when an older receipt is redeemed after it', function (): void {
    $shipment = Shipment::factory()->create();
    $otherUser = User::factory()->create();

    $older = $this->receipts->issue([$shipment->id], $otherUser);
    $this->travel(1)->seconds();
    $newer = $this->receipts->issue([$shipment->id], $this->user);

    $this->receipts->redeem($newer);
    $this->receipts->redeem($older);

    expect($shipment->fresh()->pack_slip_printed_by_user_id)->toBe($this->user->id);
});

it('keeps a newer version when an older-version receipt is redeemed after it', function (): void {
    $shipment = Shipment::factory()->create();
    $stale = $this->receipts->issue([$shipment->id], $this->user);

    Shipment::query()->whereKey($shipment->id)->increment('items_version');
    $current = $this->receipts->issue([$shipment->id], $this->user);

    $this->receipts->redeem($current);
    $this->receipts->redeem($stale);

    expect($shipment->fresh()->pack_slip_items_version)->toBe(1)
        ->and($shipment->fresh()->packSlipIsOutOfDate())->toBeFalse();
});

it('records a newer receipt over one carried over without an issue time', function (): void {
    $shipment = Shipment::factory()->create();
    $shipment->forceFill(['pack_slip_items_version' => 0, 'pack_slip_printed_at' => now()->subDay()])->save();

    expect($this->receipts->redeem($this->receipts->issue([$shipment->id], $this->user))->recorded)->toBe(1);
});

it('leaves a Shipment out of date when a view drawn before an item change is marked printed', function (): void {
    $shipment = Shipment::factory()->create();

    $view = app(PackSlipRenderer::class)->view(PackSlipRun::forShipment($shipment->id), $this->user);
    $token = $view->getData()['receipt'];

    Shipment::query()->whereKey($shipment->id)->increment('items_version');

    $this->actingAs($this->user)
        ->postJson(route('pack-slips.printed'), ['receipt' => $token])
        ->assertOk();

    expect($shipment->fresh()->hasPrintedPackSlip())->toBeTrue()
        ->and($shipment->fresh()->packSlipIsOutOfDate())->toBeTrue();
});

it('sends a run over the job limit as consecutive jobs, each with its own receipt', function (): void {
    $this->mock(GotenbergService::class)
        ->shouldReceive('pdfFromView')
        ->twice()
        ->andReturn('%PDF-fake');

    $ids = Shipment::factory()->count(PackSlipRenderer::SLIPS_PER_PRINT_JOB + 1)->create()->modelKeys();

    $jobs = app(PackSlipRenderer::class)->printJobs(new PackSlipRun($ids), $this->user);

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]->count)->toBe(PackSlipRenderer::SLIPS_PER_PRINT_JOB)
        ->and($jobs[1]->count)->toBe(1);

    $first = $this->receipts->open($jobs[0]->receipt, $this->user);
    $second = $this->receipts->open($jobs[1]->receipt, $this->user);

    expect(array_keys($first->itemsVersions))->toBe(array_slice($ids, 0, PackSlipRenderer::SLIPS_PER_PRINT_JOB))
        ->and(array_keys($second->itemsVersions))->toBe([end($ids)]);

    // The second job failed in the browser, so only the first was acknowledged.
    $this->receipts->redeem($first);

    expect(Shipment::query()->whereNotNull('pack_slip_items_version')->count())->toBe(PackSlipRenderer::SLIPS_PER_PRINT_JOB)
        ->and(Shipment::find(end($ids))->hasPrintedPackSlip())->toBeFalse();
});
