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
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
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
