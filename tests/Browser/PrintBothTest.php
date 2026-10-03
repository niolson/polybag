<?php

use App\Enums\Role;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Services\PackSlips\PackSlipReceipts;
use App\Services\SettingsService;

/**
 * Print Both's ordering lives in the browser: the slips must wait on the summary, and
 * a summary that fails must stop them. QZ Tray is replaced by a recorder that can be
 * told to fail the document printer.
 */
beforeEach(function (): void {
    $this->user = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($this->user);
    Setting::create(['key' => 'picking_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $this->shipment = Shipment::factory()->create();
    $this->batch = PickBatch::factory()->create(['total_shipments' => 1]);
    PickBatchShipment::factory()->create(['pick_batch_id' => $this->batch->id, 'shipment_id' => $this->shipment->id]);

    $receipts = app(PackSlipReceipts::class);
    $this->receipt = $receipts->seal($receipts->issue([$this->shipment->id], $this->user));
});

function visitWithRecordingQz(PickBatch $batch, bool $failDocumentPrinter): mixed
{
    $page = visit('/pick-batches/'.$batch->id);

    $page->script("
        localStorage.setItem('reportPrinter', 'Office Laser');
        localStorage.setItem('imageLabelPrinter', 'Zebra 4x6');
    ");

    // Let the page's own QZ Tray connection attempt settle, then stand in for the
    // library with everything the print path calls.
    $page->wait(2);
    $page->script('
        window.printedTo = [];
        window.qz = window.qz || {};
        qz.websocket = { isActive: () => true };
        qz.configs = { create: (printer) => ({ printer }) };
        qz.print = async (config) => {
            if ('.($failDocumentPrinter ? 'true' : 'false')." && config.printer === 'Office Laser') {
                throw new Error('Office Laser is offline');
            }
            window.printedTo.push(config.printer);
        };
        true;
    ");

    return $page;
}

it('prints the summary, then the slips, and records the slips', function (): void {
    $page = visitWithRecordingQz($this->batch, failDocumentPrinter: false);

    $page->script("Livewire.dispatch('print-pick-batch', { summary: 'JVBERg==', jobs: [{ data: 'JVBERg==', receipt: ".json_encode($this->receipt).', count: 1 }] })');
    $page->wait(1);

    expect($page->script('window.printedTo'))->toBe(['Office Laser', 'Zebra 4x6'])
        ->and($page->script("document.getElementById('qz-status-text').textContent"))->toContain('Sent 1 pack slip to the printer')
        ->and($this->shipment->fresh()->hasPrintedPackSlip())->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('sends no slips when the summary fails to print', function (): void {
    $page = visitWithRecordingQz($this->batch, failDocumentPrinter: true);

    $page->script("Livewire.dispatch('print-pick-batch', { summary: 'JVBERg==', jobs: [{ data: 'JVBERg==', receipt: ".json_encode($this->receipt).', count: 1 }] })');
    $page->wait(1);

    expect($page->script('window.printedTo'))->toBe([])
        ->and($page->script("document.getElementById('qz-status-text').textContent"))
        ->toContain('Office Laser is offline')
        ->toContain('No pack slips were sent or recorded as printed.')
        ->and($this->shipment->fresh()->hasPrintedPackSlip())->toBeFalse();

    $page->assertNoJavaScriptErrors();
});
