<?php

use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\GotenbergService;
use App\Services\PackSlips\PackSlipReceipts;
use Filament\Actions\Action;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->shipper = User::factory()->create(['role' => Role::User, 'name' => 'Pat Packer']);
    $this->actingAs($this->shipper);
});

function fakePdfRenderer(): void
{
    $gotenberg = Mockery::mock(GotenbergService::class);
    $gotenberg->shouldReceive('pdfFromView')->andReturn('%PDF-fake');
    app()->instance(GotenbergService::class, $gotenberg);
}

it('lets a Shipper print a Shipment\'s pack slip, and records it only once the receipt is redeemed', function (): void {
    fakePdfRenderer();
    $shipment = Shipment::factory()->create();

    $page = Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertActionHasLabel('printPackSlip', 'Print Pack Slip')
        ->callAction('printPackSlip')
        ->assertDispatched('print-pack-slips');

    // Sending the job records nothing; only QZ Tray's acknowledgment does.
    expect($shipment->fresh()->hasPrintedPackSlip())->toBeFalse();

    $dispatch = collect($page->effects['dispatches'])->firstWhere('name', 'print-pack-slips');
    $job = $dispatch['params']['jobs'][0];

    expect($job['count'])->toBe(1)
        ->and(base64_decode($job['data']))->toBe('%PDF-fake');

    $this->postJson(route('pack-slips.printed'), ['receipt' => $job['receipt']])->assertOk();

    $shipment->refresh();

    expect($shipment->hasPrintedPackSlip())->toBeTrue()
        ->and($shipment->pack_slip_printed_by_user_id)->toBe($this->shipper->id);

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertActionHasLabel('printPackSlip', 'Reprint Pack Slip')
        ->assertSee('by Pat Packer');
});

it('shows a never-printed Shipment as not printed', function (): void {
    $shipment = Shipment::factory()->create();

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertSee('Not printed');
});

it('warns that a batched Shipment\'s slip prints without its tote code, and links the batch', function (): void {
    fakePdfRenderer();
    $shipment = Shipment::factory()->create();
    $batch = PickBatch::factory()->create();
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $shipment->id, 'tote_code' => 'T04']);

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertSee("Batch #{$batch->id}")
        ->mountAction('printPackSlip')
        ->assertMountedActionModalSee("This Shipment is in pick batch #{$batch->id}. A slip printed here has no tote code")
        ->callMountedAction()
        ->assertDispatched('print-pack-slips');
});

it('does not ask for confirmation when the Shipment is in no active batch', function (): void {
    $shipment = Shipment::factory()->create();

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertDontSee('Pick Batch')
        ->assertActionExists('printPackSlip', fn (Action $action): bool => ! $action->isConfirmationRequired());
});

it('hides printing for a shipped Shipment', function (): void {
    $shipment = Shipment::factory()->create(['status' => ShipmentStatus::Shipped]);

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertActionHidden('printPackSlip')
        ->assertActionVisible('viewPackSlip');
});

it('reports the renderer being unavailable without dispatching a print', function (): void {
    $this->mock(GotenbergService::class)
        ->shouldReceive('pdfFromView')
        ->andThrow(new RuntimeException('PDF renderer unavailable. Is Gotenberg running?'));

    $shipment = Shipment::factory()->create();

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->callAction('printPackSlip')
        ->assertNotDispatched('print-pack-slips')
        ->assertNotified('PDF renderer unavailable');
});

it('serves the browser view with a receipt for the viewing user and a Mark as printed control', function (): void {
    $shipment = Shipment::factory()->create(['shipment_reference' => 'ORD-555']);

    $response = $this->get(route('shipments.pack-slip', $shipment))
        ->assertOk()
        ->assertSee('ORD-555')
        ->assertSee('Mark as printed');

    $receipt = app(PackSlipReceipts::class)->open($response->viewData('receipt'), $this->shipper);

    expect($receipt->itemsVersions)->toBe([$shipment->id => 0]);
});

it('requires authentication for the browser view', function (): void {
    auth()->logout();

    $this->get(route('shipments.pack-slip', Shipment::factory()->create()))->assertRedirect('/login');
});
