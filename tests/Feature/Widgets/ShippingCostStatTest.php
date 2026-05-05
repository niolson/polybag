<?php

use App\Filament\Widgets\StatsOverview;
use App\Models\DailyShippingStat;
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
