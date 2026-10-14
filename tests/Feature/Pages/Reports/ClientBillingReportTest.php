<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\ClientBillingReport;
use App\Models\Client;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** A date inside the report's default filter window, which is last month. */
function lastMonth(): Carbon
{
    return now()->subMonthNoOverflow()->startOfMonth()->addDay()->setTime(12, 0);
}

function billingRow(ClientBillingReport $page, int $clientId): ?object
{
    $method = new ReflectionMethod($page, 'summaryQuery');

    return $method->invoke($page)->where('clients.id', $clientId)->first();
}

beforeEach(function (): void {
    // SQLite lacks GREATEST(); register it so the billing queries work in tests.
    DB::connection()->getPdo()->sqliteCreateFunction(
        'GREATEST',
        fn (): mixed => max(func_get_args()),
        -1
    );

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('renders the client billing report page', function (): void {
    Livewire::test(ClientBillingReport::class)
        ->assertOk()
        ->assertSee('Client Billing');
});

it('restricts access when multi_client_enabled is false', function (): void {
    Setting::where('key', 'multi_client_enabled')->update(['value' => '0']);
    app(SettingsService::class)->clearCache();

    expect(ClientBillingReport::canAccess())->toBeFalse();
});

it('restricts access for non-manager roles', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    expect(ClientBillingReport::canAccess())->toBeFalse();
});

it('calculates label fee per package', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '2.00',
        'pick_fee_first_item' => '0.00',
        'pick_fee_additional_item' => '0.00',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    expect((float) $row->label_fees)->toBe(4.00)  // 2 packages × $2.00
        ->and((int) $row->package_count)->toBe(2);
});

it('calculates first-item pick fee for a single-item shipment', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '0.00',
        'pick_fee_first_item' => '3.00',
        'pick_fee_additional_item' => '1.00',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '5.00']);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    // First item $3.00, no additional items
    expect((float) $row->pick_fees)->toBe(3.00);
});

it('calculates additional item pick fees for multi-item shipments', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '0.00',
        'pick_fee_first_item' => '3.00',
        'pick_fee_additional_item' => '1.00',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 3]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '5.00']);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    // First item $3.00 + (3 - 1) × $1.00 = $5.00
    expect((float) $row->pick_fees)->toBe(5.00);
});

it('sums all fee types into total_billable', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '2.00',
        'pick_fee_additional_item' => '0.50',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 3]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    // postage $10 + label $1 + pick_base $2 + pick_extra (2 × $0.50) $1 = $14
    expect((float) $row->total_billable)->toBe(14.00);
});

it('only includes shipped packages in billing totals', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '0.00',
        'pick_fee_additional_item' => '0.00',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);
    Package::factory()->create(['shipment_id' => $shipment->id]); // unshipped, should not count

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    expect((int) $row->package_count)->toBe(1)
        ->and((float) $row->label_fees)->toBe(1.00);
});

it('excludes inactive clients from the summary', function (): void {
    $client = Client::factory()->create(['is_default' => false, 'active' => false]);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    expect($row)->toBeNull();
});

it('resets clientId to null when set to a nonexistent client id', function (): void {
    Livewire::test(ClientBillingReport::class)
        ->set('clientId', 999999)
        ->assertSet('clientId', null);
});

it('resets clientId to null when set to an inactive client id', function (): void {
    $inactive = Client::factory()->create(['is_default' => false, 'active' => false]);

    Livewire::test(ClientBillingReport::class)
        ->set('clientId', $inactive->id)
        ->assertSet('clientId', null);
});

it('exports summary CSV with correct headers', function (): void {
    $client = Client::factory()->create(['is_default' => false]);

    $response = Livewire::test(ClientBillingReport::class)
        ->set('viewMode', 'summary')
        ->call('exportCsv')
        ->instance()
        ->exportCsv();

    $content = '';
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    $firstLine = explode("\n", trim($content))[0];
    expect($firstLine)->toContain('Client')
        ->toContain('Orders')
        ->toContain('Total Billable');
});

it('exports detail CSV with correct headers', function (): void {
    $client = Client::factory()->create(['is_default' => false]);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id]);

    $response = Livewire::test(ClientBillingReport::class)
        ->set('viewMode', 'detail')
        ->set('clientId', $client->id)
        ->instance()
        ->exportCsv();

    $content = '';
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    $firstLine = explode("\n", trim($content))[0];
    expect($firstLine)->toContain('Reference')
        ->toContain('Line Total');
});

it('does not include another clients shipments in billing row', function (): void {
    $clientA = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '0.00',
        'pick_fee_additional_item' => '0.00',
    ]);
    $clientB = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '0.00',
        'pick_fee_additional_item' => '0.00',
    ]);

    $shipmentA = Shipment::factory()->create(['client_id' => $clientA->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipmentA->id]);

    $shipmentB = Shipment::factory()->create(['client_id' => $clientB->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipmentB->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipmentB->id]);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $rowA = billingRow($page, $clientA->id);
    $rowB = billingRow($page, $clientB->id);

    expect((int) $rowA->package_count)->toBe(1)
        ->and((int) $rowB->package_count)->toBe(2);
});

it('counts packages that reported no postage on the summary row', function (): void {
    $client = Client::factory()->create(['is_default' => false]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => null]);

    $page = Livewire::test(ClientBillingReport::class)->instance();

    $row = billingRow($page, $client->id);

    expect((int) $row->uncosted_package_count)->toBe(1)
        ->and((float) $row->total_postage)->toBe(10.00);
});

it('reports no gap when every package priced', function (): void {
    $client = Client::factory()->create(['is_default' => false]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);

    $page = Livewire::test(ClientBillingReport::class)->instance();

    expect((int) billingRow($page, $client->id)->uncosted_package_count)->toBe(0);
});

it('flags a detail line whose postage is unpriced', function (): void {
    $client = Client::factory()->create(['is_default' => false]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'cost' => null,
        'shipped_at' => lastMonth(),
    ]);

    Livewire::test(ClientBillingReport::class)
        ->set('viewMode', 'detail')
        ->set('clientId', $client->id)
        ->assertOk()
        ->assertSee('Unpriced postage')
        ->assertSee('no reported postage');
});

it('does not flag a detail line whose postage is known', function (): void {
    $client = Client::factory()->create(['is_default' => false]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'cost' => '10.00',
        'shipped_at' => lastMonth(),
    ]);

    Livewire::test(ClientBillingReport::class)
        ->set('viewMode', 'detail')
        ->set('clientId', $client->id)
        ->assertOk()
        // Not asserting on the badge text: the filter label contains it too.
        ->assertDontSee('no reported postage');
});

it('filters the detail view down to lines with unpriced postage', function (): void {
    $client = Client::factory()->create(['is_default' => false]);

    $priced = Shipment::factory()->create(['client_id' => $client->id, 'shipment_reference' => 'ZZPRICED-1']);
    Package::factory()->shipped()->create([
        'shipment_id' => $priced->id,
        'cost' => '10.00',
        'shipped_at' => lastMonth(),
    ]);

    $unpriced = Shipment::factory()->create(['client_id' => $client->id, 'shipment_reference' => 'UNPRICED-1']);
    Package::factory()->shipped()->create([
        'shipment_id' => $unpriced->id,
        'cost' => null,
        'shipped_at' => lastMonth(),
    ]);

    Livewire::test(ClientBillingReport::class)
        ->set('viewMode', 'detail')
        ->set('clientId', $client->id)
        ->assertSee('ZZPRICED-1')
        ->set('tableFilters.unpriced_postage.isActive', true)
        ->assertSee('UNPRICED-1')
        ->assertDontSee('ZZPRICED-1');
});

it('exports the unpriced package count in both CSVs', function (): void {
    $client = Client::factory()->create(['is_default' => false]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'cost' => null,
        'shipped_at' => lastMonth(),
    ]);

    ob_start();
    Livewire::test(ClientBillingReport::class)
        ->instance()
        ->exportCsv()
        ->sendContent();
    $summary = ob_get_clean();

    expect(explode("\n", trim($summary))[0])->toContain('Unpriced Packages');

    ob_start();
    Livewire::test(ClientBillingReport::class)
        ->set('viewMode', 'detail')
        ->set('clientId', $client->id)
        ->instance()
        ->exportCsv()
        ->sendContent();
    $detail = ob_get_clean();

    $lines = explode("\n", trim($detail));

    expect($lines[0])->toContain('Unpriced Packages')
        ->and($lines[1])->toContain(',1,');
});

it('defaults to last month on the 31st', function (): void {
    $this->travelTo(Carbon::parse('2026-10-31 12:00:00'));

    expect(ClientBillingReport::defaultFrom())->toBe('2026-09-01')
        ->and(ClientBillingReport::defaultUntil())->toBe('2026-09-30');
});

it('exports the same period the table shows when a date is cleared', function (): void {
    $client = Client::factory()->create(['label_fee_per_package' => 0.50, 'is_default' => false]);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'shipped_at' => now()->subMonths(4)]);

    $page = Livewire::test(ClientBillingReport::class)
        ->set('viewMode', 'summary')
        ->filterTable('date_range', ['from' => null, 'until' => null]);

    $report = $page->instance();
    expect($report)->toBeInstanceOf(ClientBillingReport::class);

    ob_start();
    if ($report instanceof ClientBillingReport) {
        $report->exportCsv()->sendContent();
    }
    $content = ob_get_clean();

    expect($content)->toContain($client->name);
});

/**
 * A client whose one shipment shipped package 1 on September 30 and package 2
 * on October 2, with fees on every line so each can be told apart.
 */
function shipmentSplitAcrossSeptemberAndOctober(): Client
{
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '3.00',
        'pick_fee_additional_item' => '0.50',
    ]);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 3]);

    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '8.00', 'shipped_at' => '2026-09-30 15:00:00']);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '9.00', 'shipped_at' => '2026-10-02 15:00:00']);

    return $client;
}

function billingFor(Client $client, string $from, string $until): ?object
{
    $records = Livewire::test(ClientBillingReport::class)
        ->filterTable('date_range', ['from' => $from, 'until' => $until])
        ->instance()
        ->getTableRecords();

    return $records->firstWhere('id', $client->id);
}

it('bills a package in the period it shipped, even when its shipment began in an earlier one', function (): void {
    $client = shipmentSplitAcrossSeptemberAndOctober();

    $row = billingFor($client, '2026-10-01', '2026-10-31');

    // release-polish/28: the whole shipment was dated by its first package, so
    // October left it out and package 2 was never billed. The pick fees fell
    // in September, with the first package.
    expect((int) $row->package_count)->toBe(1)
        ->and((float) $row->total_postage)->toBe(9.00)
        ->and((float) $row->label_fees)->toBe(1.00)
        ->and((float) $row->pick_fees)->toBe(0.00)
        ->and((int) $row->order_count)->toBe(0)
        ->and((float) $row->total_billable)->toBe(10.00);
});

it('charges the shipment fees once, in the period of its first package, and does not change it later', function (): void {
    $client = shipmentSplitAcrossSeptemberAndOctober();

    $row = billingFor($client, '2026-09-01', '2026-09-30');

    // $3.00 first item + 2 more at $0.50, and only package 1.
    expect((int) $row->package_count)->toBe(1)
        ->and((float) $row->total_postage)->toBe(8.00)
        ->and((float) $row->pick_fees)->toBe(4.00)
        ->and((int) $row->order_count)->toBe(1)
        ->and((float) $row->total_billable)->toBe(13.00);
});

it('bills a shipment wholly once over a period covering both packages', function (): void {
    $client = shipmentSplitAcrossSeptemberAndOctober();

    $row = billingFor($client, '2026-09-01', '2026-10-31');

    expect((int) $row->package_count)->toBe(2)
        ->and((float) $row->pick_fees)->toBe(4.00)
        ->and((float) $row->total_billable)->toBe(23.00);
});

it('exports the later package on its own detail line, without the shipment fees', function (): void {
    $client = shipmentSplitAcrossSeptemberAndOctober();

    $report = Livewire::test(ClientBillingReport::class)
        ->set('viewMode', 'detail')
        ->set('clientId', $client->id)
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->instance();

    ob_start();
    $report->exportCsv()->sendContent();
    $lines = explode("\n", trim(ob_get_clean()));

    expect($lines)->toHaveCount(2)
        ->and(str_getcsv($lines[1]))->toBe([
            Shipment::where('client_id', $client->id)->sole()->shipment_reference,
            '2026-10-02 15:00:00', '1', '9.00', '0', '1.00', '0.00', '0.00', '0.00', '0.00', '', '10.00',
        ]);
});

it('treats a date cleared to an empty string as no bound, in the table and the export', function (): void {
    $client = shipmentSplitAcrossSeptemberAndOctober();

    $page = Livewire::test(ClientBillingReport::class)
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '']);
    $row = $page->instance()->getTableRecords()->firstWhere('id', $client->id);

    expect((int) $row->package_count)->toBe(1)
        ->and((float) $row->total_postage)->toBe(9.00);

    ob_start();
    $page->instance()->exportCsv()->sendContent();
    $content = ob_get_clean();

    expect($content)->toContain($client->name)
        ->and($content)->toContain('9.00');
});
