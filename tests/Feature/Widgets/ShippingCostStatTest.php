<?php

use App\Filament\Widgets\StatsOverview;
use App\Models\DailyShippingStat;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('shows shipping cost this week', function (): void {
    // Summary stats for this week with costs matching the expected total
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 2,
        'costed_package_count' => 2,
        'total_cost' => 25.75,
        'total_weight' => 0,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipping Cost This Week')
        ->assertSee('$25.75');
});

it('shows zero when no packages shipped this week', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$0.00');
});

it('discloses packages the weekly cost could not price', function (): void {
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 6,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$60.00')
        ->assertSee('excludes 4 with no reported cost');
});

it('leaves the weekly cost unqualified when every package was priced', function (): void {
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$60.00')
        ->assertDontSee('no reported cost');
});

it('withholds the week-over-week change when last week left postage unpriced', function (): void {
    // Last week's total is a subtotal, so the percentage against it would
    // overstate the rise — and would look no different from a measured one.
    DailyShippingStat::create([
        'date' => now(Location::timezone())->subWeek()->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 4,
        'total_cost' => 40.00,
        'total_weight' => 20.00,
    ]);

    DailyShippingStat::create([
        'date' => now(Location::timezone())->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 100.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$100.00')
        ->assertSee('no comparison — the same days last week exclude 6 with no reported cost')
        ->assertDontSee('vs same days last week');
});

it('names both weeks when neither priced everything', function (): void {
    DailyShippingStat::create([
        'date' => now(Location::timezone())->subWeek()->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 4,
        'total_cost' => 40.00,
        'total_weight' => 20.00,
    ]);

    DailyShippingStat::create([
        'date' => now(Location::timezone())->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 7,
        'total_cost' => 70.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('excludes 3 this week and 6 in the same days last week with no reported cost')
        ->assertDontSee('vs same days last week');
});

it('still compares the weeks when both priced everything', function (): void {
    DailyShippingStat::create([
        'date' => now(Location::timezone())->subWeek()->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 50.00,
        'total_weight' => 20.00,
    ]);

    DailyShippingStat::create([
        'date' => now(Location::timezone())->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 100.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('+100.0% vs same days last week')
        ->assertDontSee('no reported cost');
});

it('compares this week so far with the same days of last week', function (): void {
    $this->travelTo(now(Location::timezone())->startOfWeek()->addDays(2)->setTime(12, 0));
    $lastMonday = now(Location::timezone())->subWeek()->startOfWeek();

    // Monday to Wednesday of last week: $50. Friday of last week is not yet comparable.
    DailyShippingStat::create(['date' => $lastMonday->toDateString(), 'package_count' => 5, 'costed_package_count' => 5, 'total_cost' => 50.00, 'total_weight' => 0]);
    DailyShippingStat::create(['date' => $lastMonday->copy()->addDays(4)->toDateString(), 'package_count' => 5, 'costed_package_count' => 5, 'total_cost' => 500.00, 'total_weight' => 0]);
    DailyShippingStat::create(['date' => now(Location::timezone())->startOfWeek()->toDateString(), 'package_count' => 5, 'costed_package_count' => 5, 'total_cost' => 100.00, 'total_weight' => 0]);

    Livewire::actingAs(User::factory()->create())
        ->test(StatsOverview::class)
        ->assertSee('+100.0% vs same days last week');
});
