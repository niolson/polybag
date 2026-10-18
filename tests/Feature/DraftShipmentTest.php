<?php

use App\Enums\ShipmentStatus;
use App\Filament\Pages\ManualShip;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Widgets\StatsOverview;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create(['auto_ship_enabled' => false]));
});

it('leaves Manual Ship\'s shipment as a draft, never open', function (): void {
    Channel::factory()->create(['name' => 'Manual']);

    Livewire::test(ManualShip::class)
        ->fillForm([
            'shipment_reference' => 'MAN-DRAFT',
            'first_name' => 'Taylor',
            'last_name' => 'Jones',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'country' => 'US',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'shipping_method_id' => ShippingMethod::factory()->create()->id,
            'box_size_id' => BoxSize::factory()->create()->id,
            'weight' => 2.5,
            'height' => 10,
            'width' => 8,
            'length' => 6,
        ])
        ->call('ship')
        ->assertHasNoFormErrors();

    expect(Shipment::where('shipment_reference', 'MAN-DRAFT')->sole()->status)->toBe(ShipmentStatus::Draft)
        ->and(Shipment::where('status', ShipmentStatus::Open)->count())->toBe(0);
});

it('keeps a draft off the Shipments list, its All tab included', function (): void {
    $order = Shipment::factory()->create(['status' => ShipmentStatus::Open]);
    $draft = Shipment::factory()->create(['status' => ShipmentStatus::Draft]);

    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$order])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('does not count a draft among the open shipments', function (): void {
    Cache::flush();
    Shipment::factory()->count(2)->create(['status' => ShipmentStatus::Open]);
    Shipment::factory()->count(5)->create(['status' => ShipmentStatus::Draft]);

    $stats = (fn (): array => $this->getStats())->call(new StatsOverview);
    $open = collect($stats)->first(fn ($stat): bool => $stat->getLabel() === 'Open Shipments');

    expect($open->getValue())->toBe('2')
        ->and(Shipment::needingShippingMethod()->count())->toBe(0);
});

it('hides drafts through the withoutDrafts scope', function (): void {
    $shipped = Shipment::factory()->create(['status' => ShipmentStatus::Shipped]);
    Shipment::factory()->create(['status' => ShipmentStatus::Draft]);

    expect(Shipment::withoutDrafts()->pluck('id')->all())->toBe([$shipped->id]);
});

it('does not offer the draft status to choose or filter by', function (): void {
    expect(ShipmentStatus::selectable())->toBe([
        'open' => 'Open',
        'shipped' => 'Shipped',
        'cancelled' => 'Cancelled',
    ]);
});

it('stays a draft while no package has shipped', function (): void {
    $draft = Shipment::factory()->create(['status' => ShipmentStatus::Draft]);
    Package::factory()->create(['shipment_id' => $draft->id]);

    $draft->updateShippedStatus();

    expect($draft->fresh()->status)->toBe(ShipmentStatus::Draft);
});

it('becomes shipped, not open, once a label is bought', function (): void {
    $draft = Shipment::factory()->create(['status' => ShipmentStatus::Draft]);
    Package::factory()->shipped()->create(['shipment_id' => $draft->id]);

    $draft->updateShippedStatus();

    expect($draft->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('deletes a draft untouched for a day with its package, and nothing else', function (): void {
    $old = Shipment::factory()->create(['status' => ShipmentStatus::Draft, 'updated_at' => now()->subHours(25)]);
    $oldPackage = Package::factory()->create(['shipment_id' => $old->id]);

    $young = Shipment::factory()->create(['status' => ShipmentStatus::Draft, 'updated_at' => now()->subHours(2)]);

    $labelled = Shipment::factory()->create(['status' => ShipmentStatus::Draft, 'updated_at' => now()->subDays(3)]);
    $labelledPackage = Package::factory()->create(['shipment_id' => $labelled->id]);
    PackageLabel::factory()->create(['package_id' => $labelledPackage->id]);

    $oldOrder = Shipment::factory()->create(['status' => ShipmentStatus::Open, 'updated_at' => now()->subDays(30)]);

    $this->artisan('shipments:delete-abandoned-drafts')->assertSuccessful();

    expect(Shipment::find($old->id))->toBeNull()
        ->and(Package::find($oldPackage->id))->toBeNull()
        ->and(Shipment::find($young->id))->not->toBeNull()
        ->and(Shipment::find($labelled->id))->not->toBeNull()
        ->and(Shipment::find($oldOrder->id))->not->toBeNull();
});
