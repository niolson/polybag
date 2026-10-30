<?php

use App\Enums\Role;
use App\Filament\Resources\LabelBatchResource;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Models\LabelBatch;
use App\Models\User;
use Livewire\Livewire;

it('lets a manager who can start a batch open the batch it started', function (): void {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    // The Batch Ship bulk action is offered to a manager…
    Livewire::test(ListShipments::class)->assertTableBulkActionVisible('batch-ship');

    // …and on submit redirects to the batch it created, where the labels are printed.
    $batch = LabelBatch::factory()->create(['user_id' => $manager->id]);

    $this->get(LabelBatchResource::getUrl('view', ['record' => $batch]))->assertOk();
    $this->get("/batch-ship/{$batch->id}")->assertRedirect(LabelBatchResource::getUrl('view', ['record' => $batch]));
});

it('keeps batches from users below manager', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));

    Livewire::test(ListShipments::class)->assertTableBulkActionHidden('batch-ship');
    $this->get(LabelBatchResource::getUrl('view', ['record' => LabelBatch::factory()->create()]))->assertForbidden();
});
