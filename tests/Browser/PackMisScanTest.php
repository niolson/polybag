<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;

/**
 * A scan that may have put the wrong thing in the box stops packing until the
 * packer acknowledges it (`pack-mis-scan-stop/01`).
 */
const PACK_SCAN_INPUT = 'input[x-ref="scanInput"]';

function packScan(mixed $page, string $code): mixed
{
    return $page->fill(PACK_SCAN_INPUT, $code)->keys(PACK_SCAN_INPUT, 'Enter');
}

function packedCount(mixed $page): int
{
    return (int) $page->script('Alpine.$data(document.querySelector(\'input[x-ref="scanInput"]\')).packingItems[0]?.packed ?? 0');
}

/**
 * Visit Scan & Pack for an order of one product. Log in before calling it.
 *
 * @return array{0: mixed, 1: Product}
 */
function visitPackWithOneItem(int $quantity): array
{
    $product = Product::factory()->create(['barcode' => '2000000000015']);
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'transparency' => false,
    ]);

    return [visit('/pack/'.$shipment->id), $product];
}

it('stops packing on a wrong item and refuses scans until OK is scanned', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    [$page, $product] = visitPackWithOneItem(2);

    packScan($page, 'NOT-ON-THIS-ORDER');

    $page->assertVisible('[data-testid="mis-scan-alert"]')
        ->assertSee('NOT-ON-THIS-ORDER is not on this order.');

    // The next item scanned is refused, not packed.
    $page->wait(0.6);
    packScan($page, $product->barcode);

    $page->assertVisible('[data-testid="mis-scan-alert"]')
        ->assertSee('1 scan ignored');
    expect(packedCount($page))->toBe(0);

    // A command other than OK is refused too.
    packScan($page, '%CSHIP');
    $page->assertSee('2 scans ignored');

    packScan($page, '%cok');
    $page->assertMissing('[data-testid="mis-scan-alert"]');

    packScan($page, $product->barcode);
    expect(packedCount($page))->toBe(1);

    $page->assertNoJavaScriptErrors();
});

it('stops packing on an extra unit, ignoring an Enter that trails any scan', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    [$page, $product] = visitPackWithOneItem(1);

    packScan($page, $product->barcode);
    packScan($page, $product->barcode);

    $page->assertVisible('[data-testid="mis-scan-alert"]')
        ->assertSee('Extra unit');
    expect(packedCount($page))->toBe(1);

    // A scanner sending CR+LF puts a second Enter right behind the scan.
    $page->keys(PACK_SCAN_INPUT, 'Enter');
    $page->assertVisible('[data-testid="mis-scan-alert"]');

    // So does every scan refused later, however long the alert has been up.
    $page->wait(0.6);
    packScan($page, $product->barcode);
    $page->keys(PACK_SCAN_INPUT, 'Enter');
    $page->assertVisible('[data-testid="mis-scan-alert"]')
        ->assertSee('1 scan ignored');

    $page->wait(0.6);
    $page->keys(PACK_SCAN_INPUT, 'Enter');
    $page->assertMissing('[data-testid="mis-scan-alert"]');

    $page->assertNoJavaScriptErrors();
});

it('stops packing on an unknown product in scan-to-add mode', function (): void {
    Setting::create(['key' => 'scan_to_add_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    $shipment = Shipment::factory()->create();
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));

    $page = visit('/pack/'.$shipment->id);
    packScan($page, 'NO-SUCH-PRODUCT');
    $page->wait(1);

    $page->assertVisible('[data-testid="mis-scan-alert"]')
        ->assertSee('No product matches NO-SUCH-PRODUCT.');

    $page->click('[data-testid="mis-scan-alert"] button');
    $page->assertMissing('[data-testid="mis-scan-alert"]');

    $page->assertNoJavaScriptErrors();
});

it('says there is nothing to confirm when OK is scanned with no alert open', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    [$page] = visitPackWithOneItem(1);

    packScan($page, '%COK');

    $page->assertSee('Nothing to Confirm')
        ->assertNoJavaScriptErrors();
});
