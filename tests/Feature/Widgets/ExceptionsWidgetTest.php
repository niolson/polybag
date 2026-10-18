<?php

use App\Enums\Deliverability;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Enums\TrackingStatus;
use App\Filament\Pages\UnmappedShippingReferences;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\ShipmentResource;
use App\Filament\Widgets\CarrierBreakdownChart;
use App\Filament\Widgets\CostPerPackageTrend;
use App\Filament\Widgets\DatabaseHealthWidget;
use App\Filament\Widgets\ExceptionsWidget;
use App\Filament\Widgets\ShippedShipmentsChart;
use App\Filament\Widgets\StatsOverview;
use App\Listeners\InvalidateDashboardCache;
use App\Models\LabelBatchItem;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

use function Livewire\invade;

uses(RefreshDatabase::class);

it('shows undeliverable shipments count', function (): void {
    Shipment::factory()->count(2)->create([
        'status' => ShipmentStatus::Open,
        'deliverability' => Deliverability::No,
    ]);

    // Only positive evidence an address is wrong is an exception.
    foreach ([Deliverability::Yes, Deliverability::Verified, Deliverability::Partial, Deliverability::Unverified] as $deliverability) {
        Shipment::factory()->create([
            'status' => ShipmentStatus::Open,
            'deliverability' => $deliverability,
        ]);
    }

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(ExceptionsWidget::class)
        ->assertSee('Undeliverable Shipments')
        ->assertSee('2');

    expect(invade($component->instance())->queryCounts()['undeliverable'])->toBe(2);
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

it('links each count to a list filtered to what it counts', function (): void {
    Livewire::actingAs(User::factory()->manager()->create())
        ->test(ExceptionsWidget::class)
        ->assertSee(ShipmentResource::getUrl('index').'?status_tab='.ShipmentStatus::Open->value.'&deliverability_tab='.Deliverability::No->value)
        ->assertSee(PackageResource::getUrl('index').'?status_tab='.PackageStatus::Shipped->value.'&tracking_tab='.TrackingStatus::Exception->value)
        ->assertSee(PackageResource::getUrl('index').'?status_tab='.PackageStatus::Shipped->value.'&tracking_tab='.TrackingStatus::PreTransit->value);
});

it('is cleared by the dashboard cache invalidation, under the key it caches by', function (): void {
    Cache::flush();

    Livewire::actingAs(User::factory()->manager()->create())->test(ExceptionsWidget::class);

    expect(Cache::has('widget:exceptions:v4'))->toBeTrue();

    InvalidateDashboardCache::invalidateAll();

    expect(Cache::has('widget:exceptions:v4'))->toBeFalse();
});

it('counts unmapped references the way the Map Shipping Refs page lists them', function (): void {
    // Open or closed, any age, one row per (reference, client); an alias removes it.
    Shipment::factory()->count(2)->create(['status' => ShipmentStatus::Open, 'shipping_method_reference' => 'A', 'shipping_method_id' => null]);
    Shipment::factory()->create(['status' => ShipmentStatus::Shipped, 'shipping_method_reference' => 'B', 'shipping_method_id' => null, 'created_at' => now()->subDays(200)]);

    expect(UnmappedShippingReferences::unmappedCount())->toBe(2);

    Livewire::actingAs(User::factory()->manager()->create())
        ->test(ExceptionsWidget::class)
        ->assertSeeInOrder(['Unmapped Shipping References', '2']);
});

it('puts the exceptions between the stats and the charts', function (): void {
    $sorts = fn (string $class): int => (new ReflectionClass($class))->getStaticPropertyValue('sort');

    expect($sorts(StatsOverview::class))->toBeLessThan($sorts(ExceptionsWidget::class))
        ->and($sorts(ExceptionsWidget::class))->toBeLessThan($sorts(ShippedShipmentsChart::class))
        ->and($sorts(ShippedShipmentsChart::class))->toBeLessThan($sorts(CarrierBreakdownChart::class))
        ->and($sorts(CarrierBreakdownChart::class))->toBeLessThan($sorts(CostPerPackageTrend::class))
        ->and($sorts(CostPerPackageTrend::class))->toBeLessThan($sorts(DatabaseHealthWidget::class));
});
