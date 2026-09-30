<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageDraftState;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Filament\Resources\PackageResource\RelationManagers\PackageItemsRelationManager;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

function viewPackageReadyDraft(): Package
{
    return Package::factory()->for(Shipment::factory())->create([
        'status' => PackageStatus::Unshipped,
        'weight' => 2.5,
        'length' => 10,
        'width' => 8,
        'height' => 6,
        'tracking_number' => null,
        'carrier' => null,
    ]);
}

function viewPackageEmptyDraft(): Package
{
    return Package::factory()->for(Shipment::factory())->create([
        'status' => PackageStatus::Unshipped,
        'box_size_id' => null,
        'weight' => null,
        'length' => null,
        'width' => null,
        'height' => null,
        'tracking_number' => null,
        'carrier' => null,
    ]);
}

it('offers only Pack for an empty draft', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $package = viewPackageEmptyDraft();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('pack')
        ->assertActionHasUrl('pack', '/pack/'.$package->shipment_id)
        ->assertActionHidden('buyAndPrintLabel')
        ->assertActionHidden('ship');
});

it('offers only Pack for a measured draft with items still to pack', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $package = viewPackageReadyDraft();
    ShipmentItem::factory()->create([
        'shipment_id' => $package->shipment_id,
        'product_id' => Product::factory(),
        'quantity' => 2,
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('pack')
        ->assertActionHidden('buyAndPrintLabel')
        ->assertActionHidden('ship');
});

it('offers a shipper Pack and the unattended purchase for a ready draft', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $package = viewPackageReadyDraft();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('pack')
        ->assertActionVisible('buyAndPrintLabel')
        ->assertActionHidden('ship');
});

it('offers a manager Pack, the Ship page and the unattended purchase for a ready draft', function (Role $role): void {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $package = viewPackageReadyDraft();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('pack')
        ->assertActionVisible('buyAndPrintLabel')
        ->assertActionVisible('ship')
        ->assertActionHasUrl('ship', '/ship/'.$package->id);
})->with([Role::Manager, Role::Admin]);

it('orders a ready draft\'s actions Pack, Ship, Buy and print label, then Edit in the menu', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $package = viewPackageReadyDraft();

    $page = Livewire::test(ViewPackage::class, ['record' => $package->id])->instance();
    expect($page)->toBeInstanceOf(ViewPackage::class);

    $visible = collect($page instanceof ViewPackage ? $page->getCachedHeaderActions() : [])
        ->flatMap(fn (Action|ActionGroup $action): array => $action instanceof ActionGroup ? $action->getFlatActions() : [$action])
        ->filter(fn (Action $action): bool => $action->isVisible())
        ->map(fn (Action $action): string => $action->getName())
        ->values()
        ->all();

    expect($visible)->toBe(['pack', 'ship', 'buyAndPrintLabel', 'edit']);
});

it('offers none of the purchase actions for a shipped package', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $package = Package::factory()->shipped()->create();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionHidden('pack')
        ->assertActionHidden('buyAndPrintLabel')
        ->assertActionHidden('ship');
});

it('shows the stored weight and when it was saved before buying', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $package = viewPackageReadyDraft();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->mountAction('buyAndPrintLabel')
        ->assertMountedActionModalSee(['2.50 lbs', '10 × 8 × 6 in', 'Last saved']);
});

it('buys unattended with the workstation printer settings and keeps the package on failure', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $package = viewPackageReadyDraft();

    $mock = Mockery::mock(PackageShippingWorkflow::class);
    $mock->shouldReceive('autoShip')
        ->once()
        ->withArgs(fn (Package $shipped, PackageAutoShippingRequest $request): bool => $shipped->is($package)
            && $request->labelFormat === 'zpl'
            && $request->labelDpi === 300
            && $request->hasReportPrinter === true
            && $request->cleanupOnFailure === false
            && $request->userId === auth()->id())
        ->andReturn(PackageShippingResult::failed('Carrier Error', 'No rates available.'));
    app()->instance(PackageShippingWorkflow::class, $mock);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction('buyAndPrintLabel', arguments: ['labelFormat' => 'zpl', 'labelDpi' => 300, 'hasReportPrinter' => true])
        ->assertNotified('Carrier Error')
        ->assertNoRedirect();

    expect(Package::find($package->id))->not->toBeNull();
});

it('buys a PDF at the default DPI when the browser sends something unknown', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $package = viewPackageReadyDraft();

    $mock = Mockery::mock(PackageShippingWorkflow::class);
    $mock->shouldReceive('autoShip')
        ->once()
        ->withArgs(fn (Package $shipped, PackageAutoShippingRequest $request): bool => $request->labelFormat === 'pdf'
            && $request->labelDpi === null
            && $request->hasReportPrinter === false)
        ->andReturn(PackageShippingResult::failed('Carrier Error', 'No rates available.'));
    app()->instance(PackageShippingWorkflow::class, $mock);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction('buyAndPrintLabel', arguments: ['labelFormat' => 'exe', 'labelDpi' => 9999]);
});

it('sends the packer to the Ship page when the purchase needs a person to choose', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $package = viewPackageReadyDraft();

    $mock = Mockery::mock(PackageShippingWorkflow::class);
    $mock->shouldReceive('autoShip')->once()->andReturn(PackageShippingResult::shippingMethodRequired());
    app()->instance(PackageShippingWorkflow::class, $mock);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction('buyAndPrintLabel', arguments: ['labelFormat' => 'pdf'])
        ->assertNotified('Shipping Method Required')
        ->assertRedirect('/ship/'.$package->id);
});

it('prints the label it bought', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $package = viewPackageReadyDraft();

    $response = new ShipResponse(
        success: true,
        trackingNumber: '9400100000000000000000',
        cost: 5.25,
        carrier: 'USPS',
        service: 'Ground Advantage',
        labelData: base64_encode('%PDF-label'),
        labelOrientation: 'portrait',
        labelFormat: 'pdf',
    );

    $mock = Mockery::mock(PackageShippingWorkflow::class);
    $mock->shouldReceive('autoShip')->once()->andReturn(PackageShippingResult::shipped($response, null, $package));
    app()->instance(PackageShippingWorkflow::class, $mock);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction('buyAndPrintLabel', arguments: ['labelFormat' => 'pdf'])
        ->assertNotified('Label Bought')
        ->assertDispatched('print-label');
});

it('does not let a shipper buy for a shipment that has already shipped', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $package = viewPackageReadyDraft();
    Package::factory()->shipped()->for($package->shipment)->create();
    $package->shipment->update(['status' => ShipmentStatus::Shipped]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionHidden('buyAndPrintLabel')
        ->assertActionHidden('pack');
});

it('shows a draft the same status badge as the Packages list', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    Livewire::test(ViewPackage::class, ['record' => viewPackageEmptyDraft()->id])
        ->assertSee(PackageDraftState::Empty->getLabel());

    Livewire::test(ViewPackage::class, ['record' => viewPackageReadyDraft()->id])
        ->assertSee(PackageDraftState::Ready->getLabel());
});

it('lists the package items on the view page, read-only', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $package = viewPackageReadyDraft();
    $item = ShipmentItem::factory()->create(['shipment_id' => $package->shipment_id, 'quantity' => 1]);
    $packed = PackageItem::create([
        'package_id' => $package->id,
        'shipment_item_id' => $item->id,
        'product_id' => $item->product_id,
        'quantity' => 1,
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSeeHtml('wire:name="'.e(PackageItemsRelationManager::class).'"');

    Livewire::test(PackageItemsRelationManager::class, ['ownerRecord' => $package, 'pageClass' => ViewPackage::class])
        ->assertCanSeeTableRecords([$packed])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($packed));
});
