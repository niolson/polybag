<?php

use App\Enums\Deliverability;
use App\Enums\LabelBatchItemStatus;
use App\Filament\Widgets\ExceptionsWidget;
use App\Models\LabelBatchItem;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows undeliverable shipments count', function () {
    Shipment::factory()->count(2)->create([
        'shipped' => false,
        'deliverability' => Deliverability::No,
    ]);
    Shipment::factory()->create([
        'shipped' => false,
        'deliverability' => Deliverability::Yes,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ExceptionsWidget::class)
        ->assertSee('Undeliverable Shipments')
        ->assertSee('2');
});

it('shows failed batch items count', function () {
    LabelBatchItem::factory()->count(3)->create([
        'status' => LabelBatchItemStatus::Failed,
        'created_at' => now(),
    ]);
    // Old failure - should not count
    LabelBatchItem::factory()->create([
        'status' => LabelBatchItemStatus::Failed,
        'created_at' => now()->subDays(10),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ExceptionsWidget::class)
        ->assertSee('Failed Batch Items')
        ->assertSee('3');
});

it('shows unmapped shipping references count', function () {
    Shipment::factory()->count(2)->create([
        'shipped' => false,
        'shipping_method_reference' => 'UNKNOWN_METHOD',
        'shipping_method_id' => null,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ExceptionsWidget::class)
        ->assertSee('Unmapped Shipping References');
});
