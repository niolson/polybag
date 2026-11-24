<?php

use App\Enums\Role;
use App\Models\BoxSize;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Scanning\ScanCode;

/**
 * A Box Size is scanned or typed as its PolyBag code, `%B<id>`; nothing else
 * applies a box (ADR-0007, as amended 2026-11-24).
 */
function boxScanPackState(mixed $page, string $expression): mixed
{
    return $page->script('Alpine.$data(document.querySelector(\'input[x-ref="scanInput"]\')).'.$expression);
}

function boxScanSubmit(mixed $page, string $code): mixed
{
    $input = 'input[x-ref="scanInput"]';

    return $page->fill($input, $code)->keys($input, 'Enter');
}

it('applies a box typed as its PolyBag code, leading zeros and case aside', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $box = BoxSize::factory()->create(['label' => '8x6x4', 'length' => 8, 'width' => 6, 'height' => 4]);
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => Product::factory()->create()->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    $page = visit('/pack/'.$shipment->id);

    boxScanSubmit($page, strtolower(ScanCode::PREFIX.'b0'.$box->id));

    expect(boxScanPackState($page, 'boxSizeId'))->toBe($box->id)
        ->and(boxScanPackState($page, 'length'))->toBe('8.00')
        ->and(boxScanPackState($page, 'height'))->toBe('4.00');

    $page->assertSeeIn('[data-testid="box-name"]', '8x6x4')
        ->assertNoJavaScriptErrors();
});

it('reports an inactive box instead of applying it', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));
    $box = BoxSize::factory()->inactive()->create(['label' => 'FedEx Tube']);
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => Product::factory()->create()->id,
        'quantity' => 1,
        'transparency' => false,
    ]);

    $page = visit('/pack/'.$shipment->id);

    boxScanSubmit($page, ScanCode::forBoxSize($box));

    $page->assertSee('Box Size Inactive');
    expect(boxScanPackState($page, 'boxSizeId'))->toBeNull();
});
