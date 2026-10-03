<?php

use App\Enums\PickBatchStatus;
use App\Enums\Role;
use App\Filament\Resources\PickBatches\Pages\ViewPickBatch;
use App\Filament\Resources\PickBatches\RelationManagers\PickBatchShipmentsRelationManager;
use App\Models\Client;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Services\GotenbergService;
use App\Services\PackSlips\PackSlipReceipts;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    Setting::create(['key' => 'picking_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('tells the shipments relation manager to refresh after marking all picked', function (): void {
    $batch = PickBatch::factory()->create(['total_shipments' => 1]);
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id]);

    $page = Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->callAction('complete')
        ->assertDispatched('pick-batch-updated');

    // The event must be broadcast globally, not scoped to the page component: Livewire
    // dispatches a bubbling DOM event that reaches `window`, which is the only way the
    // nested relation manager's listener hears it. A `self` or `component` key here
    // would narrow delivery and leave the shipments table stale.
    $dispatch = collect($page->effects['dispatches'] ?? [])
        ->firstWhere('name', 'pick-batch-updated');

    expect($dispatch)->not->toBeNull()
        ->and($dispatch)->not->toHaveKey('self')
        ->and($dispatch)->not->toHaveKey('component');

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Completed);
});

it('shows picked rows after the relation manager handles the refresh event', function (): void {
    $batch = PickBatch::factory()->create(['total_shipments' => 1]);
    $pivot = PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id]);

    $relationManager = Livewire::test(PickBatchShipmentsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewPickBatch::class,
    ]);

    $relationManager->assertTableColumnStateSet('picked_at', null, $pivot);

    Livewire::test(ViewPickBatch::class, ['record' => $batch->id])->callAction('complete');

    $relationManager
        ->dispatch('pick-batch-updated')
        ->assertTableColumnStateNotSet('picked_at', null, $pivot);
});

it('refreshes the batch on the page when the relation manager completes it', function (): void {
    $batch = PickBatch::factory()->create(['total_shipments' => 1]);
    $pivot = PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id]);

    Livewire::test(PickBatchShipmentsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewPickBatch::class,
    ])
        ->callAction(TestAction::make('markPicked')->table($pivot))
        ->assertDispatched('pick-batch-updated');

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Completed);
});

it('prints batch pack slips through the pack slip renderer, branded per Shipment', function (): void {
    $client = Client::factory()->create(['custom_message' => 'Thanks for shopping with Acme']);
    $batch = PickBatch::factory()->create(['total_shipments' => 1]);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $shipment->id, 'tote_code' => 'T01']);

    $rendered = null;
    $this->mock(GotenbergService::class)
        ->shouldReceive('pdfFromView')
        ->once()
        ->andReturnUsing(function (string $view, array $data) use (&$rendered): string {
            $rendered = view($view, $data)->render();

            return '%PDF-fake';
        });

    $page = Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->set('printMode', true)
        ->callAction('printPackSlips')
        ->assertDispatched('print-pack-slips')
        ->assertNotDispatched('print-report');

    // Pack slips are 4x6 label stock, so they go through the pack slip path to the
    // label printer, not to the document printer as a report.
    $jobs = collect($page->effects['dispatches'])->firstWhere('name', 'print-pack-slips')['params']['jobs'];

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['count'])->toBe(1)
        ->and(base64_decode($jobs[0]['data']))->toBe('%PDF-fake');

    // Print mode used to draw slips without the Client, losing its return address and footer.
    expect($rendered)->toContain('Thanks for shopping with Acme')
        ->toContain('T01');
});

/**
 * @return array{0: PickBatch, 1: Collection<int, Shipment>}
 */
function batchOfShipments(int $count): array
{
    $batch = PickBatch::factory()->create(['total_shipments' => $count]);
    $shipments = Shipment::factory()->count($count)->create();

    $shipments->each(fn (Shipment $shipment, int $index) => PickBatchShipment::factory()->create([
        'pick_batch_id' => $batch->id,
        'shipment_id' => $shipment->id,
        'tote_code' => sprintf('T%03d', $index + 1),
    ]));

    return [$batch, $shipments];
}

/**
 * @param  array{dispatches?: list<array{name: string, params: array<string, mixed>}>}  $effects
 * @return array<string, mixed>
 */
function dispatchedParams(array $effects, string $event): array
{
    return collect($effects['dispatches'] ?? [])->firstWhere('name', $event)['params'];
}

it('records batch pack slips on each Shipment only when the job\'s receipt is redeemed, with the user', function (): void {
    [$batch, $shipments] = batchOfShipments(2);
    $this->mock(GotenbergService::class)->shouldReceive('pdfFromView')->andReturn('%PDF-fake');

    $page = Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->set('printMode', true)
        ->callAction('printPackSlips')
        ->assertDispatched('print-pack-slips');

    expect(Shipment::query()->whereNotNull('pack_slip_printed_at')->count())->toBe(0);

    $receipt = dispatchedParams($page->effects, 'print-pack-slips')['jobs'][0]['receipt'];
    $this->postJson(route('pack-slips.printed'), ['receipt' => $receipt])->assertOk();

    $shipments->each(function (Shipment $shipment): void {
        $shipment->refresh();

        expect($shipment->pack_slip_printed_at)->not->toBeNull()
            ->and($shipment->pack_slip_printed_by_user_id)->toBe(auth()->id());
    });
});

it('prints the summary and then the slips with Print Both, recording only the slips', function (): void {
    [$batch, $shipments] = batchOfShipments(2);

    $views = [];
    $this->mock(GotenbergService::class)
        ->shouldReceive('pdfFromView')
        ->twice()
        ->andReturnUsing(function (string $view, array $data) use (&$views): string {
            $views[] = $view;
            $html = view($view, $data)->render();

            return $view === 'pick-batches.summary' ? '%PDF-summary' : '%PDF-slips '.$html;
        });

    $page = Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->set('printMode', true)
        ->callAction('printBoth')
        ->assertDispatched('print-pick-batch')
        ->assertNotDispatched('print-report')
        ->assertNotDispatched('print-pack-slips');

    $params = dispatchedParams($page->effects, 'print-pick-batch');
    $slips = base64_decode($params['jobs'][0]['data']);

    expect($views[0])->toBe('pick-batches.summary')
        ->and(base64_decode($params['summary']))->toBe('%PDF-summary')
        ->and($params['jobs'])->toHaveCount(1)
        ->and($params['jobs'][0]['count'])->toBe(2)
        ->and($slips)->toContain('T001')->toContain('T002')
        ->and($batch->fresh()->summary_printed_at)->not->toBeNull()
        ->and(Shipment::query()->whereNotNull('pack_slip_printed_at')->count())->toBe(0);

    $this->postJson(route('pack-slips.printed'), ['receipt' => $params['jobs'][0]['receipt']])->assertOk();

    expect(Shipment::query()->whereNotNull('pack_slip_printed_at')->count())->toBe(2);
});

it('sends a batch over the job limit through Print Both as several jobs, recording only those acknowledged', function (): void {
    [$batch, $shipments] = batchOfShipments(PackSlipRenderer::SLIPS_PER_PRINT_JOB + 1);
    $this->mock(GotenbergService::class)->shouldReceive('pdfFromView')->times(3)->andReturn('%PDF-fake');

    $page = Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->set('printMode', true)
        ->callAction('printBoth')
        ->assertDispatched('print-pick-batch');

    $params = dispatchedParams($page->effects, 'print-pick-batch');

    expect($params['summary'])->not->toBeEmpty()
        ->and(array_column($params['jobs'], 'count'))->toBe([PackSlipRenderer::SLIPS_PER_PRINT_JOB, 1]);

    // The second job failed in the browser, so only the first was acknowledged.
    $this->postJson(route('pack-slips.printed'), ['receipt' => $params['jobs'][0]['receipt']])->assertOk();

    expect(Shipment::query()->whereNotNull('pack_slip_printed_at')->count())->toBe(PackSlipRenderer::SLIPS_PER_PRINT_JOB)
        ->and($shipments->last()->fresh()->hasPrintedPackSlip())->toBeFalse();
});

it('prints the picking summary alone, without touching pack slip state', function (): void {
    [$batch] = batchOfShipments(1);
    $this->mock(GotenbergService::class)->shouldReceive('pdfFromView')->once()->andReturn('%PDF-summary');

    Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->set('printMode', true)
        ->callAction('printSummary')
        ->assertDispatched('print-report', data: base64_encode('%PDF-summary'))
        ->assertNotDispatched('print-pack-slips');

    expect($batch->fresh()->summary_printed_at)->not->toBeNull()
        ->and(Shipment::query()->whereNotNull('pack_slip_printed_at')->count())->toBe(0);
});

it('sends nothing from Print Both when the PDF renderer is unavailable', function (): void {
    [$batch] = batchOfShipments(1);
    $this->mock(GotenbergService::class)->shouldReceive('pdfFromView')->andThrow(new RuntimeException('Gotenberg is down'));

    Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->set('printMode', true)
        ->callAction('printBoth')
        ->assertNotDispatched('print-pick-batch')
        ->assertNotified('PDF renderer unavailable');

    expect($batch->fresh()->summary_printed_at)->toBeNull();
});

it('shows the Shipment\'s printed state in the batch Shipments table, including a print from its page', function (): void {
    [$batch, $shipments] = batchOfShipments(2);
    [$printed, $unprinted] = $shipments->all();
    $printedRow = PickBatchShipment::query()->where('shipment_id', $printed->id)->sole();
    $unprintedRow = PickBatchShipment::query()->where('shipment_id', $unprinted->id)->sole();

    $relationManager = Livewire::test(PickBatchShipmentsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewPickBatch::class,
    ])->assertTableColumnStateSet('shipment.pack_slip_printed_at', null, $printedRow);

    // Printed from the Shipment's own page, not from the batch.
    $receipts = app(PackSlipReceipts::class);
    $receipts->redeem($receipts->issue([$printed->id], auth()->user()));

    $relationManager
        ->dispatch('pack-slips-printed')
        ->assertTableColumnStateNotSet('shipment.pack_slip_printed_at', null, $printedRow)
        ->assertTableColumnStateSet('shipment.pack_slip_printed_at', null, $unprintedRow);
});

it('records Mark as printed on the batch\'s browser view against that view\'s receipt', function (): void {
    [$batch, $shipments] = batchOfShipments(2);

    $response = $this->get(route('pick-batches.pack-slips', $batch))
        ->assertOk()
        ->assertSee('Mark as printed');

    $receipt = app(PackSlipReceipts::class)->open($response->viewData('receipt'), auth()->user());

    expect(array_keys($receipt->itemsVersions))->toBe($shipments->modelKeys());

    $this->postJson(route('pack-slips.printed'), ['receipt' => $response->viewData('receipt')])->assertOk();

    expect(Shipment::query()->whereNotNull('pack_slip_printed_at')->count())->toBe(2);
});
