<?php

use App\Enums\PickBatchStatus;
use App\Enums\PickingStatus;
use App\Enums\Role;
use App\Filament\Pages\GeneratePickBatch;
use App\Filament\Resources\PickBatches\Pages\ViewPickBatch;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('lets a Packer open Pick Batches and Generate Pick Batch', function (): void {
    $batch = PickBatch::factory()->create();

    $this->get(PickBatchResource::getUrl('index'))->assertOk();
    $this->get(PickBatchResource::getUrl('view', ['record' => $batch]))->assertOk();
    $this->get(GeneratePickBatch::getUrl())->assertOk();
});

it('still hides pick batches from everyone when picking is off', function (): void {
    app(SettingsService::class)->set('picking_enabled', false, 'boolean');

    $this->get(PickBatchResource::getUrl('index'))->assertForbidden();
    $this->get(GeneratePickBatch::getUrl())->assertForbidden();
});

it('lets a Packer generate a pick batch', function (): void {
    Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);

    Livewire::test(GeneratePickBatch::class)
        ->call('generate')
        ->assertHasNoErrors();

    expect(PickBatch::count())->toBe(1);
});

it('lets a Packer create a pick batch from selected Shipments', function (): void {
    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);

    Livewire::test(ListShipments::class)
        ->selectTableRecords([$shipment->id])
        ->callAction(TestAction::make('create-picking-batch')->table()->bulk());

    expect(PickBatch::count())->toBe(1);
});

it('lets a Packer open the picking summary and pack slips of a batch', function (): void {
    $batch = PickBatch::factory()->create();

    $this->get(route('pick-batches.summary', $batch))->assertOk();
    $this->get(route('pick-batches.pack-slips', $batch))->assertOk();
});

it('lets a Packer mark a batch picked', function (): void {
    $batch = PickBatch::factory()->create(['total_shipments' => 1]);
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id]);

    Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->callAction('complete');

    expect($batch->refresh()->status)->toBe(PickBatchStatus::Completed);
});

it('leaves cancelling a batch to Managers', function (): void {
    $batch = PickBatch::factory()->create();

    Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->assertActionHidden('cancel');

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->assertActionVisible('cancel')
        ->callAction('cancel');

    expect($batch->refresh()->status)->toBe(PickBatchStatus::Cancelled);
});
