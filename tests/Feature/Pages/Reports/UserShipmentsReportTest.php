<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\UserShipmentsReport;
use App\Models\Client;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
});

/**
 * The mounted report page, typed, for reading its rows and export directly.
 */
function userReportPage(string $groupBy = 'user'): UserShipmentsReport
{
    $page = Livewire::test(UserShipmentsReport::class)->set('groupBy', $groupBy)->instance();

    if (! $page instanceof UserShipmentsReport) {
        throw new LogicException('Livewire mounted something other than the report.');
    }

    return $page;
}

/**
 * @return list<array<string, mixed>>
 */
function userReportRows(string $groupBy = 'user'): array
{
    return userReportPage($groupBy)->getFilteredSortedTableQuery()->get()
        ->map(fn (Model $row): array => $row->only(['user_name', 'period', 'package_count', 'order_count', 'item_count', 'active_days', 'per_active_day', 'voided_count']))
        ->all();
}

it('keeps two users who share a name apart', function (): void {
    [$first, $second] = User::factory()->count(2)->create(['name' => 'Sam Lee']);
    Package::factory()->shipped()->count(2)->create(['shipped_by_user_id' => $first->id]);
    Package::factory()->shipped()->create(['shipped_by_user_id' => $second->id]);

    expect(collect(userReportRows())->pluck('package_count')->map(fn ($count): int => (int) $count)->all())->toBe([2, 1]);
});

it('counts orders, items, active days and labels voided per user', function (): void {
    $packer = User::factory()->create(['name' => 'Packer']);
    $shipment = Shipment::factory()->create();
    $today = Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'shipped_by_user_id' => $packer->id, 'ship_date' => today()]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'shipped_by_user_id' => $packer->id, 'ship_date' => today()]);
    Package::factory()->shipped()->create(['shipped_by_user_id' => $packer->id, 'ship_date' => today()->subDays(2), 'shipped_at' => now()->subDays(2)]);
    PackageItem::factory()->create(['package_id' => $today->id, 'quantity' => 3]);
    PackageItem::factory()->create(['package_id' => $today->id, 'quantity' => 2]);
    PackageLabel::factory()->voided()->create(['voided_by_user_id' => $packer->id]);
    PackageLabel::factory()->voided()->create(['voided_by_user_id' => $packer->id, 'voided_at' => now()->subDays(60)]);

    $row = collect(userReportRows())->firstWhere('user_name', 'Packer');

    expect((int) $row['package_count'])->toBe(3)
        ->and((int) $row['order_count'])->toBe(2)
        ->and((int) $row['item_count'])->toBe(5)
        ->and((int) $row['active_days'])->toBe(2)
        ->and((float) $row['per_active_day'])->toBe(1.5)
        // The void sixty days ago is outside the default thirty-day range.
        ->and((int) $row['voided_count'])->toBe(1);
});

it('splits a user by week, naming each week by its dates', function (): void {
    $packer = User::factory()->create(['name' => 'Packer']);
    Package::factory()->shipped()->create(['shipped_by_user_id' => $packer->id, 'ship_date' => '2026-10-13', 'shipped_at' => '2026-10-13 18:00:00']);
    Package::factory()->shipped()->create(['shipped_by_user_id' => $packer->id, 'ship_date' => '2026-10-15', 'shipped_at' => '2026-10-15 18:00:00']);
    Package::factory()->shipped()->create(['shipped_by_user_id' => $packer->id, 'ship_date' => '2026-10-20', 'shipped_at' => '2026-10-20 18:00:00']);

    $page = Livewire::test(UserShipmentsReport::class)
        ->set('groupBy', 'week')
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->instance();
    assert($page instanceof UserShipmentsReport);
    $rows = $page->getFilteredSortedTableQuery()->get();
    $label = fn (string $period): string => (fn (): string => $this->periodLabel($period))->call($page);

    expect($rows->map(fn (Model $row): array => [$label((string) $row->getAttribute('period')), (int) $row->getAttribute('package_count')])->all())
        ->toBe([['Oct 19–25', 1], ['Oct 12–18', 2]]);
});

it('scopes every column, voids included, to the chosen client', function (): void {
    config()->set('features.multi_client_enabled', true);
    $packer = User::factory()->create(['name' => 'Packer']);
    [$ours, $theirs] = Client::factory()->count(2)->create();
    Package::factory()->shipped()->create(['shipped_by_user_id' => $packer->id, 'shipment_id' => Shipment::factory()->create(['client_id' => $ours->id])->id]);
    Package::factory()->shipped()->create(['shipped_by_user_id' => $packer->id, 'shipment_id' => Shipment::factory()->create(['client_id' => $theirs->id])->id]);
    PackageLabel::factory()->voided()->create([
        'voided_by_user_id' => $packer->id,
        'package_id' => Package::factory()->create(['shipment_id' => Shipment::factory()->create(['client_id' => $theirs->id])->id])->id,
    ]);

    $page = Livewire::test(UserShipmentsReport::class)
        ->filterTable('client', ['client_id' => $ours->id])
        ->instance();
    assert($page instanceof UserShipmentsReport);
    $row = $page->getFilteredSortedTableQuery()->get()->first();

    expect((int) $row?->getAttribute('package_count'))->toBe(1)
        ->and((int) $row?->getAttribute('voided_count'))->toBe(0);
});

it('exports the rows it shows', function (): void {
    $packer = User::factory()->create(['name' => 'Packer']);
    Package::factory()->shipped()->create(['shipped_by_user_id' => $packer->id]);

    $response = userReportPage()->exportCsv();
    ob_start();
    $response->sendContent();
    $lines = array_map(str_getcsv(...), array_filter(explode("\n", (string) ob_get_clean())));

    expect($lines[0])->toBe(['User', 'Packages', 'Orders', 'Items', 'Days Active', 'Packages / Active Day', 'Labels Voided'])
        ->and($lines[1])->toBe(['Packer', '1', '1', '0', '1', '1.0', '0']);
});

it('lists a user who only voided labels in range', function (): void {
    $supervisor = User::factory()->create(['name' => 'Supervisor']);
    PackageLabel::factory()->voided()->count(2)->create(['voided_by_user_id' => $supervisor->id]);

    $row = collect(userReportRows())->firstWhere('user_name', 'Supervisor');

    expect((int) $row['package_count'])->toBe(0)
        ->and((int) $row['voided_count'])->toBe(2)
        ->and($row['per_active_day'])->toBeNull();
});

it('counts a package on its ship date, the day the label is dated for', function (): void {
    $packer = User::factory()->create(['name' => 'Packer']);
    // Bought on the evening of October 31 after the cutoff, dated for Monday November 2.
    Package::factory()->shipped()->create(['shipped_by_user_id' => $packer->id, 'shipped_at' => '2026-11-01 01:00:00', 'ship_date' => '2026-11-02']);

    $page = Livewire::test(UserShipmentsReport::class)
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->instance();
    assert($page instanceof UserShipmentsReport);

    expect($page->getFilteredSortedTableQuery()->get())->toHaveCount(0);
});
