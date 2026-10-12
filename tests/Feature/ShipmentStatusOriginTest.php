<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\ShipmentReviewReason;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Filament\Pages\Pack;
use App\Filament\Pages\Ship;
use App\Filament\Resources\ShipmentResource;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Filament\Widgets\ExceptionsWidget;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\BatchLabelService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function anyRate(): RateResponse
{
    return new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 8.50);
}

/**
 * `order-reconciliation/02`: Shipment status `cancelled`, who set it, a
 * shipped-elsewhere tracking number, and a review flag a manager clears.
 */
describe('who set the status', function (): void {
    it('records PolyBag when a Label ships the order, and nothing once it reopens', function (): void {
        app(SettingsService::class)->set('packing_validation_enabled', false);
        $shipment = Shipment::factory()->create();
        ShipmentItem::factory()->for($shipment)->create();
        $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Shipped]);

        $shipment->updateShippedStatus();

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Shipped)
            ->and($fresh->status_origin)->toBe(ShipmentStatusOrigin::PolyBag)
            ->and($fresh->status_changed_at)->not->toBeNull();

        $package->update(['status' => PackageStatus::Unshipped]);
        $shipment->updateShippedStatus();

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Open)
            ->and($fresh->status_origin)->toBeNull();
    });

    it('leaves a cancelled order cancelled whatever its packages say', function (): void {
        $shipment = Shipment::factory()->cancelled()->create();
        Package::factory()->for($shipment)->create(['status' => PackageStatus::Shipped]);

        $shipment->updateShippedStatus();

        expect($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled);
    });
});

describe('a cancelled order', function (): void {
    it('is refused by the shipping workflow, attended and unattended, keeping the package', function (): void {
        $shipment = Shipment::factory()->cancelled()->create();
        $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
        $workflow = app(PackageShippingWorkflow::class);

        $attended = $workflow->ship($package, new PackageShippingRequest(selectedRate: anyRate()));
        $unattended = $workflow->autoShip($package, new PackageAutoShippingRequest(cleanupOnFailure: true));

        expect($attended->success)->toBeFalse()
            ->and($attended->title)->toBe('Order Cancelled')
            ->and($attended->leavePackageIntact)->toBeTrue()
            ->and($unattended->title)->toBe('Order Cancelled')
            ->and(Package::find($package->id))->not->toBeNull();
    });

    it('is refused when cancelled after the package was loaded', function (): void {
        $shipment = Shipment::factory()->create();
        $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
        $package->load('shipment');

        Shipment::whereKey($shipment->id)->update(['status' => ShipmentStatus::Cancelled]);

        expect(app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: anyRate()))->title)
            ->toBe('Order Cancelled');
    });

    it('is skipped by batch ship', function (): void {
        $shipment = Shipment::factory()->cancelled()->create();
        ShipmentItem::factory()->for($shipment)->create();

        $result = (new BatchLabelService)->validateShipmentsForBatch(collect([$shipment]));

        expect($result->eligible)->toBeEmpty()
            ->and($result->ineligible->first()['reason'])->toBe('Order cancelled');
    });

    it('turns the Ship page away before quoting', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $package = Package::factory()
            ->for(Shipment::factory()->cancelled())
            ->create(['status' => PackageStatus::Unshipped]);

        $workflow = Mockery::mock(PackageShippingWorkflow::class);
        $workflow->shouldNotReceive('prepareRates');
        app()->instance(PackageShippingWorkflow::class, $workflow);

        Livewire::test(Ship::class, ['package_id' => $package->id])
            ->assertNotified('Order Cancelled')
            ->assertRedirect();
    });

    it('cannot be packed, by anyone, and says when the source cancelled it', function (Role $role): void {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $shipment = Shipment::factory()->closedBySource()->create();

        Livewire::test(Pack::class, ['shipment_id' => $shipment->id])
            ->assertNotified('Order Cancelled')
            ->assertSet('shipment', null)
            ->assertRedirect('/pack');
    })->with([Role::User, Role::Manager]);
});

describe('a manager changing the status', function (): void {
    it('records the change as a manager\'s, with an audit row', function (): void {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $this->actingAs($manager);
        $shipment = Shipment::factory()->closedBySource()->create();

        Livewire::test(EditShipment::class, ['record' => $shipment->id])
            ->fillForm(['status' => ShipmentStatus::Open])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $shipment->fresh();

        expect($fresh->status)->toBe(ShipmentStatus::Open)
            ->and($fresh->status_origin)->toBe(ShipmentStatusOrigin::Manager);

        $audit = AuditLog::where('action', AuditAction::ShipmentUpdated)->sole();

        expect($audit->user_id)->toBe($manager->id)
            ->and($audit->old_values)->toBe(['status' => 'cancelled', 'status_origin' => 'source'])
            ->and($audit->new_values)->toBe(['status' => 'open', 'status_origin' => 'manager']);
    });

    it('leaves the origin alone when the status did not change', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $shipment = Shipment::factory()->closedBySource('shipped')->create();

        Livewire::test(EditShipment::class, ['record' => $shipment->id])
            ->fillForm(['external_tracking_number' => '1Z-SYNTHETIC-0001'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $shipment->fresh();

        expect($fresh->status_origin)->toBe(ShipmentStatusOrigin::Source)
            ->and($fresh->external_tracking_number)->toBe('1Z-SYNTHETIC-0001')
            ->and(AuditLog::where('action', AuditAction::ShipmentUpdated)->exists())->toBeFalse();
    });

    it('says the source closed the order', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $source = DataSource::factory()->create(['name' => 'Test Shop']);
        $shipment = Shipment::factory()->closedBySource()->create(['data_source_id' => $source->id]);

        Livewire::test(EditShipment::class, ['record' => $shipment->id])
            ->assertSee('Test Shop reported this order cancelled on')
            ->assertSee('Changing it here overrides the source.');

        expect(ShipmentResource::closedBySourceNotice(Shipment::factory()->cancelled()->create()))->toBeNull();
    });

    it('is not open to a shipper', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::User]));
        $shipment = Shipment::factory()->closedBySource()->create();

        Livewire::test(EditShipment::class, ['record' => $shipment->id])->assertForbidden();
    });
});

describe('the review flag', function (): void {
    it('shows on the Shipment and a manager clears it, audit-logged', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $shipment = Shipment::factory()->shipped()->flaggedForReview()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertSee('Cancelled after shipping')
            ->callAction('clearReviewFlag')
            ->assertNotified('Review flag cleared');

        $fresh = $shipment->fresh();

        expect($fresh->review_reason)->toBeNull()
            ->and($fresh->review_flagged_at)->toBeNull()
            ->and(AuditLog::where('action', AuditAction::ShipmentUpdated)->sole()->old_values)
            ->toBe(['review_reason' => 'cancelled_after_shipped']);
    });

    it('cannot be cleared by a shipper', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::User]));
        $shipment = Shipment::factory()->flaggedForReview()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertActionHidden('clearReviewFlag');

        expect($shipment->fresh()->review_reason)->toBe(ShipmentReviewReason::CancelledAfterShipped);
    });

    it('is counted on the Exceptions widget', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        Cache::flush();
        Shipment::factory()->flaggedForReview(ShipmentReviewReason::ClosedWithDraft)->create();
        Shipment::factory()->create();

        Livewire::test(ExceptionsWidget::class)
            ->assertSee('Flagged for Review')
            ->assertSee(ShipmentResource::flaggedForReviewUrl(), escape: false);

        expect(Cache::get('widget:exceptions:v4')['flagged_for_review'])->toBe(1);
    });
});

describe('the Shipments list', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('filters to flagged Shipments', function (): void {
        $flagged = Shipment::factory()->flaggedForReview()->create();
        $clear = Shipment::factory()->create();

        Livewire::test(ListShipments::class)
            ->filterTable('flagged_for_review')
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$clear]);
    });

    it('filters by who set the status', function (): void {
        $bySource = Shipment::factory()->closedBySource('shipped')->create();
        $byPolyBag = Shipment::factory()->shipped()->create();

        Livewire::test(ListShipments::class)
            ->filterTable('status_origin', ShipmentStatusOrigin::Source->value)
            ->assertCanSeeTableRecords([$bySource])
            ->assertCanNotSeeTableRecords([$byPolyBag]);
    });

    it('has a Cancelled tab', function (): void {
        $cancelled = Shipment::factory()->cancelled()->create();
        $open = Shipment::factory()->create();

        Livewire::withQueryParams(['status_tab' => ShipmentStatus::Cancelled->value])
            ->test(ListShipments::class)
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$open]);
    });
});

it('finds a Shipment by the tracking number it shipped elsewhere with', function (): void {
    expect(ShipmentResource::getGloballySearchableAttributes())->toContain('external_tracking_number');
});
