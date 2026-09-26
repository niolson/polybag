<?php

use App\Enums\PostageSourceKind;
use App\Enums\UnlistedServices;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
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

function removeAmazonCarrierMigration(): object
{
    return require database_path('migrations/2026_09_26_145829_remove_amazon_carrier.php');
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
            ->assertFormFieldHidden('unlisted_services')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        expect($method->fresh()->allowsSource(PostageSourceKind::Amazon))->toBeTrue()
            ->and($method->fresh()->allowsUnlistedServices(PostageSourceKind::Amazon))->toBeFalse();
    });

    it('takes only none until 13', function (): void {
        expect(fn () => ShippingMethodPostageSource::factory()->amazon()->any()->create())
            ->toThrow(DomainException::class);

        $row = ShippingMethodPostageSource::factory()->amazon()->create();

        expect($row->unlisted_services)->toBe(UnlistedServices::None);
    });

    it('is described as every Amazon service, not the listed ones', function (): void {
        $method = ShippingMethod::factory()->create();
        ShippingMethodPostageSource::factory()->amazon()->for($method)->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->assertSee('All Amazon services (approvals gate automation)');
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

        removeAmazonCarrierMigration()->up();

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

        expect(fn () => removeAmazonCarrierMigration()->up())
            ->toThrow(RuntimeException::class, "shipping_rules #{$rule->id}; package_labels #{$label->id}");

        expect(DB::table('carriers')->where('id', $carrierId)->exists())->toBeTrue();
    });

    it('does nothing on an install without the carrier', function (): void {
        $carriers = DB::table('carriers')->count();

        removeAmazonCarrierMigration()->up();

        expect(DB::table('carriers')->count())->toBe($carriers);
    });
});
