<?php

use App\Enums\PostageSourceKind;
use App\Enums\UnlistedServices;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\CarrierServicesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\PostageSourcesRelationManager;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
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

    it('opens the edit modal with the toggle on when the row allows any service', function (): void {
        $method = ShippingMethod::factory()->create();
        $row = ShippingMethodPostageSource::factory()->amazon()->any()->for($method)->create();
        $listed = ShippingMethodPostageSource::factory()->amazon()->for(ShippingMethod::factory())->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->mountAction(TestAction::make(EditAction::class)->table($row))
            ->assertSchemaStateSet(['unlisted_services' => true]);

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $listed->shippingMethod,
            'pageClass' => EditShippingMethod::class,
        ])
            ->mountAction(TestAction::make(EditAction::class)->table($listed))
            ->assertSchemaStateSet(['unlisted_services' => false]);
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
