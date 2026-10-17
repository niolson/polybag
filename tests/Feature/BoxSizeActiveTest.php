<?php

use App\Contracts\PackageDraftWorkflow;
use App\DataTransferObjects\PackageDrafts\Measurements;
use App\DataTransferObjects\PackageDrafts\PackageDraftInput;
use App\Enums\Role;
use App\Filament\Pages\ManualShip;
use App\Filament\Pages\Pack;
use App\Filament\Pages\PrintBoxSizeBarcodes;
use App\Filament\Resources\BoxSizeResource\Pages\EditBoxSize;
use App\Filament\Resources\BoxSizeResource\Pages\ListBoxSizes;
use App\Models\BoxSize;
use App\Models\Shipment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('leaves an inactive box size off the barcode sheet', function (): void {
    $active = BoxSize::factory()->create();
    BoxSize::factory()->inactive()->create();

    $printed = Livewire::test(PrintBoxSizeBarcodes::class)->get('boxSizes');

    expect($printed->pluck('id')->all())->toBe([$active->id]);
});

it('gives Pack the active box sizes to scan and the inactive ones to report', function (): void {
    BoxSize::factory()->create(['code' => 'A1']);
    $inactive = BoxSize::factory()->inactive()->create(['code' => 'Z9', 'label' => 'FedEx Tube']);

    $component = Livewire::test(Pack::class, ['shipment_id' => Shipment::factory()->create()->id])
        ->assertSet('inactiveBoxSizes.Z9.id', $inactive->id)
        ->assertSet('inactiveBoxSizes.Z9.label', 'FedEx Tube')
        ->assertSeeHtml('Box Size Inactive');

    expect(array_keys($component->get('boxSizes')))->toBe(['A1'])
        ->and(array_keys($component->get('inactiveBoxSizes')))->toBe(['Z9']);
});

it('offers only active box sizes on Manual Ship', function (): void {
    $active = BoxSize::factory()->create();
    $inactive = BoxSize::factory()->inactive()->create();

    $options = (new ManualShip)->getBoxSizeOptions();

    expect(array_keys($options))->toBe([$active->id]);
});

it('keeps accepting a box size on a Package Draft after it is deactivated', function (): void {
    $boxSize = BoxSize::factory()->create();
    $shipment = Shipment::factory()->create();
    $workflow = app(PackageDraftWorkflow::class);

    $workflow->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(3, 10, 8, 6),
        boxSizeId: $boxSize->id,
        items: [],
    ));

    $boxSize->update(['active' => false]);

    $resaved = $workflow->saveForShipment($shipment, new PackageDraftInput(
        measurements: new Measurements(4, 10, 8, 6),
        boxSizeId: $boxSize->id,
        items: [],
    ));

    expect($resaved->boxSizeId)->toBe($boxSize->id);
});

it('activates and deactivates box sizes in bulk without touching their dimensions', function (): void {
    $boxes = BoxSize::factory()->count(3)->create(['length' => 12, 'width' => 9, 'height' => 3]);

    Livewire::test(ListBoxSizes::class)
        ->selectTableRecords($boxes->pluck('id')->all())
        ->callAction(TestAction::make('deactivate')->table()->bulk());

    expect(BoxSize::where('active', true)->count())->toBe(0);

    Livewire::test(ListBoxSizes::class)
        ->set('activeTab', 'inactive')
        ->selectTableRecords([$boxes[0]->id])
        ->callAction(TestAction::make('activate')->table()->bulk());

    $boxes[0]->refresh();

    expect($boxes[0]->active)->toBeTrue()
        ->and((float) $boxes[0]->length)->toBe(12.0)
        ->and(BoxSize::where('active', true)->count())->toBe(1);
});

it('opens the box size list on active boxes, smallest first', function (): void {
    $large = BoxSize::factory()->create(['length' => 10, 'width' => 10, 'height' => 10]);
    $small = BoxSize::factory()->create(['length' => 6, 'width' => 4, 'height' => 4]);
    $inactive = BoxSize::factory()->inactive()->create();

    Livewire::test(ListBoxSizes::class)
        ->assertSet('activeTab', 'active')
        ->assertCanSeeTableRecords([$small, $large], inOrder: true)
        ->assertCanNotSeeTableRecords([$inactive])
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$small, $large])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$small, $large, $inactive]);
});

it('filters the box size list by packaging', function (): void {
    $own = BoxSize::factory()->create(['carrier_packaging' => null]);
    $inactive = BoxSize::factory()->inactive()->create(['carrier_packaging' => null]);

    Livewire::test(ListBoxSizes::class)
        ->set('activeTab', 'all')
        ->filterTable('carrier', 'own')
        ->assertCanSeeTableRecords([$own, $inactive]);
});

it('deactivates a box size from its edit form', function (): void {
    $boxSize = BoxSize::factory()->create();

    Livewire::test(EditBoxSize::class, ['record' => $boxSize->id])
        ->fillForm(['active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($boxSize->refresh()->active)->toBeFalse();
});
