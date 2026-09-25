<?php

use App\Enums\PostageSourceKind;
use App\Filament\Resources\Carriers\Pages\EditCarrier;
use App\Filament\Resources\CarrierServiceResource\Pages\EditCarrierService;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\SourceServiceMapping;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('refuses a second Shopify row for the same carrier service', function (): void {
    $service = CarrierService::factory()->create();

    SourceServiceMapping::factory()->shopify()->create([
        'external_carrier_id' => 'usps',
        'external_service_id' => 'usps_ground_advantage',
        'carrier_service_id' => $service->id,
    ]);

    expect(fn () => SourceServiceMapping::factory()->shopify()->create([
        'external_carrier_id' => 'usps',
        'external_service_id' => 'usps_ground_advantage_other',
        'carrier_service_id' => $service->id,
    ]))->toThrow(QueryException::class);
});

it('refuses a second Shopify code for a service when mapping, rather than rewriting the first', function (): void {
    $service = CarrierService::factory()->create();

    SourceServiceMapping::map(PostageSourceKind::Shopify, 'usps', 'usps_ground_advantage', $service->id);

    expect(fn () => SourceServiceMapping::map(PostageSourceKind::Shopify, 'usps', 'usps_other', $service->id))
        ->toThrow(QueryException::class)
        ->and(SourceServiceMapping::sole()->external_service_id)->toBe('usps_ground_advantage');
});

it('lets several Amazon identifiers name one service', function (): void {
    $service = CarrierService::factory()->create();

    SourceServiceMapping::map(PostageSourceKind::Amazon, 'AMZN_US', 'std-us-swa-mfn', $service->id);
    SourceServiceMapping::map(PostageSourceKind::Amazon, 'AMZN_US', 'AMZN_US_GROUND', $service->id);

    // And the Shopify constraint does not reach them.
    SourceServiceMapping::factory()->shopify()->create(['carrier_service_id' => $service->id]);

    expect(SourceServiceMapping::where('carrier_service_id', $service->id)->count())->toBe(3);
});

it('refuses a second row for the same external identity', function (): void {
    SourceServiceMapping::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_service_id' => 'ONTRAC_MFN_GROUND',
    ]);

    expect(fn () => SourceServiceMapping::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_service_id' => 'ONTRAC_MFN_GROUND',
    ]))->toThrow(QueryException::class);
});

it('restricts deleting a service a mapping names', function (): void {
    $mapping = SourceServiceMapping::factory()->create();

    expect(fn () => $mapping->carrierService->delete())->toThrow(QueryException::class);
});

it('refuses to delete a mapped service with a message naming the mapping', function (): void {
    $mapping = SourceServiceMapping::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_service_id' => 'ONTRAC_MFN_GROUND',
    ]);

    Livewire::test(EditCarrierService::class, ['record' => $mapping->carrier_service_id])
        ->callAction(DeleteAction::class)
        ->assertNotified('Cannot delete carrier service');

    expect(CarrierService::whereKey($mapping->carrier_service_id)->exists())->toBeTrue();
});

it('still deletes a service nothing names', function (): void {
    $service = CarrierService::factory()->create();

    Livewire::test(EditCarrierService::class, ['record' => $service->id])
        ->callAction(DeleteAction::class);

    expect(CarrierService::whereKey($service->id)->exists())->toBeFalse();
});

it('refuses to delete a carrier whose service a mapping names', function (): void {
    $carrier = Carrier::factory()->create();
    $service = CarrierService::factory()->create(['carrier_id' => $carrier->id]);
    SourceServiceMapping::factory()->create(['carrier_service_id' => $service->id]);

    Livewire::test(EditCarrier::class, ['record' => $carrier->id])
        ->callAction(DeleteAction::class)
        ->assertNotified('Cannot delete carrier');

    expect(Carrier::whereKey($carrier->id)->exists())->toBeTrue();
});

it('describes a mapping the way a person reads it', function (): void {
    $mapping = SourceServiceMapping::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_service_id' => 'ONTRAC_MFN_GROUND',
    ]);

    expect($mapping->describe())->toBe('Amazon Buy Shipping ONTRAC / ONTRAC_MFN_GROUND');
});
