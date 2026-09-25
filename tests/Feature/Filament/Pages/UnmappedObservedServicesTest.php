<?php

use App\DataTransferObjects\PostageSources\ServiceObservation;
use App\Enums\AmazonChannelType;
use App\Enums\PostageSourceKind;
use App\Enums\Role;
use App\Enums\SourceEnvironment;
use App\Filament\Pages\UnmappedObservedServices;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Models\SourceServiceMapping;
use App\Models\User;
use App\Services\PostageSources\ObservedServiceRecorder;
use App\Services\PostageSources\ServiceApprovalGate;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * An approval, granted the way the gate insists on it: by somebody.
 */
function approveService(ObservedService $observation, Client $client): void
{
    app(ServiceApprovalGate::class)->grant($observation, AmazonChannelType::Amazon, $client, User::factory()->create());
}

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('renders the page successfully', function (): void {
    Livewire::test(UnmappedObservedServices::class)
        ->assertSuccessful();
});

it('lists unmapped observations and hides mapped ones by default', function (): void {
    $unmapped = ObservedService::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_service_id' => 'ONTRAC_MFN_GROUND',
    ]);

    $mapped = ObservedService::factory()->mapped()->create([
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);

    Livewire::test(UnmappedObservedServices::class)
        ->assertCanSeeTableRecords([$unmapped])
        ->assertCanNotSeeTableRecords([$mapped])
        ->assertCountTableRecords(1);
});

it('can show mapped observations so a mapping can be corrected', function (): void {
    $mapped = ObservedService::factory()->mapped()->create();

    Livewire::test(UnmappedObservedServices::class)
        ->filterTable('mapped', true)
        ->assertCanSeeTableRecords([$mapped]);
});

it('aliases an observed service onto an existing carrier service', function (): void {
    $carrierService = CarrierService::factory()->create(['name' => 'Ground Advantage']);

    $observation = ObservedService::factory()->create([
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);

    Livewire::test(UnmappedObservedServices::class)
        ->callAction(TestAction::make('assign')->table($observation), [
            'carrier_service_id' => $carrierService->id,
        ])
        ->assertNotified();

    $mapping = SourceServiceMapping::sole();

    expect($mapping->source_kind)->toBe(PostageSourceKind::Amazon)
        ->and($mapping->external_carrier_id)->toBe('USPS')
        ->and($mapping->external_service_id)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($mapping->carrier_service_id)->toBe($carrierService->id);
});

it('carries one mapping across the environments the same service was seen in', function (): void {
    $carrierService = CarrierService::factory()->create();

    $production = ObservedService::factory()->create([
        'environment' => SourceEnvironment::Production,
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);

    $sandbox = ObservedService::factory()->create([
        'environment' => SourceEnvironment::Sandbox,
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);

    $otherService = ObservedService::factory()->create([
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_PRIORITY_MAIL',
    ]);

    Livewire::test(UnmappedObservedServices::class)
        ->callAction(TestAction::make('assign')->table($production), [
            'carrier_service_id' => $carrierService->id,
        ]);

    expect(SourceServiceMapping::count())->toBe(1)
        ->and($production->mapping()->carrier_service_id)->toBe($carrierService->id)
        ->and($sandbox->mapping()->carrier_service_id)->toBe($carrierService->id)
        ->and($otherService->isMapped())->toBeFalse();
});

it('holds a mapping over a service first seen elsewhere after it was mapped', function (): void {
    $carrierService = CarrierService::factory()->create();

    $observation = ObservedService::factory()->create([
        'source' => 'amazon',
        'marketplace' => 'ATVPDKIKX0DER',
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);

    Livewire::test(UnmappedObservedServices::class)
        ->callAction(TestAction::make('assign')->table($observation), [
            'carrier_service_id' => $carrierService->id,
        ]);

    // One row names the service, so a marketplace that reports it later is
    // mapped the moment it is recorded, with nothing copied onto it.
    app(ObservedServiceRecorder::class)->record([
        new ServiceObservation(
            source: 'amazon',
            externalCarrierId: 'USPS',
            externalServiceId: 'USPS_GROUND_ADVANTAGE',
            marketplace: 'A2EUQ1WTGCTBG2',
            eligible: true,
        ),
    ]);

    expect(ObservedService::where('marketplace', 'A2EUQ1WTGCTBG2')->sole()->mapping()->carrier_service_id)
        ->toBe($carrierService->id);
});

it('writes one mapping row and leaves observations untouched', function (): void {
    $carrierService = CarrierService::factory()->create();
    $observation = ObservedService::factory()->create();
    $before = $observation->fresh()->getAttributes();

    $observationWrites = 0;

    DB::listen(function ($query) use (&$observationWrites): void {
        if (str_contains($query->sql, 'observed_services') && preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $observationWrites++;
        }
    });

    Livewire::test(UnmappedObservedServices::class)
        ->callAction(TestAction::make('assign')->table($observation), [
            'carrier_service_id' => $carrierService->id,
        ]);

    expect($observationWrites)->toBe(0)
        ->and(SourceServiceMapping::count())->toBe(1)
        ->and($observation->fresh()->getAttributes())->toBe($before);
});

it('replaces a mapping rather than adding a second row', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $replacement = CarrierService::factory()->create();

    Livewire::test(UnmappedObservedServices::class)
        ->filterTable('mapped', true)
        ->callAction(TestAction::make('assign')->table($observation), [
            'carrier_service_id' => $replacement->id,
        ]);

    expect(SourceServiceMapping::sole()->carrier_service_id)->toBe($replacement->id);
});

it('promotes an observation for an unknown carrier by authoring the carrier and the service', function (): void {
    $observation = ObservedService::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_carrier_name' => 'OnTrac',
        'external_service_id' => 'ONTRAC_MFN_GROUND',
        'external_service_name' => 'OnTrac Ground',
    ]);

    expect(Carrier::where('name', 'OnTrac')->exists())->toBeFalse();

    Livewire::test(UnmappedObservedServices::class)
        ->callAction([
            TestAction::make('author')->table($observation),
            TestAction::make('createOption')->schemaComponent('carrier_id'),
        ], [
            'name' => 'OnTrac',
        ]);

    $carrier = Carrier::where('name', 'OnTrac')->sole();

    Livewire::test(UnmappedObservedServices::class)
        ->callAction(TestAction::make('author')->table($observation), [
            'carrier_id' => $carrier->id,
            'service_code' => 'ONTRAC_MFN_GROUND',
            'name' => 'OnTrac Ground',
            'can_ship_to_po_boxes' => false,
            'can_ship_to_military_addresses' => false,
        ])
        ->assertNotified();

    $carrierService = CarrierService::where('service_code', 'ONTRAC_MFN_GROUND')->sole();

    expect($carrierService->carrier_id)->toBe($carrier->id)
        ->and($carrierService->name)->toBe('OnTrac Ground')
        ->and($observation->mapping()->carrier_service_id)->toBe($carrierService->id);
});

it('prefills the authoring form from what the source reported', function (): void {
    Carrier::factory()->create(['name' => 'OnTrac']);

    $observation = ObservedService::factory()->create([
        'external_carrier_name' => 'OnTrac',
        'external_service_id' => 'ONTRAC_MFN_GROUND',
        'external_service_name' => 'OnTrac Ground',
    ]);

    Livewire::test(UnmappedObservedServices::class)
        ->mountAction(TestAction::make('author')->table($observation))
        ->assertActionDataSet([
            'carrier_id' => Carrier::where('name', 'OnTrac')->value('id'),
            'service_code' => 'ONTRAC_MFN_GROUND',
            'name' => 'OnTrac Ground',
        ]);
});

it('refuses the page to a manager, because from carrier-catalog-reset/13 a mapping authorizes spend', function (): void {
    $this->actingAs(User::factory()->manager()->create());

    expect(UnmappedObservedServices::canAccess())->toBeFalse();

    $this->get(UnmappedObservedServices::getUrl())->assertForbidden();
});

it('offers an admin every mapping action', function (): void {
    $observation = ObservedService::factory()->create();

    expect(UnmappedObservedServices::canAccess())->toBeTrue();

    Livewire::test(UnmappedObservedServices::class)
        ->assertActionVisible(TestAction::make('author')->table($observation))
        ->assertActionVisible(TestAction::make('assign')->table($observation));
});

it('returns a mapped observation to the unmapped state without deleting catalog rows', function (): void {
    $carrierService = CarrierService::factory()->create();
    $observation = ObservedService::factory()->mapped($carrierService)->create();

    Livewire::test(UnmappedObservedServices::class)
        ->filterTable('mapped', true)
        ->callAction(TestAction::make('unmap')->table($observation))
        ->assertNotified();

    expect(SourceServiceMapping::count())->toBe(0)
        ->and($observation->fresh())->not->toBeNull()
        ->and(CarrierService::whereKey($carrierService->id)->exists())->toBeTrue();
});

it('leaves approvals in place when a service is unmapped', function (): void {
    // amazon-buy-shipping/18: what a service is called is not whether
    // automation may buy it.
    $observation = ObservedService::factory()->mapped()->create();

    approveService($observation, Client::factory()->create());

    Livewire::test(UnmappedObservedServices::class)
        ->filterTable('mapped', true)
        ->callAction(TestAction::make('unmap')->table($observation))
        ->assertNotified();

    expect(ServiceApproval::count())->toBe(1)
        ->and($observation->isMapped())->toBeFalse();
});
it('offers to unmap an approved service, since that withdraws nothing', function (): void {
    $approved = ObservedService::factory()->mapped()->create([
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);

    approveService($approved, Client::factory()->create());

    Livewire::test(UnmappedObservedServices::class)
        ->filterTable('mapped', true)
        ->assertActionVisible(TestAction::make('unmap')->table($approved));
});
it('leaves an unmapped observation alone — no badge, no queue, no error', function (): void {
    ObservedService::factory()
        ->count(3)
        ->sequence(
            ['external_service_id' => 'ONTRAC_MFN_GROUND'],
            ['external_service_id' => 'ONTRAC_MFN_SUNRISE'],
            ['external_service_id' => 'ONTRAC_MFN_GOLD'],
        )
        ->create();

    // ADR-0003 decision 8: unmapped is a valid terminal state. A navigation
    // badge would turn "valid" into "outstanding work" for something that
    // never has to be done.
    expect(UnmappedObservedServices::getNavigationBadge())->toBeNull();

    Livewire::test(UnmappedObservedServices::class)
        ->assertCountTableRecords(3)
        ->assertSuccessful();
});
