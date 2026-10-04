<?php

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\Role;
use App\Filament\Pages\Pack;
use App\Filament\Pages\PrintPackSlips;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\PickBatches\Pages\ViewPickBatch;
use App\Filament\Resources\PickBatches\RelationManagers\PickBatchShipmentsRelationManager;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Models\Client;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\PackSlips\PackSlipReceipts;
use App\Services\PackSlips\PackSlipViews;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($this->manager);
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

function turnPackSlipsOff(): void
{
    app(SettingsService::class)->set('pack_slips_enabled', false, 'boolean');
}

describe('the setting', function (): void {
    it('is on for an install that never saved it', function (): void {
        expect(app(SettingsService::class)->packSlipsEnabled())->toBeTrue();

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->assertSet('data.pack_slips_enabled', true)
            ->call('save')
            ->assertNotified();

        expect(app(SettingsService::class)->packSlipsEnabled())->toBeTrue();
    });

    it('is turned off from Settings', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm(['pack_slips_enabled' => false])
            ->call('save')
            ->assertNotified();

        expect(app(SettingsService::class)->packSlipsEnabled())->toBeFalse();
    });

    it('relabels picking and says pack slips then print from pick batches', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm(['picking_enabled' => true])
            ->assertSee('PolyBag Prints Pack Slips')
            ->assertSee('PolyBag Prints Pick Batches')
            ->assertSee('Pack slips then print from pick batches.');
    });
});

describe('with pack slips off', function (): void {
    beforeEach(function (): void {
        turnPackSlipsOff();
    });

    it('refuses the Print Pack Slips page and drops it from navigation', function (): void {
        expect(PrintPackSlips::canAccess())->toBeFalse();

        $this->get(PrintPackSlips::getUrl())->assertForbidden();
    });

    it('refuses to render pack slips by any route', function (): void {
        $shipment = Shipment::factory()->create();
        $batch = PickBatch::factory()->create(['total_shipments' => 1]);
        PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $shipment->id]);
        $key = app(PackSlipViews::class)->put(new PackSlipRun([$shipment->id]));

        $this->get(route('shipments.pack-slip', $shipment))->assertForbidden();
        $this->get(route('pack-slips.view', $key))->assertForbidden();
        $this->get(route('pick-batches.pack-slips', $batch))->assertForbidden();
    });

    it('still records a slip whose job was sent before pack slips were turned off', function (): void {
        app(SettingsService::class)->set('pack_slips_enabled', true, 'boolean');
        $shipment = Shipment::factory()->create();
        $receipts = app(PackSlipReceipts::class);
        $receipt = $receipts->seal($receipts->issue([$shipment->id], $this->manager));
        turnPackSlipsOff();

        $this->postJson(route('pack-slips.printed'), ['receipt' => $receipt])->assertOk();

        expect($shipment->fresh()->hasPrintedPackSlip())->toBeTrue();
    });

    it('hides the Shipment page action and printed details', function (): void {
        $shipment = Shipment::factory()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertActionHidden('printPackSlip')
            ->assertDontSee('Not printed');
    });

    it('hides the Shipments list column and filter', function (): void {
        Livewire::test(ListShipments::class)
            ->assertTableColumnHidden('pack_slip')
            ->assertTableFilterHidden('pack_slip');
    });

    it('does not warn at Scan & Pack that a slip is out of date', function (): void {
        app(SettingsService::class)->set('pack_slips_enabled', true, 'boolean');
        $shipment = Shipment::factory()->create();
        $receipts = app(PackSlipReceipts::class);
        $receipts->redeem($receipts->issue([$shipment->id], $this->manager));
        ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
        turnPackSlipsOff();

        Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
            ->assertNotNotified('Pack Slip Out of Date');
    });

    it('opens a Shipment at Scan & Pack by its exact ERP reference', function (): void {
        $shipment = Shipment::factory()->create(['shipment_reference' => 'ERP-000417']);

        Livewire::test(Pack::class)
            ->call('navigateToShipment', 'ERP-000417')
            ->assertRedirect("/pack/{$shipment->id}");
    });

    describe('with picking on', function (): void {
        beforeEach(function (): void {
            app(SettingsService::class)->set('picking_enabled', true, 'boolean');
            $this->batch = PickBatch::factory()->create(['total_shipments' => 1]);
            PickBatchShipment::factory()->create(['pick_batch_id' => $this->batch->id]);
        });

        it('offers only the Picking Summary on View Pick Batch', function (): void {
            Livewire::test(ViewPickBatch::class, ['record' => $this->batch->id])
                ->assertActionVisible('viewSummary')
                ->assertActionHidden('viewPackSlips')
                ->set('printMode', true)
                ->assertActionVisible('printSummary')
                ->assertActionHidden('printPackSlips')
                ->assertActionHidden('printBoth');
        });

        it('drops the Slip Printed columns from the batch\'s Shipments', function (): void {
            Livewire::test(PickBatchShipmentsRelationManager::class, [
                'ownerRecord' => $this->batch,
                'pageClass' => ViewPickBatch::class,
            ])
                ->assertTableColumnHidden('shipment.pack_slip_printed_at');
        });
    });
});

describe('pack slip branding', function (): void {
    it('keeps the default client\'s branding and the tenant logo when Settings is saved with pack slips off', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        Storage::fake('public');
        Storage::disk('public')->put('logos/client.png', 'png');
        app(SettingsService::class)->set('pack_slip_logo', 'logos/tenant.png');
        $client = Client::factory()->create([
            'is_default' => true,
            'logo' => 'logos/client.png',
            'custom_message' => 'Thank you!',
        ]);

        Livewire::test(Settings::class)
            ->fillForm(['pack_slips_enabled' => false])
            ->assertSee('Inactive. '.Settings::PACK_SLIPS_OFF_NOTE)
            ->call('save')
            ->assertNotified();

        app(SettingsService::class)->clearCache();

        $client->refresh();

        expect(app(SettingsService::class)->get('pack_slip_logo'))->toBe('logos/tenant.png')
            ->and($client->logo)->toBe('logos/client.png')
            ->and($client->custom_message)->toBe('Thank you!');

        Livewire::test(Settings::class)
            ->fillForm(['pack_slips_enabled' => true])
            ->call('save');

        expect($client->fresh()->custom_message)->toBe('Thank you!');
    });

    it('marks a Client\'s pack slip branding inactive but editable', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        turnPackSlipsOff();
        $client = Client::factory()->create(['custom_message' => 'Thank you!']);

        Livewire::test(EditClient::class, ['record' => $client->id])
            ->assertSee('Inactive. '.Settings::PACK_SLIPS_OFF_NOTE)
            ->assertFormFieldEnabled('custom_message')
            ->fillForm(['custom_message' => 'Thanks again!'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($client->fresh()->custom_message)->toBe('Thanks again!');
    });
});
