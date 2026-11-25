<?php

use App\Enums\PostageSource;
use App\Enums\Role;
use App\Enums\VoidReason;
use App\Filament\Pages\Reports\VoidedLabelsReport;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
});

/**
 * A voided Label on its own Package.
 *
 * @param  array<string, mixed>  $attributes
 */
function voidedLabel(array $attributes = []): PackageLabel
{
    return PackageLabel::factory()->voided()->create($attributes + [
        'package_id' => Package::factory()->create()->id,
        'carrier' => 'USPS',
        'cost' => 8.25,
        'postage_source' => PostageSource::CarrierAccount,
    ]);
}

function voidedLabelsPage(): VoidedLabelsReport
{
    $page = Livewire::test(VoidedLabelsReport::class)->instance();
    assert($page instanceof VoidedLabelsReport);

    return $page;
}

it('lists voided labels in range and never an active one', function (): void {
    $recent = voidedLabel();
    $old = voidedLabel(['voided_at' => now()->subDays(45)]);
    $active = PackageLabel::factory()->active()->create();

    Livewire::test(VoidedLabelsReport::class)
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old, $active]);
});

it('totals the count and postage of the labels shown', function (): void {
    voidedLabel(['cost' => 8.25]);
    voidedLabel(['cost' => 11.75]);

    Livewire::test(VoidedLabelsReport::class)
        ->assertSee('$20.00');
});

it('says who voided a label, how, and whether the package was bought again', function (): void {
    $packer = User::factory()->create(['name' => 'Jordan Packer']);
    $rebought = voidedLabel(['voided_by_user_id' => $packer->id, 'void_reason' => VoidReason::Operator]);
    PackageLabel::factory()->active()->create(['package_id' => $rebought->package_id]);
    voidedLabel(['void_reason' => VoidReason::VoidedUpstream]);

    $rows = voidedLabelsPage()->getFilteredSortedTableQuery()->get()->keyBy('id');

    expect((int) $rows[$rebought->id]->getAttribute('rebought'))->toBe(1)
        ->and((int) $rows->firstWhere('id', '!=', $rebought->id)->getAttribute('rebought'))->toBe(0);

    Livewire::test(VoidedLabelsReport::class)
        ->assertSee('Jordan Packer')
        ->assertSee('Voided in PolyBag')
        ->assertSee('Voided at the seller');
});

it('filters by carrier and by where the label was bought', function (): void {
    $direct = voidedLabel(['carrier' => 'USPS']);
    $shopify = DataSource::factory()->create(['name' => 'Our Shop']);
    $channel = voidedLabel(['carrier' => 'UPS', 'postage_source' => PostageSource::PostageDataSource, 'postage_data_source_id' => $shopify->id]);

    Livewire::test(VoidedLabelsReport::class)
        ->filterTable('carrier', 'UPS')
        ->assertCanSeeTableRecords([$channel])
        ->assertCanNotSeeTableRecords([$direct])
        ->resetTableFilters()
        ->filterTable('postage_source', VoidedLabelsReport::DIRECT_SOURCE)
        ->assertCanSeeTableRecords([$direct])
        ->assertCanNotSeeTableRecords([$channel])
        ->filterTable('postage_source', $shopify->id)
        ->assertCanSeeTableRecords([$channel])
        ->assertCanNotSeeTableRecords([$direct])
        ->assertSee('Our Shop');
});

it('exports the rows it shows', function (): void {
    $label = voidedLabel(['tracking_number' => '9400100000000000000001', 'void_reason' => VoidReason::Recorded]);

    ob_start();
    voidedLabelsPage()->exportCsv()->sendContent();
    $lines = array_map(str_getcsv(...), explode("\n", trim((string) ob_get_clean())));

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toBe(['Voided', 'Order', 'Tracking', 'Carrier', 'Service', 'Bought Through', 'Postage', 'Voided By', 'How', 'Package Shipped Now'])
        ->and($lines[1][2])->toBe('9400100000000000000001')
        ->and($lines[1][6])->toBe('8.25')
        ->and($lines[1][8])->toBe('Recorded by a manager')
        ->and($lines[1][9])->toBe('no')
        ->and($label->fresh()?->voided_at)->not->toBeNull();
});

it('restricts access to managers and above', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));

    expect(VoidedLabelsReport::canAccess())->toBeFalse();
});

it('counts the labels whose postage was never reported beside the total it leaves out', function (): void {
    voidedLabel(['cost' => 8.00]);
    voidedLabel(['cost' => 9.00]);
    voidedLabel(['cost' => null]);

    Livewire::test(VoidedLabelsReport::class)
        ->assertSee('$17.00')
        ->assertSee('Postage not reported');
});

it('narrows to one client in multi-client mode', function (): void {
    config()->set('features.multi_client_enabled', true);
    [$ours, $theirs] = Client::factory()->count(2)->create();
    $mine = voidedLabel(['package_id' => Package::factory()->create(['shipment_id' => Shipment::factory()->create(['client_id' => $ours->id])->id])->id]);
    $other = voidedLabel(['package_id' => Package::factory()->create(['shipment_id' => Shipment::factory()->create(['client_id' => $theirs->id])->id])->id]);

    Livewire::test(VoidedLabelsReport::class)
        ->filterTable('client', $ours->id)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('exports every row once when rows share a void time across chunks', function (): void {
    $at = now()->subDay()->startOfSecond();
    $labels = collect(range(1, 3))->map(fn (): PackageLabel => voidedLabel(['voided_at' => $at]));

    ob_start();
    voidedLabelsPage()->exportCsv(1)->sendContent();
    $lines = array_map(str_getcsv(...), explode("\n", trim((string) ob_get_clean())));

    expect(array_slice($lines, 1))->toHaveCount(3)
        ->and(collect(array_slice($lines, 1))->pluck(2)->sort()->values()->all())
        ->toBe($labels->pluck('tracking_number')->sort()->values()->all());
});

it('exports the carrier under its display name and the void time with its offset', function (): void {
    Carrier::query()->updateOrCreate(['name' => 'USPS'], ['display_name' => 'Postal Service']);
    voidedLabel();

    ob_start();
    voidedLabelsPage()->exportCsv()->sendContent();
    $row = array_map(str_getcsv(...), explode("\n", trim((string) ob_get_clean())))[1];

    expect($row[3])->toBe('Postal Service')
        ->and($row[0])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-]\d{2}:\d{2}$/');
});
