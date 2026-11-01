<?php

use App\Enums\Deliverability;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Filament\Resources\ShipmentResource\RelationManagers\ShipmentItemsRelationManager;
use App\Jobs\GenerateLabelJob;
use App\Models\BoxSize;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('displays status column in shipment table', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $notShipped = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->set('activeStatusTab', 'all')
        ->assertCanSeeTableRecords([$shipped, $notShipped]);
});

it('shows every shipment on the All tab', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $open = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->set('activeStatusTab', 'all')
        ->assertCanSeeTableRecords([$open, $shipped]);
});

it('moves to the All tab when the status filter is used, so the two never stack', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $open = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Shipped->value)
        ->assertSet('activeStatusTab', 'all')
        ->assertCanSeeTableRecords([$shipped])
        ->assertCanNotSeeTableRecords([$open]);
});

it('clears the status filter when a status tab is chosen', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $open = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Open->value)
        ->set('activeStatusTab', ShipmentStatus::Shipped->value)
        ->assertSet('tableFilters.status.value', null)
        ->assertCanSeeTableRecords([$shipped])
        ->assertCanNotSeeTableRecords([$open]);
});

it('lets a status filter in the URL win over the status tab', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $open = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::withQueryParams(['filters' => ['status' => ['value' => ShipmentStatus::Shipped->value]]])
        ->test(ListShipments::class)
        ->assertCanSeeTableRecords([$shipped])
        ->assertCanNotSeeTableRecords([$open]);
});

it('filters shipments by status', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $notShipped = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Shipped->value)
        ->assertCanSeeTableRecords([$shipped])
        ->assertCanNotSeeTableRecords([$notShipped]);

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Open->value)
        ->assertCanSeeTableRecords([$notShipped])
        ->assertCanNotSeeTableRecords([$shipped]);
});

it('filters shipments by status and deliverability tab groups together', function (): void {
    $matchingShipment = Shipment::factory()->create([
        'status' => ShipmentStatus::Open,
        'deliverability' => Deliverability::Yes,
    ]);
    $wrongStatusShipment = Shipment::factory()->create([
        'status' => ShipmentStatus::Shipped,
        'deliverability' => Deliverability::Yes,
    ]);
    $wrongDeliverabilityShipment = Shipment::factory()->create([
        'status' => ShipmentStatus::Open,
        'deliverability' => Deliverability::No,
    ]);

    Livewire::test(ListShipments::class)
        ->set('activeStatusTab', ShipmentStatus::Open->value)
        ->set('activeDeliverabilityTab', Deliverability::Yes->value)
        ->assertCanSeeTableRecords([$matchingShipment])
        ->assertCanNotSeeTableRecords([$wrongStatusShipment, $wrongDeliverabilityShipment]);
});

it('shows and filters shipment locations in multi-location mode', function (): void {
    config()->set('features.multi_location_enabled', true);
    app(SettingsService::class)->clearCache();
    $east = Location::factory()->create(['name' => 'East']);
    $west = Location::factory()->create(['name' => 'West']);
    $eastShipment = Shipment::factory()->create(['location_id' => $east]);
    $westShipment = Shipment::factory()->create(['location_id' => $west]);

    Livewire::test(ListShipments::class)
        ->assertTableColumnVisible('location.name')
        ->filterTable('location', $east->id)
        ->assertCanSeeTableRecords([$eastShipment])
        ->assertCanNotSeeTableRecords([$westShipment]);
});

it('batch ship carries the workstation\'s report printer answer into the batch', function (): void {
    // The bulk action's hidden `has_report_printer` is filled from
    // `localStorage.reportPrinter` by the batch-ship-local-storage view, the
    // way `label_format` is, and reaches every GenerateLabelJob — so a batch
    // started on a workstation with a report printer is not refused as if it
    // had none (shopify-shipping-carrier/07 constraint 4).
    Bus::fake();
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    $boxSize = BoxSize::factory()->create();
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    Livewire::test(ListShipments::class)
        ->callTableBulkAction('batch-ship', [$shipment], data: [
            'box_size_id' => $boxSize->id,
            'label_format' => 'pdf',
            'label_dpi' => null,
            'has_report_printer' => true,
        ]);

    Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 1
        && $batch->jobs->every(fn (GenerateLabelJob $job): bool => $job->hasReportPrinter));
});

it('offers no bulk address validation, since every check is a billed API request', function (): void {
    Livewire::test(ListShipments::class)
        ->assertTableBulkActionDoesNotExist('validate-addresses');
});

it('offers a deliverability tab for every value, labelled as the enum labels it', function (Deliverability $deliverability): void {
    $matching = Shipment::factory()->create(['deliverability' => $deliverability]);
    $other = Shipment::factory()->create([
        'deliverability' => $deliverability === Deliverability::No ? Deliverability::Yes : Deliverability::No,
    ]);

    Livewire::test(ListShipments::class)
        ->assertSee($deliverability->getLabel())
        ->set('activeDeliverabilityTab', $deliverability->value)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
})->with(Deliverability::cases());

describe('the order\'s currency', function (): void {
    it('defaults a new Shipment to USD and saves an edited currency upper-cased', function (): void {
        $shipment = Shipment::factory()->create(['value' => 120.00]);

        expect($shipment->fresh()->currency)->toBe('USD');

        Livewire::test(EditShipment::class, ['record' => $shipment->id])
            ->assertFormSet(['currency' => 'USD'])
            ->fillForm(['currency' => 'cad'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($shipment->fresh()->currency)->toBe('CAD');
    });

    it('refuses a currency that is not a three-letter code', function (string $currency): void {
        $shipment = Shipment::factory()->create();

        Livewire::test(EditShipment::class, ['record' => $shipment->id])
            ->fillForm(['currency' => $currency])
            ->call('save')
            ->assertHasFormErrors(['currency']);
    })->with(['$', 'US', 'C4D']);

    it('fixes the currency once a Package has a Label', function (): void {
        $shipment = Shipment::factory()->create(['currency' => 'CAD']);
        Package::factory()->shipped()->for($shipment)->create();

        Livewire::test(EditShipment::class, ['record' => $shipment->id])
            ->assertFormFieldIsDisabled('currency')
            ->fillForm(['currency' => 'EUR'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($shipment->fresh()->currency)->toBe('CAD');
    });

    it('shows the value and its items\' values in the Shipment\'s currency', function (): void {
        $shipment = Shipment::factory()->create(['value' => 1234.50, 'currency' => 'EUR']);
        ShipmentItem::factory()->for($shipment)->create(['value' => 61.25]);

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertSee('€1,234.50');

        Livewire::test(ShipmentItemsRelationManager::class, ['ownerRecord' => $shipment, 'pageClass' => ViewShipment::class])
            ->assertSee('€61.25');
    });
});
