<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\UserShipmentsReport;
use App\Filament\Support\LocalDateRange;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Livewire\Livewire;

/**
 * release-polish/32: the reports' from/until dates are the default Location's
 * calendar days, and `shipped_at` is stored in UTC.
 */
beforeEach(function (): void {
    Location::factory()->default()->create(['timezone' => 'America/Los_Angeles']);
    Location::clearDefaultCache();

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
});

afterEach(fn () => Location::clearDefaultCache());

it('turns local dates into UTC bounds, with the until day included whole', function (): void {
    expect(LocalDateRange::utcBounds('2026-09-01', '2026-09-30'))->toBe([
        'from' => '2026-09-01 07:00:00',
        'until' => '2026-10-01 07:00:00',
    ]);
});

it('follows daylight saving time across the range', function (): void {
    // PDT ends November 1, 2026; the next local midnight is eight hours behind UTC.
    expect(LocalDateRange::utcBounds('2026-10-31', '2026-11-01'))->toBe([
        'from' => '2026-10-31 07:00:00',
        'until' => '2026-11-02 08:00:00',
    ]);
});

it('treats an empty date as no bound', function (): void {
    expect(LocalDateRange::utcBounds(null, ''))->toBe(['from' => null, 'until' => null]);
});

/** A shipped Package bought at 18:00 on September 30 in Los Angeles, 01:00 October 1 in UTC. */
function packageShippedSeptember30Evening(array $attributes = []): Package
{
    return Package::factory()->shipped()->create(['shipped_at' => '2026-10-01 01:00:00', ...$attributes]);
}

it('counts a package in Packages by User on its local ship date', function (): void {
    packageShippedSeptember30Evening(['shipped_by_user_id' => auth()->id(), 'ship_date' => '2026-09-30']);

    Livewire::test(UserShipmentsReport::class)
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertDontSee(auth()->user()->name)
        ->filterTable('date_range', ['from' => '2026-09-30', 'until' => '2026-09-30'])
        ->assertSee(auth()->user()->name);
});
