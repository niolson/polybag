<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
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

it('sends an empty draft back to packing instead of offering to ship it', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $package = viewPackageEmptyDraft();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('continuePacking')
        ->assertActionHasUrl('continuePacking', '/pack/'.$package->shipment_id)
        ->assertActionHidden('buyAndPrintLabel')
        ->assertActionHidden('ship');
});

it('sends a measured draft with items still to pack back to packing', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    $package = viewPackageReadyDraft();
    ShipmentItem::factory()->create([
        'shipment_id' => $package->shipment_id,
        'product_id' => Product::factory(),
        'quantity' => 2,
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionVisible('continuePacking')
        ->assertActionHidden('buyAndPrintLabel')
        ->assertActionHidden('ship');
});

it('offers a shipper only the unattended purchase for a ready draft', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));
    $package = viewPackageReadyDraft();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionHidden('continuePacking')
        ->assertActionVisible('buyAndPrintLabel')
        ->assertActionHidden('ship');
});

it('offers a manager both the unattended purchase and the Ship page for a ready draft', function (Role $role): void {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $package = viewPackageReadyDraft();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionHidden('continuePacking')
        ->assertActionVisible('buyAndPrintLabel')
        ->assertActionVisible('ship')
        ->assertActionHasUrl('ship', '/ship/'.$package->id);
})->with([Role::Manager, Role::Admin]);

it('offers none of the purchase actions for a shipped package', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $package = Package::factory()->shipped()->create();

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertActionHidden('continuePacking')
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
        ->assertActionHidden('continuePacking');
});
