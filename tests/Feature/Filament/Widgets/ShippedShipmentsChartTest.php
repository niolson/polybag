<?php

use App\Filament\Widgets\ShippedShipmentsChart;
use App\Models\DailyShippingStat;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    $this->actingAs(User::factory()->create());
});

it('renders the chart widget', function (): void {
    Livewire::test(ShippedShipmentsChart::class)
        ->assertSee('Shipped Shipments')
        ->assertSuccessful();
});

it('defaults to week filter', function (): void {
    $component = Livewire::test(ShippedShipmentsChart::class);

    expect($component->get('filter'))->toBe('week');
});

it('can change to month filter', function (): void {
    Livewire::test(ShippedShipmentsChart::class)
        ->set('filter', 'month')
        ->assertSet('filter', 'month');
});

it('has correct filter options', function (): void {
    $widget = new ShippedShipmentsChart;

    $filters = invade($widget)->getFilters();

    expect($filters)->toBe([
        'week' => 'Last 7 days',
        'month' => 'Last 30 days',
    ]);
});

it('counts shipped shipments for the last week', function (): void {
    // Summary stats within the last week
    DailyShippingStat::create([
        'date' => now()->subDays(2)->toDateString(),
        'package_count' => 3,
        'total_cost' => 0,
        'total_weight' => 0,
    ]);

    // Summary stats before the last week (should not be counted)
    DailyShippingStat::create([
        'date' => now()->subDays(10)->toDateString(),
        'package_count' => 2,
        'total_cost' => 0,
        'total_weight' => 0,
    ]);

    $widget = new ShippedShipmentsChart;
    $widget->filter = 'week';

    $data = invade($widget)->getData();

    // Sum all data points in the chart
    $totalCount = array_sum($data['datasets'][0]['data']);

    expect($totalCount)->toBe(3);
});

it('counts shipped shipments for the last month', function (): void {
    // Summary stats within the last month
    DailyShippingStat::create([
        'date' => now()->subDays(15)->toDateString(),
        'package_count' => 5,
        'total_cost' => 0,
        'total_weight' => 0,
    ]);

    DailyShippingStat::create([
        'date' => now()->subDays(3)->toDateString(),
        'package_count' => 3,
        'total_cost' => 0,
        'total_weight' => 0,
    ]);

    // Summary stats before the last month (should not be counted)
    DailyShippingStat::create([
        'date' => now()->subDays(35)->toDateString(),
        'package_count' => 2,
        'total_cost' => 0,
        'total_weight' => 0,
    ]);

    $widget = new ShippedShipmentsChart;
    $widget->filter = 'month';

    $data = invade($widget)->getData();

    // Sum all data points in the chart
    $totalCount = array_sum($data['datasets'][0]['data']);

    expect($totalCount)->toBe(8);
});

it('generates correct number of labels for week view', function (): void {
    $widget = new ShippedShipmentsChart;
    $widget->filter = 'week';

    $data = invade($widget)->getData();

    expect($data['labels'])->toHaveCount(7);
});

it('generates correct number of labels for month view', function (): void {
    $widget = new ShippedShipmentsChart;
    $widget->filter = 'month';

    $data = invade($widget)->getData();

    expect($data['labels'])->toHaveCount(30);
});

it('returns bar chart type', function (): void {
    $widget = new ShippedShipmentsChart;

    $type = invade($widget)->getType();

    expect($type)->toBe('bar');
});
