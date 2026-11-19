<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;

/**
 * The demo Pack Next Item command packs each line in turn, as scanning its
 * barcode would, so a demo needs no product labels.
 */
it('packs the next unpacked item on each scan of the command', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));

    $shipment = Shipment::factory()->create();
    foreach ([2, 1] as $quantity) {
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => $quantity,
            'transparency' => false,
        ]);
    }

    $input = 'input[x-ref="scanInput"]';
    $page = visit('/pack/'.$shipment->id);
    $packed = fn (): array => $page->script(
        'Alpine.$data(document.querySelector(\'input[x-ref="scanInput"]\')).packingItems.map(item => item.packed)'
    );

    $page->fill($input, '%CNEXT')->keys($input, 'Enter');
    expect($packed())->toBe([1, 0]);

    $page->fill($input, '%cnext')->keys($input, 'Enter');
    $page->fill($input, '%CNEXT')->keys($input, 'Enter');
    expect($packed())->toBe([2, 1]);

    $page->fill($input, '%CNEXT')->keys($input, 'Enter');
    $page->assertSee('Nothing Left to Pack')
        ->assertMissing('[data-testid="mis-scan-alert"]')
        ->assertNoJavaScriptErrors();
    expect($packed())->toBe([2, 1]);
});
