<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\PrepaidDutiesReport;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A DDP Label on a fresh Package.
 *
 * @param  array<string, mixed>  $label
 */
function ddpLabel(array $label = [], string $country = 'DE'): PackageLabel
{
    $package = Package::factory()->create([
        'shipment_id' => Shipment::factory()->create(['country' => $country]),
    ]);

    return PackageLabel::factory()->active()->create($label + [
        'package_id' => $package->id,
        'carrier' => 'USPS',
        'cost' => 79.61,
        'ship_date' => today(),
        'customs_terms' => ['duties_terms' => 'ddp', 'registration' => null],
        'duties_cost' => 286.08,
        'duties_package_fee' => 39.14,
        'duties_duty' => 49.49,
        'duties_tax' => 197.43,
    ]);
}

/**
 * The mounted report page, typed, for calling its export directly.
 */
function prepaidDutiesPage(): PrepaidDutiesReport
{
    $page = Livewire::test(PrepaidDutiesReport::class)->instance();

    if (! $page instanceof PrepaidDutiesReport) {
        throw new LogicException('Livewire mounted something other than the report.');
    }

    return $page;
}

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
});

it('lists a DDP label with the postage and the duties apart', function (): void {
    $label = ddpLabel();

    Livewire::test(PrepaidDutiesReport::class)
        ->assertCanSeeTableRecords([$label])
        ->assertOk()
        ->assertSee('286.08')
        ->assertSee('39.14')
        ->assertSee('49.49')
        ->assertSee('197.43');
});

it('lists a DDP label with no recorded duties, and leaves a DDU label out', function (): void {
    $ddpWithoutAmount = ddpLabel(['carrier' => 'UPS', 'duties_cost' => null, 'duties_package_fee' => null, 'duties_duty' => null, 'duties_tax' => null]);
    $ddu = ddpLabel(['customs_terms' => ['duties_terms' => 'ddu', 'registration' => null], 'duties_cost' => null]);

    Livewire::test(PrepaidDutiesReport::class)
        ->assertCanSeeTableRecords([$ddpWithoutAmount])
        ->assertCanNotSeeTableRecords([$ddu])
        ->assertSee('Not recorded');
});

it('hides voided labels until asked', function (): void {
    $active = ddpLabel();
    $voided = ddpLabel(['voided_at' => now()]);

    Livewire::test(PrepaidDutiesReport::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$voided])
        ->filterTable('label_status', 'all')
        ->assertCanSeeTableRecords([$active, $voided]);
});

it('limits the list to the ship dates asked for', function (): void {
    $inside = ddpLabel(['ship_date' => '2026-09-15']);
    $outside = ddpLabel(['ship_date' => '2026-08-31']);

    Livewire::test(PrepaidDutiesReport::class)
        ->filterTable('date_range', ['from' => '2026-09-01', 'until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('says when no registration was declared, and which one when there was', function (): void {
    ddpLabel(['tracking_number' => 'NONE0000000000000001']);
    ddpLabel(['tracking_number' => 'IOSS0000000000000002', 'customs_terms' => ['duties_terms' => 'ddp', 'registration' => ['regime' => 'ioss', 'number' => 'IM0400000000', 'source' => 'client']]]);

    Livewire::test(PrepaidDutiesReport::class)
        ->assertSee('None')
        ->assertSee('IOSS')
        ->assertDontSee('IM0400000000');
});

it('exports what the table shows', function (): void {
    ddpLabel(['tracking_number' => 'EXPORT00000000000001']);

    $response = prepaidDutiesPage()->exportCsv();

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->toContain('Duties Total')
        ->toContain('EXPORT00000000000001')
        ->toContain('286.08');
});

it('exports every row when the list spans several chunks, whatever order the ids are in', function (): void {
    // The oldest ship date gets the highest id, so a chunk paged by id alone drops it.
    foreach ([5, 4, 3, 2, 1] as $daysAgo) {
        ddpLabel(['tracking_number' => "CHUNK0000000000000{$daysAgo}", 'ship_date' => today()->subDays(6 - $daysAgo)]);
    }

    $response = prepaidDutiesPage()->exportCsv(chunkSize: 2);

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    foreach ([1, 2, 3, 4, 5] as $n) {
        expect($content)->toContain("CHUNK0000000000000{$n}");
    }
});

it('searches by tracking number and by order reference', function (): void {
    $match = ddpLabel(['tracking_number' => 'FINDME0000000000001']);
    $other = ddpLabel(['tracking_number' => 'OTHER0000000000002']);

    Livewire::test(PrepaidDutiesReport::class)
        ->searchTable('FINDME')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable($other->package->shipment->shipment_reference)
        ->assertCanSeeTableRecords([$other]);
});

it('sorts by ship date', function (): void {
    $older = ddpLabel(['ship_date' => '2026-09-01']);
    $newer = ddpLabel(['ship_date' => '2026-09-20']);

    Livewire::test(PrepaidDutiesReport::class)
        ->filterTable('date_range', ['from' => '2026-09-01', 'until' => null])
        ->sortTable('ship_date', 'asc')
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
});

it('restricts access to managers and above', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    expect(PrepaidDutiesReport::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    expect(PrepaidDutiesReport::canAccess())->toBeTrue();
});
