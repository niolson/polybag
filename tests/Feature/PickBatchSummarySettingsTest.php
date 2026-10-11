<?php

use App\Enums\PickingStatus;
use App\Filament\Pages\Settings;
use App\Filament\Resources\PickBatches\Pages\ViewPickBatch;
use App\Filament\Resources\PickBatches\RelationManagers\PickBatchShipmentsRelationManager;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Models\PickBatch;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->manager()->create();
    $this->actingAs($this->user);
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

/**
 * Two orders sharing one product, batched under the current settings.
 */
function summaryBatch(User $user): PickBatch
{
    $product = Product::factory()->create([
        'sku' => 'SKU-WIDGET',
        'barcode' => '012345678905',
        'bin_location' => 'A-01-03',
        'name' => 'Widget',
    ]);

    $first = Shipment::factory()->create(['picking_status' => PickingStatus::Pending, 'shipment_reference' => 'ORD-1001']);
    $second = Shipment::factory()->create(['picking_status' => PickingStatus::Pending, 'shipment_reference' => 'ORD-1002']);

    ShipmentItem::factory()->create(['shipment_id' => $first->id, 'product_id' => $product->id, 'quantity' => 2]);
    ShipmentItem::factory()->create(['shipment_id' => $second->id, 'product_id' => $product->id, 'quantity' => 1]);

    return app(PickBatchService::class)->createFromShipments(collect([$first, $second]), $user);
}

it('prints bin, SKU and totes but no barcode by default', function (): void {
    $batch = summaryBatch($this->user);

    $this->get(route('pick-batches.summary', $batch))
        ->assertOk()
        ->assertSeeInOrder(['<th>Bin</th>', '<th>SKU</th>', '<th>Product</th>', '<th>Qty</th>', '<th>Totes</th>'], escape: false)
        ->assertDontSee('<th>Barcode</th>', escape: false)
        ->assertDontSee('012345678905')
        ->assertSee('A-01-03')
        ->assertSee('T01')
        ->assertDontSee('ORD-1001');
});

it('drops and adds the optional columns from settings', function (): void {
    $settings = app(SettingsService::class);
    $settings->set('pick_summary_show_bin', false, 'boolean');
    $settings->set('pick_summary_show_sku', false, 'boolean');
    $settings->set('pick_summary_show_barcode', true, 'boolean');

    $batch = summaryBatch($this->user);

    $this->get(route('pick-batches.summary', $batch))
        ->assertOk()
        ->assertDontSee('<th>Bin</th>', escape: false)
        ->assertDontSee('<th>SKU</th>', escape: false)
        ->assertDontSee('A-01-03')
        ->assertDontSee('SKU-WIDGET')
        ->assertSee('<th>Barcode</th>', escape: false)
        ->assertSee('012345678905');
});

describe('with pick to totes off', function (): void {
    beforeEach(function (): void {
        app(SettingsService::class)->set('pick_to_tote_enabled', false, 'boolean');
    });

    it('assigns no tote codes', function (): void {
        $batch = summaryBatch($this->user);

        expect($batch->pickBatchShipments()->pluck('tote_code')->filter())->toBeEmpty();
    });

    it('lists order references with their quantities instead of totes', function (): void {
        $batch = summaryBatch($this->user);

        $this->get(route('pick-batches.summary', $batch))
            ->assertOk()
            ->assertSee('<th>Orders</th>', escape: false)
            ->assertDontSee('<th>Totes</th>', escape: false)
            ->assertSeeInOrder(['ORD-1001', '×2', 'ORD-1002', '×1'])
            ->assertDontSee('T01');
    });

    it('prints pack slips without a tote row, in batch order', function (): void {
        $batch = summaryBatch($this->user);

        $this->get(route('pick-batches.pack-slips', $batch))
            ->assertOk()
            ->assertSeeInOrder(['ORD-1001', 'ORD-1002'])
            ->assertDontSee('<div class="tote-label">', escape: false);
    });

    it('hides the tote column on the batch', function (): void {
        $batch = summaryBatch($this->user);

        Livewire::test(PickBatchShipmentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewPickBatch::class,
        ])->assertTableColumnHidden('tote_code');
    });

    it('prints a batched shipment\'s pack slip without asking about its tote', function (): void {
        $batch = summaryBatch($this->user);
        $shipment = $batch->shipments()->first();

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertActionExists('printPackSlip', fn (Action $action): bool => ! $action->isConfirmationRequired());
    });

    it('still prints totes for a batch created while it was on', function (): void {
        app(SettingsService::class)->set('pick_to_tote_enabled', true, 'boolean');
        $batch = summaryBatch($this->user);
        app(SettingsService::class)->set('pick_to_tote_enabled', false, 'boolean');

        $this->get(route('pick-batches.summary', $batch))
            ->assertSee('<th>Totes</th>', escape: false)
            ->assertSee('T01');
    });
});

describe('the settings', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('saves from the Pick Batches section', function (): void {
        Livewire::test(Settings::class)
            ->assertSet('data.pick_to_tote_enabled', true)
            ->assertSet('data.pick_summary_show_bin', true)
            ->assertSet('data.pick_summary_show_sku', true)
            ->assertSet('data.pick_summary_show_barcode', false)
            ->fillForm([
                'pick_to_tote_enabled' => false,
                'pick_summary_show_bin' => false,
                'pick_summary_show_barcode' => true,
            ])
            ->call('save')
            ->assertNotified();

        $settings = app(SettingsService::class);

        expect($settings->get('pick_to_tote_enabled'))->toBeFalse()
            ->and($settings->get('pick_summary_show_bin'))->toBeFalse()
            ->and($settings->get('pick_summary_show_sku'))->toBeTrue()
            ->and($settings->get('pick_summary_show_barcode'))->toBeTrue();
    });

    it('keeps them while picking is turned off', function (): void {
        app(SettingsService::class)->set('pick_to_tote_enabled', false, 'boolean');

        Livewire::test(Settings::class)
            ->fillForm(['picking_enabled' => false])
            ->assertDontSee('Pick to Totes')
            ->call('save')
            ->assertNotified();

        expect(app(SettingsService::class)->get('pick_to_tote_enabled'))->toBeFalse();
    });
});
