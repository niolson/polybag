<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\VolumeReport;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Support\LocalDateRange;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\Client;
use App\Models\DailyShippingStat;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders volume report page', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertOk()
        ->assertSee('Volume');
});

it('defaults to channel grouping', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $component = Livewire::actingAs($user)
        ->test(VolumeReport::class);

    expect($component->get('groupBy'))->toBe('channel');
});

it('switches to shipping method grouping', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Package::factory()->shipped()->create(['shipped_at' => now()]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->set('groupBy', 'shipping_method')
        ->assertOk();
});

it('switches to period grouping', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Package::factory()->shipped()->create(['shipped_at' => now()]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->set('groupBy', 'period')
        ->assertOk();
});

it('restricts access to managers and above', function (): void {
    $user = User::factory()->create(['role' => Role::Packer]);
    $this->actingAs($user);

    expect(VolumeReport::canAccess())->toBeFalse();

    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);

    expect(VolumeReport::canAccess())->toBeTrue();
});

it('averages cost over the packages that reported one', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    // Six of ten packages priced, $60 between them. The average is $10 over
    // those six, not $6 over all ten.
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 6,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertOk()
        ->assertSee('$10.00')
        ->assertDontSee('$6.00');
});

it('discloses how many packages the total leaves out', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 6,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertSee('excludes 4 with no reported cost')
        ->assertSee('over 6 priced');
});

it('says nothing about exclusions when every package was priced', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertSee('$6.00')
        ->assertDontSee('no reported cost');
});

it('reports an unknown average when nothing in the group was priced', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 4,
        'costed_package_count' => 0,
        'total_cost' => 0.00,
        'total_weight' => 8.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertSee('Unknown')
        ->assertSee('excludes 4 with no reported cost');
});

it('offers one Group by choice with every grouping in it', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertSee('Group by')
        ->assertSeeInOrder(['Channel', 'Shipping Method', 'Day', 'Week', 'Month'])
        ->assertSee('Last 30 days');
});

it('labels weeks by their dates and marks the current one as in progress', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    DailyShippingStat::create(['date' => now()->toDateString(), 'package_count' => 4, 'costed_package_count' => 4, 'total_cost' => 40, 'total_weight' => 0]);

    $monday = now()->startOfWeek();
    $sunday = now()->endOfWeek();
    $label = $monday->month === $sunday->month
        ? $monday->format('M j').'–'.$sunday->format('j')
        : $monday->format('M j').' – '.$sunday->format('M j');

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->set('groupBy', 'week')
        ->assertSee($label)
        ->assertDontSee('-W')
        ->assertSee('In progress, not a full week');
});

/**
 * The mounted report page, typed, for calling its export directly.
 */
function volumeReportPage(?string $groupBy = null): VolumeReport
{
    $test = Livewire::test(VolumeReport::class);

    if ($groupBy !== null) {
        $test->set('groupBy', $groupBy);
    }

    $page = $test->instance();

    if (! $page instanceof VolumeReport) {
        throw new LogicException('Livewire mounted something other than the report.');
    }

    return $page;
}

it('groups by carrier under its display name', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    Carrier::query()->updateOrCreate(['name' => 'USPS'], ['display_name' => 'Postal Service']);
    DailyShippingStat::create(['date' => today()->toDateString(), 'carrier' => 'USPS', 'package_count' => 3, 'costed_package_count' => 3, 'total_cost' => 30, 'total_weight' => 6]);
    DailyShippingStat::create(['date' => today()->toDateString(), 'carrier' => 'FedEx', 'package_count' => 1, 'costed_package_count' => 1, 'total_cost' => 20, 'total_weight' => 1]);

    Livewire::test(VolumeReport::class)
        ->set('groupBy', 'carrier')
        ->assertSee('Postal Service')
        ->assertSee('FedEx')
        ->assertSeeInOrder(['Postal Service', '75.0%', '2.00 lb', 'FedEx', '25.0%', '1.00 lb']);
});

it('narrows to one location when several are in use', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    config()->set('features.multi_location_enabled', true);
    [$east, $west] = Location::factory()->count(2)->create();
    DailyShippingStat::create(['date' => today()->toDateString(), 'carrier' => 'USPS', 'location_id' => $east->id, 'package_count' => 7, 'costed_package_count' => 7, 'total_cost' => 70, 'total_weight' => 7]);
    DailyShippingStat::create(['date' => today()->toDateString(), 'carrier' => 'UPS', 'location_id' => $west->id, 'package_count' => 5, 'costed_package_count' => 5, 'total_cost' => 50, 'total_weight' => 5]);

    Livewire::test(VolumeReport::class)
        ->set('groupBy', 'carrier')
        ->filterTable('location', $east->id)
        ->assertSee('USPS')
        ->assertDontSee('UPS')
        ->assertSee('100.0%');
});

it('links a row to the Packages list showing exactly the packages it counts', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $channel = Channel::factory()->create();
    $shipment = fn (): int => Shipment::factory()->create(['channel_id' => $channel->id])->id;
    $counted = Package::factory()->shipped()->create(['shipment_id' => $shipment(), 'ship_date' => today()->subDays(3), 'shipped_at' => now()->subDays(3)]);
    $outOfRange = Package::factory()->shipped()->create(['shipment_id' => $shipment(), 'ship_date' => today()->subDays(40), 'shipped_at' => now()->subDays(40)]);
    $otherChannel = Package::factory()->shipped()->create(['ship_date' => today()->subDays(3), 'shipped_at' => now()->subDays(3)]);
    $this->artisan('stats:aggregate', ['--days' => 60]);

    $page = volumeReportPage();
    $record = $page->getFilteredSortedTableQuery()->get()->firstWhere('group_name', $channel->name);
    $url = (fn (): ?string => $this->buildDrillDownUrl($record))->call($page);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith(PackageResource::getUrl('index'))
        ->and($query['filters']['channel']['value'])->toBe((string) $channel->id)
        ->and($query['filters']['ship_date']['ship_date_from'])->toBe(LocalDateRange::now()->subDays(30)->format('Y-m-d'))
        ->and($query['filters']['status']['value'])->toBe('shipped');

    Livewire::withQueryParams(['filters' => $query['filters']])
        ->test(ListPackages::class)
        ->assertCanSeeTableRecords([$counted])
        ->assertCanNotSeeTableRecords([$outOfRange, $otherChannel]);
});

it('limits a week row link to the part of the week inside the range', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    DailyShippingStat::create(['date' => '2026-09-30', 'package_count' => 2, 'costed_package_count' => 2, 'total_cost' => 20, 'total_weight' => 2]);

    $test = Livewire::test(VolumeReport::class)
        ->set('groupBy', 'week')
        ->filterTable('date_range', ['from' => '2026-09-30', 'until' => '2026-10-31']);
    $page = $test->instance();
    assert($page instanceof VolumeReport);
    $record = $page->getFilteredSortedTableQuery()->get()->first();
    $url = (fn (): ?string => $this->buildDrillDownUrl($record))->call($page);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    // The week of Monday September 28, cut at the report's first day.
    expect($query['filters']['ship_date'])->toBe(['ship_date_from' => '2026-09-30', 'ship_date_until' => '2026-10-04']);
});

it('gives no link to a row with nothing to filter by', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    DailyShippingStat::create(['date' => today()->toDateString(), 'channel_id' => null, 'package_count' => 1, 'costed_package_count' => 1, 'total_cost' => 5, 'total_weight' => 1]);

    $page = volumeReportPage();
    $record = $page->getFilteredSortedTableQuery()->get()->first();

    expect((fn (): ?string => $this->buildDrillDownUrl($record))->call($page))->toBeNull();
});

it('exports the rows it shows', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    DailyShippingStat::create(['date' => today()->toDateString(), 'carrier' => 'USPS', 'package_count' => 4, 'costed_package_count' => 3, 'total_cost' => 30, 'total_weight' => 10]);

    $response = volumeReportPage('carrier')->exportCsv();
    ob_start();
    $response->sendContent();
    $lines = array_map(str_getcsv(...), array_filter(explode("\n", (string) ob_get_clean())));

    expect($lines[0])->toBe(['Carrier', 'Packages', 'Share', 'Total Cost', 'Unpriced Packages', 'Avg Cost', 'Avg Weight (lb)'])
        ->and($lines[1])->toBe(['USPS', '4', '100.0%', '30.00', '1', '10.00', '2.50']);
});

it('groups by catalog service after the carrier, and links each row to the packages it counts', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $carrier = Carrier::factory()->create(['name' => 'Acme Post', 'display_name' => 'Acme']);
    $ground = CarrierService::factory()->create(['carrier_id' => $carrier->id, 'name' => 'Economy']);
    $mapped = Package::factory()->shipped()->create(['carrier' => 'Acme Post']);
    $unmapped = Package::factory()->shipped()->create(['carrier' => 'Acme Post']);
    PackageLabel::query()->where('package_id', $mapped->id)->update(['carrier_service_id' => $ground->id]);
    PackageLabel::query()->where('package_id', $unmapped->id)->update(['carrier_service_id' => null]);
    DB::table('packages')->update(['ship_date' => LocalDateRange::now()->toDateString()]);
    $this->artisan('stats:aggregate', ['--today' => true]);

    $page = volumeReportPage('service');
    $rows = $page->getFilteredSortedTableQuery()->get();
    $label = fn (Model $row): string => (fn (): string => $this->groupLabel($row))->call($page);
    $linkFor = function (string $name) use ($rows, $page): array {
        $url = (fn (): ?string => $this->buildDrillDownUrl($rows->firstWhere('group_name', $name)))->call($page);
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return $query['filters'];
    };

    expect($rows->map($label)->sort()->values()->all())->toBe(['Acme Economy', 'Not a catalog service']);

    Livewire::withQueryParams(['filters' => $linkFor('Economy')])
        ->test(ListPackages::class)
        ->assertCanSeeTableRecords([$mapped])
        ->assertCanNotSeeTableRecords([$unmapped]);

    Livewire::withQueryParams(['filters' => $linkFor('Not a catalog service')])
        ->test(ListPackages::class)
        ->assertCanSeeTableRecords([$unmapped])
        ->assertCanNotSeeTableRecords([$mapped]);
});

it('offers the client grouping only in multi-client mode', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    expect(volumeReportPage()->groupings())->not->toHaveKey('client');

    config()->set('features.multi_client_enabled', true);

    expect(volumeReportPage()->groupings())->toHaveKey('client');
});

it('groups by client and narrows to one', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    config()->set('features.multi_client_enabled', true);
    [$ours, $theirs] = Client::factory()->count(2)->create();
    DailyShippingStat::create(['date' => today()->toDateString(), 'client_id' => $ours->id, 'package_count' => 3, 'costed_package_count' => 3, 'total_cost' => 30, 'total_weight' => 3]);
    DailyShippingStat::create(['date' => today()->toDateString(), 'client_id' => $theirs->id, 'package_count' => 1, 'costed_package_count' => 1, 'total_cost' => 10, 'total_weight' => 1]);

    $test = Livewire::test(VolumeReport::class)->set('groupBy', 'client');
    $test->assertSeeInOrder(['75.0%', '25.0%']);
    $page = $test->instance();
    assert($page instanceof VolumeReport);

    expect($page->getFilteredSortedTableQuery()->get()->pluck('group_name')->all())->toBe([$ours->name, $theirs->name]);

    $test->filterTable('client', $theirs->id);
    $test->assertSee('100.0%');
    $page = $test->instance();
    assert($page instanceof VolumeReport);

    expect($page->getFilteredSortedTableQuery()->get()->pluck('group_name')->all())->toBe([$theirs->name]);
});
