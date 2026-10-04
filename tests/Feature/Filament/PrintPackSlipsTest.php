<?php

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\PackSlipState;
use App\Enums\Role;
use App\Filament\Pages\PrintPackSlips;
use App\Models\Channel;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\GotenbergService;
use App\Services\PackSlips\PackSlipReceipts;
use App\Services\PackSlips\PackSlipViews;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->shipper = User::factory()->create(['role' => Role::User]);
    $this->actingAs($this->shipper);
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

function fakeQueuePdfRenderer(): void
{
    $gotenberg = Mockery::mock(GotenbergService::class);
    $gotenberg->shouldReceive('pdfFromView')->andReturn('%PDF-fake');
    app()->instance(GotenbergService::class, $gotenberg);
}

/**
 * The Shipment IDs a print dispatch carried, read back from its receipts.
 *
 * @return list<int>
 */
function dispatchedShipmentIds(mixed $page, User $user): array
{
    $dispatch = collect($page->effects['dispatches'])->firstWhere('name', 'print-pack-slips');

    return collect($dispatch['params']['jobs'])
        ->flatMap(fn (array $job): array => array_keys(app(PackSlipReceipts::class)->open($job['receipt'], $user)->itemsVersions))
        ->all();
}

function effectsJson(mixed $page): string
{
    return (string) json_encode($page->effects, JSON_UNESCAPED_SLASHES);
}

it('lets a Shipper open the page and see the Shipments waiting', function (): void {
    $waiting = Shipment::factory()->create();
    $printed = Shipment::factory()->create(['pack_slip_items_version' => 0]);

    Livewire::test(PrintPackSlips::class)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$printed]);
});

it('prints the next N, and the Shipments leave the list only once the receipt is redeemed', function (): void {
    fakeQueuePdfRenderer();
    $this->shipper->update(['pack_slip_batch_size' => 2]);
    $shipments = collect(range(3, 1))->map(fn (int $days): Shipment => Shipment::factory()->create(['created_at' => now()->subDays($days)]));

    $page = Livewire::test(PrintPackSlips::class)
        ->assertActionHasLabel('printNext', 'Print next 2')
        ->callAction('printNext')
        ->assertDispatched('print-pack-slips');

    expect(dispatchedShipmentIds($page, $this->shipper))->toBe([$shipments[0]->id, $shipments[1]->id]);

    // Nothing is recorded until QZ Tray acknowledges the job.
    Livewire::test(PrintPackSlips::class)->assertCanSeeTableRecords($shipments);

    $job = collect($page->effects['dispatches'])->firstWhere('name', 'print-pack-slips')['params']['jobs'][0];
    $this->postJson(route('pack-slips.printed'), ['receipt' => $job['receipt']])->assertOk();

    Livewire::test(PrintPackSlips::class)
        ->assertCanSeeTableRecords([$shipments[2]])
        ->assertCanNotSeeTableRecords([$shipments[0], $shipments[1]]);
});

it('remembers the batch size per user', function (): void {
    $other = User::factory()->create(['role' => Role::User]);

    Livewire::test(PrintPackSlips::class)
        ->callAction('batchSize', data: ['pack_slip_batch_size' => 40])
        ->assertHasNoActionErrors()
        ->assertActionHasLabel('printNext', 'Print next 40');

    expect($this->shipper->fresh()->pack_slip_batch_size)->toBe(40)
        ->and($other->fresh()->pack_slip_batch_size)->toBe(25);
});

it('prints only the selected Shipments', function (): void {
    fakeQueuePdfRenderer();
    [$first, $second, $third] = Shipment::factory()->count(3)->create();

    $page = Livewire::test(PrintPackSlips::class)
        ->selectTableRecords([$first, $third])
        ->callAction(TestAction::make('printSelected')->table()->bulk())
        ->assertDispatched('print-pack-slips');

    expect(dispatchedShipmentIds($page, $this->shipper))->toEqualCanonicalizing([$first->id, $third->id]);
});

it('opens a view of exactly the selected Shipments, with a receipt and Mark as printed', function (): void {
    [$first, $second] = Shipment::factory()->count(2)->create();

    $page = Livewire::test(PrintPackSlips::class)
        ->selectTableRecords([$second])
        ->callAction(TestAction::make('viewSelected')->table()->bulk())
        ->assertNotified('Pack slips opened in a new tab');

    preg_match('#/pack-slips/view/([A-Za-z0-9]+)#', effectsJson($page), $matches);

    $response = $this->get(route('pack-slips.view', $matches[1]))
        ->assertOk()
        ->assertSee($second->shipment_reference)
        ->assertDontSee($first->shipment_reference)
        ->assertSee('Mark as printed');

    $receipt = app(PackSlipReceipts::class)->open($response->viewData('receipt'), $this->shipper);

    expect(array_keys($receipt->itemsVersions))->toBe([$second->id]);

    $this->postJson(route('pack-slips.printed'), ['receipt' => $response->viewData('receipt')])->assertOk();

    expect($second->fresh()->hasPrintedPackSlip())->toBeTrue();
});

it('says a view has expired once its key is gone', function (): void {
    $this->get(route('pack-slips.view', 'no-such-key'))
        ->assertStatus(410)
        ->assertSee('This pack slip view has expired');
});

it('keeps a stored view for an hour', function (): void {
    $shipment = Shipment::factory()->create();
    $key = app(PackSlipViews::class)->put(new PackSlipRun([$shipment->id]));

    $this->travel(PackSlipViews::LIFETIME_SECONDS + 1)->seconds();

    $this->get(route('pack-slips.view', $key))->assertStatus(410);
});

it('returns a Shipment to the list when its items change after printing', function (): void {
    $shipment = Shipment::factory()->create();
    $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);
    $shipment->refresh()->forceFill(['pack_slip_items_version' => $shipment->items_version])->save();

    Livewire::test(PrintPackSlips::class)->assertCanNotSeeTableRecords([$shipment]);

    $item->update(['quantity' => 2]);

    Livewire::test(PrintPackSlips::class)->assertCanSeeTableRecords([$shipment]);
});

it('explains Shipments left off for in-progress pick batches and links managers to the batches', function (): void {
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    $batch = PickBatch::factory()->create();
    PickBatchShipment::factory()->count(2)->create(['pick_batch_id' => $batch->id]);

    Livewire::test(PrintPackSlips::class)
        ->assertSee('2 Shipments left off')
        ->assertSee("batch #{$batch->id}")
        ->assertSee(route('filament.app.resources.pick-batches.view', $batch), escape: false);
});

it('names the batches without links for a Shipper, who cannot open pick batches', function (): void {
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    $batch = PickBatch::factory()->create();
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id]);

    Livewire::test(PrintPackSlips::class)
        ->assertSee('1 Shipment left off')
        ->assertSee("batch #{$batch->id}")
        ->assertDontSee(route('filament.app.resources.pick-batches.view', $batch), escape: false);
});

it('shows that pack slips print from pick batches when picking is required', function (): void {
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    app(SettingsService::class)->set('require_picking_before_shipping', true, 'boolean');
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    Shipment::factory()->create();

    Livewire::test(PrintPackSlips::class)
        ->assertSee('Pack slips print from pick batches')
        ->assertSee('Go to Pick Batches')
        ->assertActionHidden('printNext');
});

it('reports the renderer being unavailable without dispatching a print', function (): void {
    $this->mock(GotenbergService::class)
        ->shouldReceive('pdfFromView')
        ->andThrow(new RuntimeException('PDF renderer unavailable. Is Gotenberg running?'));
    Shipment::factory()->create();

    Livewire::test(PrintPackSlips::class)
        ->callAction('printNext')
        ->assertNotDispatched('print-pack-slips')
        ->assertNotified('PDF renderer unavailable');
});

it('says so when there is nothing to print', function (): void {
    Livewire::test(PrintPackSlips::class)
        ->callAction('printNext')
        ->assertNotDispatched('print-pack-slips')
        ->assertNotified('No pack slips to print');
});

/**
 * Redeems every receipt a print dispatch carried, as QZ Tray's acknowledgment would.
 */
function acknowledgePrint(mixed $page, mixed $test): void
{
    $dispatch = collect($page->effects['dispatches'])->firstWhere('name', 'print-pack-slips');

    foreach ($dispatch['params']['jobs'] as $job) {
        $test->postJson(route('pack-slips.printed'), ['receipt' => $job['receipt']])->assertOk();
    }
}

it('puts a printed run at the top of the Printed tab, and a reprint records the new time and user', function (): void {
    fakeQueuePdfRenderer();
    $earlier = Shipment::factory()->create([
        'pack_slip_items_version' => 0,
        'pack_slip_printed_at' => now()->subDay(),
    ]);
    $run = Shipment::factory()->count(2)->create();

    acknowledgePrint(
        Livewire::test(PrintPackSlips::class)
            ->selectTableRecords($run)
            ->callAction(TestAction::make('printSelected')->table()->bulk()),
        $this,
    );

    Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->assertCanSeeTableRecords([...$run, $earlier], inOrder: true)
        ->assertTableColumnVisible('pack_slip_printed_at')
        ->assertTableColumnVisible('packSlipPrintedBy.name');

    $other = User::factory()->create(['role' => Role::User]);
    $this->actingAs($other);
    $this->travel(5)->minutes();

    $page = Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->selectTableRecords([$earlier])
        ->callAction(TestAction::make('reprintSelected')->table()->bulk())
        ->assertDispatched('print-pack-slips');

    expect(dispatchedShipmentIds($page, $other))->toBe([$earlier->id]);

    acknowledgePrint($page, $this);

    $earlier->refresh();
    expect($earlier->pack_slip_printed_by_user_id)->toBe($other->id)
        ->and($earlier->pack_slip_printed_at->timestamp)->toBe(now()->timestamp);

    Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->assertCanSeeTableRecords([$earlier, ...$run], inOrder: true);
});

it('moves a Shipment whose items change from Printed to Not printed, marked changed since printed', function (): void {
    $shipment = Shipment::factory()->create();
    $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);
    $shipment->refresh()->forceFill(['pack_slip_items_version' => $shipment->items_version])->save();
    $neverPrinted = Shipment::factory()->create();

    Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->assertCanSeeTableRecords([$shipment]);

    $item->update(['quantity' => 2]);

    Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->assertCanNotSeeTableRecords([$shipment]);

    Livewire::test(PrintPackSlips::class)
        ->assertCanSeeTableRecords([$shipment, $neverPrinted])
        ->assertTableColumnStateSet('pack_slip_state', PackSlipState::ChangedSincePrinted, $shipment)
        ->assertTableColumnStateNotSet('pack_slip_state', PackSlipState::ChangedSincePrinted, $neverPrinted);
});

it('offers Reprint, not Print next N, on the Printed tab', function (): void {
    Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->assertActionHidden('printNext')
        ->assertActionHidden('batchSize')
        ->assertActionVisible(TestAction::make('reprintSelected')->table()->bulk())
        ->assertActionHidden(TestAction::make('printSelected')->table()->bulk())
        ->assertSee('No pack slips printed');
});

it('filters the Printed tab like the Not printed tab', function (): void {
    $channel = Channel::factory()->create();
    $match = Shipment::factory()->create(['channel_id' => $channel->id, 'pack_slip_items_version' => 0]);
    $other = Shipment::factory()->create(['pack_slip_items_version' => 0]);

    Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->filterTable('channel', $channel->id)
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('explains printed Shipments left off the Printed tab for in-progress pick batches', function (): void {
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    $printed = Shipment::factory()->create(['pack_slip_items_version' => 0]);
    $batch = PickBatch::factory()->create();
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $printed->id]);
    PickBatchShipment::factory()->count(2)->create(['pick_batch_id' => $batch->id]);

    Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->assertCanNotSeeTableRecords([$printed])
        ->assertSee('1 Shipment left off')
        ->assertSee(route('filament.app.resources.pick-batches.view', $batch), escape: false);
});

it('shows the picking-required empty state on the Printed tab', function (): void {
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    app(SettingsService::class)->set('require_picking_before_shipping', true, 'boolean');
    Shipment::factory()->create(['pack_slip_items_version' => 0]);

    Livewire::test(PrintPackSlips::class)
        ->set('activeTab', 'printed')
        ->assertSee('Pack slips print from pick batches')
        ->assertActionHidden('printNext');
});
