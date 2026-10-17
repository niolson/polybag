<?php

use App\Enums\Role;
use App\Filament\Pages\Pack;
use App\Filament\Pages\PrintCommandBarcodes;
use App\Models\BoxSize;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\CacheService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin, 'auto_ship_enabled' => false]));
});

/**
 * A shipment with one line of two, and the packing state to save for it.
 *
 * @return array{0: Shipment, 1: list<array<string, mixed>>}
 */
function clarityShipment(int $packed = 1): array
{
    $product = Product::factory()->create();
    $shipment = Shipment::factory()->create();
    $line = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'transparency' => false,
    ]);

    return [$shipment, [[
        'id' => $line->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'packed' => $packed,
        'transparency_codes' => [],
    ]]];
}

it('names the button for what it does, and confirms it in a modal rather than the browser', function (): void {
    [$shipment] = clarityShipment();

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSee('Unpack All Items')
        ->assertSeeHtml("open-modal', { id: 'unpack-all-confirm' }")
        ->assertSee('Unpack all items?')
        ->assertSee('The box and measurements stay.')
        ->assertSee('To leave this shipment instead, scan the Clear Shipment command barcode.')
        ->assertSeeHtml('@pack-unpack-all.window="unpackAll()"')
        ->assertDontSeeHtml('window.confirm(');
});

it('says when the draft was saved, on save and when the shipment is opened again', function (): void {
    Carbon::setTestNow('2026-10-17 18:42:00');
    [$shipment, $packingItems] = clarityShipment();

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('draftSavedAt', null)
        ->assertDontSeeText('Draft saved')
        ->call('saveDraft', $packingItems, null, '', '', '', '')
        ->assertSet('draftSavedAt', fn (?string $time): bool => $time !== null);

    $time = Carbon::now()->tz(Location::timezone())->format('g:i A');

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('draftSavedAt', $time)
        ->assertSeeText("Draft saved {$time}");
});

it('names the box a draft was packed in', function (): void {
    $box = BoxSize::factory()->create(['code' => '03', 'label' => '8x6x4', 'height' => 4, 'width' => 6, 'length' => 8]);
    app(CacheService::class)->clearBoxSizesCache();
    [$shipment, $packingItems] = clarityShipment();

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->call('saveDraft', $packingItems, $box->id, '', '4', '6', '8');

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSeeHtml('data-testid="box-name"')
        ->assertSeeText('Box: 8x6x4 (03)')
        // A box scanned after the first render is named by the page itself.
        ->assertSeeHtml('x-text="boxName()"');
});

it('counts a partly packed line, and keeps the unpack button in place before the first scan', function (): void {
    [$shipment] = clarityShipment();

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSeeHtml('`${packingItem.packed} of ${packingItem.quantity} packed`')
        ->assertSeeHtml("packingItem.packed > 0 ? '' : 'invisible'");
});

it('explains Auto Ship on its button', function (): void {
    [$shipment] = clarityShipment();

    Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSeeHtml('title="Buy and print the cheapest on-time label as soon as a package is packed."');
});

it('keeps Scan & Pack lit on Print Command Barcodes', function (): void {
    expect(Pack::getNavigationItemActiveRoutePattern())->toContain(PrintCommandBarcodes::getRouteName());
});
