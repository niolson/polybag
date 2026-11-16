<?php

use App\Enums\PackageStatus;
use App\Enums\PickBatchStatus;
use App\Filament\Pages\EndOfDay;
use App\Filament\Pages\PrintPackSlips;
use App\Filament\Resources\LabelBatchResource;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Models\Carrier;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\PostageSources\UnresolvedPurchaseResolver;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Sidebar badges count work still to do, and are empty once it is done.
 */
beforeEach(function (): void {
    Cache::flush();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('counts pack slips not yet printed or out of date, leaving out those a pick batch holds', function (): void {
    expect(PrintPackSlips::getNavigationBadge())->toBeNull();

    Cache::flush();
    Shipment::factory()->count(2)->create();
    Shipment::factory()->create(['items_version' => 2, 'pack_slip_items_version' => 1]);
    Shipment::factory()->create(['items_version' => 1, 'pack_slip_items_version' => 1]);
    PickBatchShipment::factory()->create([
        'pick_batch_id' => PickBatch::factory(),
        'shipment_id' => Shipment::factory(),
    ]);

    expect(PrintPackSlips::getNavigationBadge())->toBe('3');
});

it('shows no pack slip count when slips print from pick batches', function (): void {
    Shipment::factory()->create();
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    app(SettingsService::class)->set('require_picking_before_shipping', true, 'boolean');

    expect(PrintPackSlips::getNavigationBadge())->toBeNull();
});

it('counts pick batches in progress', function (): void {
    PickBatch::factory()->count(2)->create(['status' => PickBatchStatus::InProgress]);
    PickBatch::factory()->completed()->create();
    PickBatch::factory()->cancelled()->create();

    expect(PickBatchResource::getNavigationBadge())->toBe('2');
});

it('counts label batches holding labels still to print', function (): void {
    $unprinted = LabelBatch::factory()->completed()->create();
    LabelBatchItem::factory()->success()->count(2)->create([
        'label_batch_id' => $unprinted->id,
        'package_id' => fn (): int => Package::factory()->shipped()->create()->id,
    ]);

    $printed = LabelBatch::factory()->completed()->create();
    LabelBatchItem::factory()->success()->create([
        'label_batch_id' => $printed->id,
        'package_id' => Package::factory()->shipped()->create(['label_printed_at' => now()])->id,
    ]);

    // Voided after the batch ran: neither printed nor printable.
    $voided = LabelBatch::factory()->completed()->create();
    LabelBatchItem::factory()->success()->create([
        'label_batch_id' => $voided->id,
        'package_id' => Package::factory()->create(['status' => PackageStatus::Unshipped])->id,
    ]);

    expect(LabelBatchResource::getNavigationBadge())->toBe('1')
        ->and(LabelBatchResource::getNavigationBadgeColor())->toBeNull();
});

it('counts and warns about a stalled label batch, even before any label succeeds', function (): void {
    LabelBatch::factory()->processing()->create(['updated_at' => now()->subMinutes(5)]);
    LabelBatch::factory()->processing()->create();

    expect(LabelBatchResource::getNavigationBadge())->toBe('1')
        ->and(LabelBatchResource::getNavigationBadgeColor())->toBe('warning')
        ->and(LabelBatchResource::getNavigationBadgeTooltip())->toContain('stalled');
});

it('counts packages with an unfinished label purchase, as the Packages filter does', function (): void {
    $unanswered = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    ShippingOffer::factory()->direct()->awaitingConfirmation()->count(2)->create(['package_id' => $unanswered->id]);

    $confirmedUnsaved = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    ShippingOffer::factory()->direct()->consumed()->create(['package_id' => $confirmedUnsaved->id]);

    // Bought again after a purchase that never answered: still needs a person.
    $reboughtAfterSilence = Package::factory()->shipped()->create();
    ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'package_id' => $reboughtAfterSilence->id,
        'consumed_at' => $reboughtAfterSilence->activeLabel->created_at->subMinute(),
    ]);

    $recorded = Package::factory()->shipped()->create();
    ShippingOffer::factory()->direct()->consumed()->create([
        'package_id' => $recorded->id,
        'consumed_at' => $recorded->activeLabel->created_at->subMinute(),
    ]);

    $declined = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    ShippingOffer::factory()->direct()->declined()->create(['package_id' => $declined->id]);

    $resolver = app(UnresolvedPurchaseResolver::class);

    expect($resolver->packagesNeedingAPersonCount())
        ->toBe($resolver->needingAPerson()->distinct()->count('package_id'))
        ->toBe(3)
        ->and(PackageResource::getNavigationBadge())->toBe('3')
        ->and(PackageResource::getNavigationBadgeColor())->toBe('warning');
});

it('counts labels a manifest would take today, and none once they are on one', function (): void {
    // A Wednesday morning, clear of the USPS cutoff and the weekend.
    $this->travelTo(now()->next('Wednesday')->setTime(10, 0));
    Carrier::factory()->usps()->create(['active' => true]);

    $onManifest = Package::factory()->usps()->create(['carrier_account_id' => null]);
    Package::factory()->usps()->count(2)->create(['carrier_account_id' => null]);
    Package::factory()->usps()->create(['carrier_account_id' => null, 'ship_date' => today()->subDay()]);

    $manifest = Manifest::create([
        'carrier' => 'USPS', 'manifest_number' => 'M1', 'manifest_date' => today()->toDateString(), 'package_count' => 1,
    ]);
    $onManifest->forceFill(['manifest_id' => $manifest->id])->save();

    expect(EndOfDay::getNavigationBadge())->toBe('2');

    Cache::flush();
    Package::query()->update(['manifest_id' => $manifest->id]);

    expect(EndOfDay::getNavigationBadge())->toBeNull();
});

it('leaves out labels dated after the ship date, and matches the page', function (): void {
    $this->travelTo(now()->next('Wednesday')->setTime(10, 0));
    Carrier::factory()->usps()->create(['active' => true]);

    Package::factory()->usps()->count(2)->create(['carrier_account_id' => null]);
    Package::factory()->usps()->create(['carrier_account_id' => null, 'ship_date' => today()->addDay()]);

    expect(EndOfDay::getNavigationBadge())->toBe('2');

    Livewire::test(EndOfDay::class)->assertSet('carrierSummary.0.unmanifested_count', 2);
});

it('counts only the person\'s own location when multi-location is on', function (): void {
    $this->travelTo(now()->next('Wednesday')->setTime(10, 0));
    config()->set('features.multi_location_enabled', true);
    Carrier::factory()->usps()->create(['active' => true]);

    $here = Location::factory()->create();
    $elsewhere = Location::factory()->create();
    $this->actingAs(User::factory()->admin()->create(['location_id' => $here->id]));

    Package::factory()->usps()->create(['carrier_account_id' => null, 'location_id' => $here->id]);
    Package::factory()->usps()->count(2)->create(['carrier_account_id' => null, 'location_id' => $elsewhere->id]);

    expect(EndOfDay::getNavigationBadge())->toBe('1');
});
