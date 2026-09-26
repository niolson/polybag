<?php

use App\Enums\PostageSourceKind;
use App\Enums\UnlistedServices;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\CarrierServicesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\PostageSourcesRelationManager;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingRule;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| carrier-catalog-reset/12 — a method asks Amazon through its source policy
|--------------------------------------------------------------------------
|
| A shipping method allows Amazon Buy Shipping with an `amazon` row in its
| postage-source table, and the `Amazon` carrier with its
| `AMAZON_BUY_SHIPPING` hook row goes. Rating is covered in
| SourceFirstRatingTest.
|
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

function runRemoveAmazonCarrierMigration(): void
{
    $migration = require database_path('migrations/2026_09_26_145829_remove_amazon_carrier.php');

    $migration->up();
}

/**
 * The `Amazon` carrier and its hook row, as installs have them before the
 * migration. Written directly: the reference-data sync no longer seeds them.
 *
 * @return array{0: int, 1: int}
 */
function legacyAmazonHookRow(): array
{
    $carrierId = DB::table('carriers')->insertGetId([
        'name' => 'Amazon',
        'active' => true,
        'is_system' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $serviceId = DB::table('carrier_services')->insertGetId([
        'carrier_id' => $carrierId,
        'service_code' => 'AMAZON_BUY_SHIPPING',
        'name' => 'Amazon Buy Shipping rates',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$carrierId, $serviceId];
}

describe('the amazon row', function (): void {
    it('lets an Admin allow Amazon Buy Shipping on the method page', function (): void {
        $method = ShippingMethod::factory()->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->fillForm(['source_kind' => PostageSourceKind::Amazon->value])
            ->assertFormFieldVisible('unlisted_services')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        expect($method->fresh()->allowsSource(PostageSourceKind::Amazon))->toBeTrue()
            ->and($method->fresh()->allowsUnlistedServices(PostageSourceKind::Amazon))->toBeFalse();
    });

    it('defaults to services on this method, and takes any service (carrier-catalog-reset/13)', function (): void {
        $row = ShippingMethodPostageSource::factory()->amazon()->create();
        $any = ShippingMethodPostageSource::factory()->amazon()->any()->create();

        expect($row->unlisted_services)->toBe(UnlistedServices::None)
            ->and($any->allowsUnlistedServices())->toBeTrue();
    });

    it('lets an Admin allow Amazon any service on the method page', function (): void {
        $method = ShippingMethod::factory()->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->fillForm(['source_kind' => PostageSourceKind::Amazon->value, 'unlisted_services' => true])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        expect($method->fresh()->allowsUnlistedServices(PostageSourceKind::Amazon))->toBeTrue();
    });

    it('is described as listed services only, or any service', function (): void {
        $listed = ShippingMethod::factory()->create();
        ShippingMethodPostageSource::factory()->amazon()->for($listed)->create();
        $any = ShippingMethod::factory()->create();
        ShippingMethodPostageSource::factory()->amazon()->any()->for($any)->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $listed,
            'pageClass' => EditShippingMethod::class,
        ])
            ->assertSee('Listed services only')
            ->assertDontSee('approvals');

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $any,
            'pageClass' => EditShippingMethod::class,
        ])
            ->assertSee('Any service');
    });

    it('cannot be added, changed or removed by a Manager', function (): void {
        $this->actingAs(User::factory()->manager()->create());
        $method = ShippingMethod::factory()->create();
        $amazon = ShippingMethodPostageSource::factory()->amazon()->for($method)->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->assertCanSeeTableRecords([$amazon])
            ->assertActionHidden(TestAction::make(CreateAction::class)->table())
            ->assertActionHidden(TestAction::make(EditAction::class)->table($amazon))
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($amazon));
    });
});

describe('the migration', function (): void {
    it('gives each method that listed the hook row an amazon row, and removes the carrier', function (): void {
        [$carrierId, $serviceId] = legacyAmazonHookRow();
        $ground = CarrierService::factory()->uspsGroundAdvantage()->for(Carrier::factory()->usps())->create();
        $asking = ShippingMethod::factory()->create();
        $asking->carrierServices()->attach([$ground->id, $serviceId]);
        $alreadyAllowed = ShippingMethod::factory()->create();
        $alreadyAllowed->carrierServices()->attach($serviceId);
        ShippingMethodPostageSource::factory()->amazon()->for($alreadyAllowed)->create();
        $other = ShippingMethod::factory()->create();
        $other->carrierServices()->attach($ground->id);

        runRemoveAmazonCarrierMigration();

        expect($asking->fresh()->allowsSource(PostageSourceKind::Amazon))->toBeTrue()
            ->and($asking->fresh()->carrierServices->pluck('id')->all())->toBe([$ground->id])
            ->and($alreadyAllowed->postageSources()->where('source_kind', PostageSourceKind::Amazon)->count())->toBe(1)
            ->and($other->fresh()->allowsSource(PostageSourceKind::Amazon))->toBeFalse()
            ->and(DB::table('carriers')->where('id', $carrierId)->exists())->toBeFalse()
            ->and(DB::table('carrier_services')->where('id', $serviceId)->exists())->toBeFalse();
    });

    it('refuses, naming the rows, while a rule or label still names the carrier', function (): void {
        [$carrierId, $serviceId] = legacyAmazonHookRow();
        $rule = ShippingRule::factory()->create(['carrier_service_id' => $serviceId]);
        $label = PackageLabel::factory()->for(Package::factory())->create(['normalized_carrier_id' => $carrierId]);

        expect(fn () => runRemoveAmazonCarrierMigration())
            ->toThrow(RuntimeException::class, "shipping_rules #{$rule->id}; package_labels #{$label->id}");

        expect(DB::table('carriers')->where('id', $carrierId)->exists())->toBeTrue();
    });

    it('does nothing on an install without the carrier', function (): void {
        $carriers = DB::table('carriers')->count();

        runRemoveAmazonCarrierMigration();

        expect(DB::table('carriers')->count())->toBe($carriers);
    });
});

describe('the method\'s carrier services', function (): void {
    it('shows a service as active only while it and its carrier are', function (): void {
        $method = ShippingMethod::factory()->create();
        $active = CarrierService::factory()->uspsGroundAdvantage()->create();
        $inactive = CarrierService::factory()->create(['active' => false]);
        $inactiveCarrier = CarrierService::factory()->create();
        $inactiveCarrier->carrier->update(['active' => false]);
        $method->carrierServices()->attach([$active->id, $inactive->id, $inactiveCarrier->id]);

        Livewire::test(CarrierServicesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->assertTableColumnStateSet('active', true, $active)
            ->assertTableColumnStateSet('active', false, $inactive)
            ->assertTableColumnStateSet('active', false, $inactiveCarrier);
    });
});
