<?php

use App\Enums\PostageSource;
use App\Enums\Role;
use App\Enums\VoidReason;
use App\Filament\Pages\Reports\VoidedLabelsReport;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageLabel;
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
        ->and($lines[0])->toBe(['Voided', 'Order', 'Tracking', 'Carrier', 'Service', 'Bought Through', 'Postage', 'Voided By', 'How', 'Bought Again'])
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
