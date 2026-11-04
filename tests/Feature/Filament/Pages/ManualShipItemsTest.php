<?php

use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\ManualShip\ManualShipLine;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\Filament\Pages\ManualShip;
use App\Filament\Pages\Pack;
use App\Filament\Pages\Settings;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\RelationManagers\ShipmentItemsRelationManager;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\ClientContext;
use App\Services\ManualShipContents;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create(['auto_ship_enabled' => false]));
    $this->undoRepeaterFake = Repeater::fake();

    Channel::factory()->create(['name' => 'Manual']);
    Setting::create(['key' => 'manual_ship_items_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $this->client = app(ClientContext::class)->default();
    $this->catalogProduct = Product::factory()->create([
        'client_id' => $this->client->id,
        'sku' => 'MUG-01',
        'name' => 'Mug',
        'description' => null,
        'weight' => 0.75,
        'hs_tariff_number' => '6912.00',
        'country_of_origin' => 'PT',
        'customs_value' => 12.50,
    ]);
});

afterEach(function (): void {
    ($this->undoRepeaterFake)();
    app(SettingsService::class)->clearCache();
});

/**
 * @return array<string, mixed>
 */
function manualShipItemsForm(array $overrides = []): array
{
    return [
        'shipment_reference' => 'MAN-ITEMS',
        'first_name' => 'Robin',
        'last_name' => 'Tremblay',
        'address1' => '100 Queen St W',
        'city' => 'Toronto',
        'country' => 'CA',
        'state_or_province' => 'ON',
        'postal_code' => 'M5H 2N2',
        'shipping_method_id' => ShippingMethod::factory()->create()->id,
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 3,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        ...$overrides,
    ];
}

/**
 * A catalog line and a typed-in one, the catalog line picked so its fields prefill.
 */
function fillManualShipItems(mixed $component, Product $catalogProduct): mixed
{
    return $component
        ->set('data.items', [
            ['product_id' => null, 'quantity' => 2],
            [
                'product_id' => null,
                'description' => 'Replacement lid',
                'sku' => 'LID-SAMPLE',
                'quantity' => 1,
                'value' => '3.25',
                'weight' => '0.10',
                'hs_tariff_number' => '3923.50',
                'country_of_origin' => 'us',
            ],
        ])
        ->set('data.items.0.product_id', (string) $catalogProduct->id);
}

/**
 * What the first line's catalog select lists for a search, as the browser asks for it.
 *
 * @return array<int, array{label: string, value: mixed}>
 */
function searchManualShipCatalog(mixed $component, string $search): array
{
    return $component
        ->call('callSchemaComponentMethod', 'form.items.0.product_id', 'getSearchResultsForJs', ['search' => $search])
        ->effects['returns'][0] ?? [];
}

it('prefills a catalog line from its Product, valued in the Client\'s currency', function (): void {
    $component = Livewire::test(ManualShip::class)->fillForm(manualShipItemsForm());
    fillManualShipItems($component, $this->catalogProduct);

    $component
        ->assertSet('data.items.0.description', 'Mug')
        ->assertSet('data.items.0.sku', 'MUG-01')
        ->assertSet('data.items.0.hs_tariff_number', '6912.00')
        ->assertSet('data.items.0.country_of_origin', 'PT')
        ->assertSet('data.items.0.value', 12.5);
});

it('records the items on the Draft Shipment and packs them in full for the Ship page', function (): void {
    $component = Livewire::test(ManualShip::class)->fillForm(manualShipItemsForm());
    fillManualShipItems($component, $this->catalogProduct);
    $component->call('ship')->assertHasNoFormErrors();

    $shipment = Shipment::where('shipment_reference', 'MAN-ITEMS')->firstOrFail();
    $package = Package::where('shipment_id', $shipment->id)->firstOrFail();
    $component->assertRedirect('/ship/'.$package->id);

    $typedIn = Product::where('client_id', $this->client->id)->where('sku', 'LID-SAMPLE')->firstOrFail();
    expect($typedIn->name)->toBe('Replacement lid')
        ->and((float) $typedIn->weight)->toBe(0.1)
        ->and($typedIn->hs_tariff_number)->toBe('3923.50')
        ->and($typedIn->country_of_origin)->toBe('US')
        ->and((float) $typedIn->customs_value)->toBe(3.25);

    $items = $shipment->shipmentItems()->orderBy('id')->get();
    expect($items->map(fn (ShipmentItem $item): array => [$item->product_id, $item->quantity, (float) $item->value])->all())
        ->toBe([[$this->catalogProduct->id, 2, 12.5], [$typedIn->id, 1, 3.25]])
        // 2 × 12.50 + 3.25
        ->and((float) $shipment->fresh()->value)->toBe(28.25);

    expect($package->packageItems()->orderBy('id')->get()->map(fn (PackageItem $item): array => [$item->shipment_item_id, $item->quantity])->all())
        ->toBe([[$items[0]->id, 2], [$items[1]->id, 1]]);

    // The Ship page refuses a Package whose items are not all packed.
    Setting::create(['key' => 'packing_validation_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
    expect(app(PackageDraftWorkflow::class)->assertPackageReadyToShip($package)->package->id)->toBe($package->id);
});

it('declares the entered items and values on the customs form', function (): void {
    $component = Livewire::test(ManualShip::class)->fillForm(manualShipItemsForm());
    fillManualShipItems($component, $this->catalogProduct);
    $component->call('ship')->assertHasNoFormErrors();

    $package = Package::whereHas('shipment', fn ($query) => $query->where('shipment_reference', 'MAN-ITEMS'))
        ->with(['packageItems.product', 'packageItems.shipmentItem'])
        ->firstOrFail();

    $lines = $package->packageItems->sortBy('id')->map(CustomsItem::fromPackageItem(...))->values();

    expect($lines->map(fn (CustomsItem $line): array => [
        $line->description, $line->quantity, $line->unitValue, $line->hsTariffNumber, $line->countryOfOrigin, $line->merchantProductId,
    ])->all())->toBe([
        ['Mug', 2, 12.5, '6912.00', 'PT', 'MUG-01'],
        ['Replacement lid', 1, 3.25, '3923.50', 'US', 'LID-SAMPLE'],
    ]);
});

it('keeps an entered Shipment value over the items\' total', function (): void {
    $component = Livewire::test(ManualShip::class)->fillForm(manualShipItemsForm(['value' => '40.00']));
    fillManualShipItems($component, $this->catalogProduct);
    $component->call('ship')->assertHasNoFormErrors();

    expect((float) Shipment::where('shipment_reference', 'MAN-ITEMS')->value('value'))->toBe(40.0);
});

it('refuses a typed-in SKU that is already in the catalog', function (): void {
    Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm())
        ->set('data.items', [['product_id' => null, 'description' => 'Another mug', 'sku' => 'MUG-01', 'quantity' => 1]])
        ->call('ship')
        ->assertHasFormErrors(['items.0.sku']);

    expect(Shipment::where('shipment_reference', 'MAN-ITEMS')->exists())->toBeFalse()
        ->and(Product::where('sku', 'MUG-01')->count())->toBe(1);
});

it('auto ships a Package holding the entered items', function (): void {
    auth()->user()->update(['auto_ship_enabled' => true]);

    $workflow = Mockery::mock(PackageShippingWorkflow::class);
    $workflow->shouldReceive('autoShip')
        ->once()
        ->withArgs(function (Package $package): bool {
            expect($package->packageItems()->sum('quantity'))->toBe(3);

            return true;
        })
        ->andReturn(PackageShippingResult::attendedSelectionRequired('Attended Shipping Required', 'Choose a rate.'));
    app()->instance(PackageShippingWorkflow::class, $workflow);

    $component = Livewire::test(ManualShip::class)->fillForm(manualShipItemsForm());
    fillManualShipItems($component, $this->catalogProduct);
    $component->call('ship')->assertNotified('Attended Shipping Required');
});

it('sends the items to Pack to be checked, with scan-to-add off for the Shipment', function (): void {
    Setting::create(['key' => 'scan_to_add_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $component = Livewire::test(ManualShip::class)->fillForm(manualShipItemsForm());
    fillManualShipItems($component, $this->catalogProduct);
    $component->call('ship')->assertHasNoFormErrors();

    $shipment = Shipment::where('shipment_reference', 'MAN-ITEMS')->firstOrFail();
    $component->assertRedirect('/pack/'.$shipment->id);

    // Pack records what is scanned, so the draft starts empty.
    expect(PackageItem::whereHas('package', fn ($query) => $query->where('shipment_id', $shipment->id))->exists())->toBeFalse();

    $pack = Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
        ->assertSet('scanToAddMode', false);

    expect(collect($pack->get('packingItems'))->map(fn (array $item): array => [$item['sku'], $item['quantity'], $item['packed']])->all())
        ->toBe([['MUG-01', 2, 0], ['LID-SAMPLE', 1, 0]]);
});

it('takes no items while the setting is off', function (): void {
    Setting::where('key', 'manual_ship_items_enabled')->update(['value' => '0']);
    app(SettingsService::class)->clearCache();

    Livewire::test(ManualShip::class)
        ->assertSet('itemsEnabled', false)
        ->assertDontSeeText('Add item')
        ->fillForm(manualShipItemsForm(['value' => '99']))
        ->set('data.items', [['product_id' => null, 'description' => 'Lid', 'sku' => 'LID-OFF', 'quantity' => 1]])
        ->call('ship')
        ->assertHasNoFormErrors();

    $shipment = Shipment::where('shipment_reference', 'MAN-ITEMS')->firstOrFail();

    expect($shipment->shipmentItems()->exists())->toBeFalse()
        ->and($shipment->value)->toBeNull()
        ->and(Product::where('sku', 'LID-OFF')->exists())->toBeFalse();
});

it('saves the Manual Ship Items setting', function (): void {
    Setting::where('key', 'manual_ship_items_enabled')->delete();
    app(SettingsService::class)->clearCache();

    expect(app(SettingsService::class)->manualShipItemsEnabled())->toBeFalse();

    Livewire::test(Settings::class)
        ->fillForm(['manual_ship_items_enabled' => true])
        ->call('save');

    app(SettingsService::class)->clearCache();

    expect(app(SettingsService::class)->manualShipItemsEnabled())->toBeTrue();
});

describe('items of a Shipment with a source', function (): void {
    it('are read-only while the source imports items', function (): void {
        $source = DataSource::factory()->create(['name' => 'Warehouse ERP']);
        $shipment = Shipment::factory()->create(['data_source_id' => $source->id]);
        $item = ShipmentItem::factory()->for($shipment)->create();

        Livewire::test(ShipmentItemsRelationManager::class, ['ownerRecord' => $shipment, 'pageClass' => EditShipment::class])
            ->assertSee('These items come from Warehouse ERP')
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertActionHidden(TestAction::make('edit')->table($item))
            ->assertActionHidden(TestAction::make('delete')->table($item))
            ->assertActionHidden(TestAction::make('delete')->table()->bulk());
    });

    it('stay editable when the source does not import items, or there is no source', function (?array $sourceSettings): void {
        $shipment = Shipment::factory()->create([
            'data_source_id' => $sourceSettings === null ? null : DataSource::factory()->create(['settings' => $sourceSettings])->id,
        ]);

        $item = ShipmentItem::factory()->for($shipment)->create();

        Livewire::test(ShipmentItemsRelationManager::class, ['ownerRecord' => $shipment, 'pageClass' => EditShipment::class])
            ->assertDontSee('These items come from')
            ->assertActionVisible(TestAction::make('create')->table())
            ->assertActionVisible(TestAction::make('edit')->table($item));
    })->with([
        'item import off' => [['shipment_items_enabled' => false]],
        'no source' => [null],
    ]);
});

it('has Pack refuse a short count of the entered items', function (): void {
    Setting::create(['key' => 'scan_to_add_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $component = Livewire::test(ManualShip::class)->fillForm(manualShipItemsForm());
    fillManualShipItems($component, $this->catalogProduct);
    $component->call('ship')->assertHasNoFormErrors();

    $shipment = Shipment::where('shipment_reference', 'MAN-ITEMS')->firstOrFail();
    $pack = Livewire::test(Pack::class, ['shipment_id' => $shipment->id]);

    // One mug of two.
    $packingItems = collect($pack->get('packingItems'))
        ->map(fn (array $item): array => [...$item, 'packed' => $item['sku'] === 'MUG-01' ? 1 : $item['quantity']])
        ->all();

    $pack->call('ship', $packingItems, null, '3', '10', '8', '6', false)
        ->assertNotified('Not Ready');

    expect($shipment->packages()->first()->status->value)->toBe('unshipped');
});

it('searches and accepts only the default client\'s active Products', function (): void {
    $otherClient = Client::factory()->create();
    $otherClientsMug = Product::factory()->create(['client_id' => $otherClient->id, 'sku' => 'MUG-02', 'name' => 'Mug']);
    Product::factory()->create(['client_id' => $this->client->id, 'sku' => 'MUG-03', 'name' => 'Old mug', 'active' => false]);

    $component = Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm())
        ->set('data.items', [['product_id' => null, 'quantity' => 1]]);

    $results = searchManualShipCatalog($component, 'Mug');

    expect(collect($results)->pluck('label')->all())->toBe(['MUG-01 — Mug']);

    // An ID sent from the browser is checked too.
    $component->set('data.items.0.product_id', (string) $otherClientsMug->id)
        ->call('ship')
        ->assertHasFormErrors(['items.0.product_id']);

    expect(Shipment::where('shipment_reference', 'MAN-ITEMS')->exists())->toBeFalse();

    // The service checks on its own, for any caller but this form.
    $shipment = Shipment::factory()->create(['client_id' => $this->client->id]);

    expect(fn () => app(ManualShipContents::class)->record($shipment, [new ManualShipLine(productId: $otherClientsMug->id, quantity: 1)]))
        ->toThrow(ValidationException::class);
});

it('declares a catalog line\'s Product details, not details sent with it', function (): void {
    $component = Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm())
        ->set('data.items', [['product_id' => null, 'quantity' => 1]])
        ->set('data.items.0.product_id', (string) $this->catalogProduct->id)
        ->set('data.items.0.description', 'Gold bar')
        ->set('data.items.0.sku', 'GOLD')
        ->set('data.items.0.hs_tariff_number', '7108.13')
        ->call('ship')
        ->assertHasNoFormErrors();

    $item = Shipment::where('shipment_reference', 'MAN-ITEMS')->firstOrFail()->shipmentItems()->sole();

    expect($item->product_id)->toBe($this->catalogProduct->id)
        ->and($this->catalogProduct->fresh()->only(['name', 'sku', 'hs_tariff_number']))
        ->toBe(['name' => 'Mug', 'sku' => 'MUG-01', 'hs_tariff_number' => '6912.00'])
        ->and(Product::where('sku', 'GOLD')->exists())->toBeFalse();
});

it('clears a line whose catalog pick is removed, so it can be typed in', function (): void {
    Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm())
        ->set('data.items', [['product_id' => null, 'quantity' => 1]])
        ->set('data.items.0.product_id', (string) $this->catalogProduct->id)
        ->assertSet('data.items.0.sku', 'MUG-01')
        ->set('data.items.0.product_id', null)
        ->assertSet('data.items.0.sku', null)
        ->assertSet('data.items.0.description', null)
        ->assertSet('data.items.0.value', null);
});

it('refuses a typed-in SKU of an inactive Product, saying so', function (): void {
    Product::factory()->create(['client_id' => $this->client->id, 'sku' => 'OLD-01', 'active' => false]);

    Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm())
        ->set('data.items', [['product_id' => null, 'description' => 'Old thing', 'sku' => 'OLD-01', 'quantity' => 1]])
        ->call('ship')
        ->assertHasFormErrors(['items.0.sku'])
        ->assertSeeText('This SKU belongs to an inactive product.');
});

it('records a value entered with no items', function (): void {
    Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm(['value' => '50.00']))
        ->call('ship')
        ->assertHasNoFormErrors();

    expect((float) Shipment::where('shipment_reference', 'MAN-ITEMS')->value('value'))->toBe(50.0);
});

it('leaves the Shipment\'s value unknown when any item has no value', function (): void {
    Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm())
        ->set('data.items', [
            ['product_id' => null, 'description' => 'Valued', 'sku' => 'V-1', 'quantity' => 1, 'value' => '10'],
            ['product_id' => null, 'description' => 'Unvalued', 'sku' => 'U-1', 'quantity' => 1],
        ])
        ->call('ship')
        ->assertHasNoFormErrors();

    expect(Shipment::where('shipment_reference', 'MAN-ITEMS')->value('value'))->toBeNull();
});

it('refuses items totaling more than a Shipment\'s value holds', function (): void {
    Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm())
        ->set('data.items', [['product_id' => null, 'description' => 'Painting', 'sku' => 'ART-1', 'quantity' => 2, 'value' => '600000']])
        ->call('ship')
        ->assertHasErrors(['data.value']);

    expect(Shipment::where('shipment_reference', 'MAN-ITEMS')->exists())->toBeFalse()
        ->and(Product::where('sku', 'ART-1')->exists())->toBeFalse();
});

it('lets a failed Auto Ship be retried with its typed-in items', function (): void {
    auth()->user()->update(['auto_ship_enabled' => true]);

    $workflow = Mockery::mock(PackageShippingWorkflow::class);
    $workflow->shouldReceive('autoShip')
        ->twice()
        ->andReturn(PackageShippingResult::failed('No Rates', 'No rates were returned.'));
    app()->instance(PackageShippingWorkflow::class, $workflow);

    $component = Livewire::test(ManualShip::class)->fillForm(manualShipItemsForm());
    fillManualShipItems($component, $this->catalogProduct);
    $component->call('ship')->assertNotified('No Rates');

    $lid = Product::where('sku', 'LID-SAMPLE')->sole();
    $component->assertSet('data.items.1.product_id', $lid->id)
        ->call('ship')
        ->assertHasNoFormErrors()
        ->assertNotified('No Rates');

    expect(Product::where('sku', 'LID-SAMPLE')->count())->toBe(1);
});

it('labels a catalog Product without breaking its characters', function (): void {
    Product::factory()->create(['client_id' => $this->client->id, 'sku' => '€-1', 'name' => 'Кружка Д']);

    $component = Livewire::test(ManualShip::class)
        ->fillForm(manualShipItemsForm())
        ->set('data.items', [['product_id' => null, 'quantity' => 1]]);

    $results = searchManualShipCatalog($component, '€');

    expect(collect($results)->pluck('label')->all())->toBe(['€-1 — Кружка Д']);
});
