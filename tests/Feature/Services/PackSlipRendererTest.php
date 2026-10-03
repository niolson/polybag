<?php

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Models\Client;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    app(SettingsService::class)->clearCache();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

function renderPackSlips(PackSlipRun $run): string
{
    return app(PackSlipRenderer::class)->view($run)->render();
}

it('brands each slip from its own Shipment\'s Client', function (): void {
    Storage::disk('public')->put('logos/acme.png', 'acme-logo');
    Storage::disk('public')->put('logos/globex.png', 'globex-logo');

    $acme = Client::factory()->create(['name' => 'Acme', 'logo' => 'logos/acme.png', 'custom_message' => 'Thanks from Acme']);
    $globex = Client::factory()->create(['name' => 'Globex', 'logo' => 'logos/globex.png', 'custom_message' => 'Thanks from Globex']);

    $a = Shipment::factory()->create(['client_id' => $acme->id]);
    $b = Shipment::factory()->create(['client_id' => $globex->id]);

    $slips = app(PackSlipRenderer::class)->view(new PackSlipRun([$a->id, $b->id]))->getData()['slips'];

    expect($slips[0]->logoDataUri)->toBe('data:image/png;base64,'.base64_encode('acme-logo'))
        ->and($slips[1]->logoDataUri)->toBe('data:image/png;base64,'.base64_encode('globex-logo'));

    expect(renderPackSlips(new PackSlipRun([$a->id, $b->id])))
        ->toContain('Thanks from Acme')
        ->toContain('Thanks from Globex');
});

it('falls back to the tenant pack slip logo when the Client has none', function (): void {
    Storage::disk('public')->put('logos/tenant.png', 'tenant-logo');
    app(SettingsService::class)->set('pack_slip_logo', 'logos/tenant.png');

    $client = Client::factory()->create(['logo' => null]);
    $shipment = Shipment::factory()->create(['client_id' => $client->id]);

    $slips = app(PackSlipRenderer::class)->view(PackSlipRun::forShipment($shipment->id))->getData()['slips'];

    expect($slips[0]->logoDataUri)->toBe('data:image/png;base64,'.base64_encode('tenant-logo'));
});

it('prints the tote row only for a Shipment given a tote code', function (): void {
    $toted = Shipment::factory()->create();
    $loose = Shipment::factory()->create();

    $html = renderPackSlips(new PackSlipRun([$toted->id, $loose->id], [$toted->id => 'T07']));

    expect(substr_count($html, 'class="tote-cell"'))->toBe(1)
        ->and($html)->toContain('T07');

    expect(renderPackSlips(PackSlipRun::forShipment($loose->id)))->not->toContain('class="tote-cell"');
});

it('puts the scan code, reference and items on every slip', function (): void {
    config(['app.scan_code_prefix' => 'PB']);

    $shipment = Shipment::factory()->create(['shipment_reference' => '#1247']);
    $product = Product::factory()->create(['sku' => 'SKU-RED', 'name' => 'Red Widget']);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id, 'quantity' => 3]);

    expect(renderPackSlips(PackSlipRun::forShipment($shipment->id)))
        ->toContain('#1247')
        ->toContain("PBS{$shipment->id}")
        ->toContain('SKU-RED')
        ->toContain('Red Widget');
});

it('draws slips in run order and skips Shipments that no longer exist', function (): void {
    $first = Shipment::factory()->create();
    $second = Shipment::factory()->create();

    $slips = app(PackSlipRenderer::class)
        ->view(new PackSlipRun([$second->id, 999999, $first->id]))
        ->getData()['slips'];

    expect(array_map(fn ($slip) => $slip->shipment->id, $slips))->toBe([$second->id, $first->id]);
});

it('orders a pick batch run by tote code, naturally', function (): void {
    $batch = PickBatch::factory()->create();
    $shipments = Shipment::factory()->count(3)->create();

    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $shipments[0]->id, 'tote_code' => 'T10']);
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $shipments[1]->id, 'tote_code' => 'T2']);
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id, 'shipment_id' => $shipments[2]->id, 'tote_code' => 'T1']);

    $run = app(PickBatchService::class)->packSlipRun($batch);

    expect($run->shipmentIds)->toBe([$shipments[2]->id, $shipments[1]->id, $shipments[0]->id])
        ->and($run->toteCodes)->toBe([
            $shipments[2]->id => 'T1',
            $shipments[1]->id => 'T2',
            $shipments[0]->id => 'T10',
        ]);
});

it('splits a run into chunks that keep order and their own tote codes', function (): void {
    $run = new PackSlipRun([5, 3, 9], [5 => 'T1', 9 => 'T3']);

    [$first, $second] = $run->chunk(2);

    expect($first->shipmentIds)->toBe([5, 3])
        ->and($first->toteCodes)->toBe([5 => 'T1'])
        ->and($second->shipmentIds)->toBe([9])
        ->and($second->toteCodes)->toBe([9 => 'T3']);
});
