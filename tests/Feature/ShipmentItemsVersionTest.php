<?php

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\Role;
use App\Filament\Pages\Pack;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Filament\Resources\ShipmentResource\RelationManagers\ShipmentItemsRelationManager;
use App\Models\DataSource;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\PackSlips\PackSlipReceipts;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\ShipmentImport\ImportReferenceResolver;
use App\Services\ShipmentImport\ShipmentItemImporter;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create(['role' => Role::Admin, 'auto_ship_enabled' => false]);
    $this->actingAs($this->user);
});

function versionedImporter(): ShipmentItemImporter
{
    return new ShipmentItemImporter(app(ImportReferenceResolver::class));
}

/**
 * Makes the next items-version increment fail, the way a lost connection or
 * deadlock would, without touching the item write before it.
 */
function failTheItemsVersionIncrement(): void
{
    DB::beforeExecuting(function (string $query): void {
        if (str_contains($query, 'items_version')) {
            throw new RuntimeException('Increment failed');
        }
    });
}

function itemsVersion(Shipment $shipment): int
{
    return Shipment::query()->whereKey($shipment->id)->value('items_version');
}

describe('item writes', function (): void {
    it('increments the version when an item is created, changes product or quantity, or is deleted', function (): void {
        $shipment = Shipment::factory()->create();

        $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);
        expect(itemsVersion($shipment))->toBe(1);

        $item->update(['quantity' => 2]);
        expect(itemsVersion($shipment))->toBe(2);

        $item->update(['product_id' => Product::factory()->create()->id]);
        expect(itemsVersion($shipment))->toBe(3);

        $item->delete();
        expect(itemsVersion($shipment))->toBe(4);
    });

    it('does not increment the version for changes the slip does not show', function (): void {
        $shipment = Shipment::factory()->create();
        $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 2, 'value' => 5]);
        $before = itemsVersion($shipment);

        $item->update(['quantity' => '2', 'value' => 9, 'transparency' => ! $item->transparency]);
        $shipment->update(['address1' => '1 New Street', 'city' => 'Elsewhere']);

        expect(itemsVersion($shipment))->toBe($before);
    });

    it('increments both Shipments when an item moves between them', function (): void {
        [$from, $to] = Shipment::factory()->count(2)->create()->all();
        $item = ShipmentItem::factory()->create(['shipment_id' => $from->id]);

        $item->update(['shipment_id' => $to->id]);

        expect(itemsVersion($from))->toBe(2)
            ->and(itemsVersion($to))->toBe(1);
    });
});

describe('imports', function (): void {
    beforeEach(function (): void {
        $this->source = DataSource::factory()->create(['settings' => ['authoritative_shipment_items' => true]]);
        $this->shipment = Shipment::factory()->create(['data_source_id' => $this->source]);
        $this->kept = Product::factory()->create(['sku' => 'KEPT']);
        $this->dropped = Product::factory()->create(['sku' => 'DROPPED']);

        versionedImporter()->import($this->shipment, collect([
            ['sku' => 'KEPT', 'quantity' => 1],
            ['sku' => 'DROPPED', 'quantity' => 1],
        ]), $this->source);

        $this->before = itemsVersion($this->shipment);
    });

    it('increments the version when an import adds an item or changes a quantity', function (): void {
        Product::factory()->create(['sku' => 'ADDED']);

        versionedImporter()->import($this->shipment, collect([
            ['sku' => 'KEPT', 'quantity' => 3],
            ['sku' => 'DROPPED', 'quantity' => 1],
            ['sku' => 'ADDED', 'quantity' => 1],
        ]), $this->source);

        expect(itemsVersion($this->shipment))->toBeGreaterThan($this->before);
    });

    it('does not increment the version when a re-import changes no item', function (): void {
        versionedImporter()->import($this->shipment, collect([
            ['sku' => 'KEPT', 'quantity' => 1],
            ['sku' => 'DROPPED', 'quantity' => 1],
        ]), $this->source);

        expect(itemsVersion($this->shipment))->toBe($this->before);
    });

    it('increments the version when the authoritative snapshot removes an item', function (): void {
        // KEPT is unchanged, so only the bulk delete's own increment can move the version.
        versionedImporter()->import($this->shipment, collect([
            ['sku' => 'KEPT', 'quantity' => 1],
        ]), $this->source);

        expect($this->shipment->shipmentItems()->count())->toBe(1)
            ->and(itemsVersion($this->shipment))->toBe($this->before + 1);
    });

    it('rolls back an import, bulk delete included, when the increment fails', function (): void {
        failTheItemsVersionIncrement();

        expect(fn () => versionedImporter()->import($this->shipment, collect([
            ['sku' => 'KEPT', 'quantity' => 1],
        ]), $this->source))->toThrow(RuntimeException::class, 'Increment failed');

        expect($this->shipment->shipmentItems()->pluck('product_id')->sort()->values()->all())
            ->toBe([$this->kept->id, $this->dropped->id]);
    });

    it('rolls back an imported quantity change when the increment fails', function (): void {
        failTheItemsVersionIncrement();

        expect(fn () => versionedImporter()->import($this->shipment, collect([
            ['sku' => 'KEPT', 'quantity' => 5],
            ['sku' => 'DROPPED', 'quantity' => 1],
        ]), $this->source))->toThrow(RuntimeException::class);

        expect($this->shipment->shipmentItems()->where('product_id', $this->kept->id)->value('quantity'))->toBe(1);
    });
});

describe('the Shipment items table', function (): void {
    beforeEach(function (): void {
        $this->shipment = Shipment::factory()->create();
        $this->item = ShipmentItem::factory()->create(['shipment_id' => $this->shipment->id, 'quantity' => 1]);
        $this->before = itemsVersion($this->shipment);
    });

    function itemsTable(Shipment $shipment): mixed
    {
        return Livewire::test(ShipmentItemsRelationManager::class, [
            'ownerRecord' => $shipment,
            'pageClass' => EditShipment::class,
        ]);
    }

    it('increments the version when an item is created', function (): void {
        itemsTable($this->shipment)
            ->callAction(TestAction::make('create')->table(), [
                'product_id' => Product::factory()->create()->id,
                'quantity' => 2,
            ])
            ->assertHasNoFormErrors();

        expect(itemsVersion($this->shipment))->toBe($this->before + 1);
    });

    it('increments the version when an item\'s quantity is edited', function (): void {
        itemsTable($this->shipment)
            ->callAction(TestAction::make('edit')->table($this->item), ['quantity' => 4])
            ->assertHasNoFormErrors();

        expect(itemsVersion($this->shipment))->toBe($this->before + 1);
    });

    it('increments the version when an item is deleted, singly or in bulk', function (): void {
        $other = ShipmentItem::factory()->create(['shipment_id' => $this->shipment->id]);

        itemsTable($this->shipment)->callAction(TestAction::make('delete')->table($this->item));
        itemsTable($this->shipment)
            ->selectTableRecords([$other])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

        expect($this->shipment->shipmentItems()->count())->toBe(0)
            ->and(itemsVersion($this->shipment))->toBe($this->before + 3);
    });

    it('rolls back an edit when the increment fails', function (): void {
        failTheItemsVersionIncrement();

        expect(fn () => itemsTable($this->shipment)
            ->callAction(TestAction::make('edit')->table($this->item), ['quantity' => 4]))
            ->toThrow(RuntimeException::class, 'Increment failed');

        expect($this->item->fresh()->quantity)->toBe(1);
    });

    it('keeps an item a bulk delete could not record', function (): void {
        failTheItemsVersionIncrement();

        // The bulk action reports each record's failure rather than throwing.
        itemsTable($this->shipment)
            ->selectTableRecords([$this->item])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

        expect($this->item->fresh())->not->toBeNull();
    });
});

describe('printed slips', function (): void {
    it('leaves the slip out of date when an item changes after the version was read', function (): void {
        $shipment = Shipment::factory()->create();
        $receipts = app(PackSlipReceipts::class);
        $receipt = $receipts->issue([$shipment->id], $this->user);

        ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
        $receipts->redeem($receipt);

        expect($shipment->fresh()->packSlipIsOutOfDate())->toBeTrue();
    });

    it('leaves the Shipment out of date when a view drawn before an item change is marked printed', function (): void {
        $shipment = Shipment::factory()->create();
        $view = app(PackSlipRenderer::class)->view(PackSlipRun::forShipment($shipment->id), $this->user);

        ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

        $this->postJson(route('pack-slips.printed'), ['receipt' => $view->getData()['receipt']])->assertOk();

        expect($shipment->fresh()->packSlipIsOutOfDate())->toBeTrue();
    });

    it('shows an out-of-date slip as changed since printed on the Shipment page', function (): void {
        $shipment = printedThenChangedShipment($this->user);

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertSee('Changed since printed');
    });

    it('does not show a current slip as changed', function (): void {
        $shipment = Shipment::factory()->create();
        $receipts = app(PackSlipReceipts::class);
        $receipts->redeem($receipts->issue([$shipment->id], $this->user));

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertSee('Printed')
            ->assertDontSee('Changed since printed');
    });

    it('warns on Scan & Pack without blocking packing', function (): void {
        $shipment = printedThenChangedShipment($this->user);

        Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
            ->assertNotified('Pack Slip Out of Date')
            ->assertSet('shipment.id', $shipment->id)
            ->assertNoRedirect();
    });

    it('does not warn on Scan & Pack when the slip is current', function (): void {
        $shipment = Shipment::factory()->create();

        Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
            ->assertNotNotified('Pack Slip Out of Date');
    });
});

function printedThenChangedShipment(User $user): Shipment
{
    $shipment = Shipment::factory()->create();
    $receipts = app(PackSlipReceipts::class);
    $receipts->redeem($receipts->issue([$shipment->id], $user));

    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    return $shipment;
}
