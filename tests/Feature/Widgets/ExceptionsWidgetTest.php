<?php

use App\Enums\Deliverability;
use App\Enums\LabelBatchItemStatus;
use App\Enums\ShipmentStatus;
use App\Filament\Widgets\ExceptionsWidget;
use App\Models\LabelBatchItem;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows undeliverable shipments count', function (): void {
    Shipment::factory()->count(2)->create([
        'status' => ShipmentStatus::Open,
        'deliverability' => Deliverability::No,
    ]);
    Shipment::factory()->create([
        'status' => ShipmentStatus::Open,
        'deliverability' => Deliverability::Yes,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ExceptionsWidget::class)
        ->assertSee('Undeliverable Shipments')
        ->assertSee('2');
});

it('shows failed batch items count', function (): void {
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

it('shows unmapped shipping references count', function (): void {
    Shipment::factory()->count(2)->create([
        'status' => ShipmentStatus::Open,
        'shipping_method_reference' => 'UNKNOWN_METHOD',
        'shipping_method_id' => null,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ExceptionsWidget::class)
        ->assertSee('Unmapped Shipping References');
});

it('renders when an entry cached before the shipping method count still exists', function (): void {
    // The shape cached under the old key, before `needs_shipping_method`.
    Cache::put('widget:exceptions', [
        'undeliverable' => 0,
        'failed_batch_items' => 0,
        'unmapped_references' => 0,
        'tracking_exceptions' => 0,
        'stuck_pre_transit' => 0,
    ], 300);
    Shipment::factory()->withoutShippingMethod()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(ExceptionsWidget::class)
        ->assertSee('Needs Shipping Method');
});
