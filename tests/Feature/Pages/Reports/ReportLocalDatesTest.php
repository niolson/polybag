<?php

use App\Enums\Deliverability;
use App\Enums\Role;
use App\Filament\Pages\Reports\PackingValidationReport;
use App\Filament\Pages\Reports\RateComparison;
use App\Filament\Pages\Reports\UserShipmentsReport;
use App\Filament\Support\LocalDateRange;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\RateQuote;
use App\Models\Shipment;
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

it('keeps the whole until day in Rate Comparison and dates the row locally', function (): void {
    $package = packageShippedSeptember30Evening();
    $quotedAt = now()->startOfSecond();
    $quoteIds = collect([['USPS', 8.50], ['FedEx', 12.00]])->map(fn (array $quote): int => RateQuote::factory()->create([
        'package_id' => $package->id, 'carrier' => $quote[0], 'quoted_price' => $quote[1], 'created_at' => $quotedAt,
    ])->id);
    PackageLabel::query()->where('package_id', $package->id)->whereNull('voided_at')->update(['rate_quote_id' => $quoteIds[0]]);

    Livewire::test(RateComparison::class)
        ->filterTable('date_range', ['from' => '2026-09-30', 'until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$package])
        ->assertSee('Sep 30, 2026')
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-01'])
        ->assertCanNotSeeTableRecords([$package]);
});

it('keeps the whole until day in Packing Validation and dates the row locally', function (): void {
    $package = packageShippedSeptember30Evening([
        'shipment_id' => Shipment::factory()->create(['deliverability' => Deliverability::No])->id,
    ]);

    Livewire::test(PackingValidationReport::class)
        ->set('section', 'validation_issues')
        ->filterTable('date_range', ['from' => '2026-09-30', 'until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$package])
        ->assertSee('Sep 30, 2026')
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-01'])
        ->assertCanNotSeeTableRecords([$package]);
});

it('counts a package in Shipments by User on the local day it shipped', function (): void {
    packageShippedSeptember30Evening(['shipped_by_user_id' => auth()->id()]);

    Livewire::test(UserShipmentsReport::class)
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertDontSee(auth()->user()->name)
        ->filterTable('date_range', ['from' => '2026-09-30', 'until' => '2026-09-30'])
        ->assertSee(auth()->user()->name);
});
