<?php

use App\DataTransferObjects\PostageSources\ServiceObservation;
use App\Enums\PostageSourceKind;
use App\Enums\Role;
use App\Enums\SourceEnvironment;
use App\Exceptions\CrossCarrierMappingException;
use App\Filament\Pages\UnmappedObservedServices;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\CarrierService;
use App\Models\ObservedService;
use App\Models\SourceServiceMapping;
use App\Models\User;
use App\Services\PostageSources\ObservedServiceMapper;
use App\Services\PostageSources\ObservedServiceRecorder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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

it('filters to the unmapped services a source has offered as buyable', function (): void {
    $offered = ObservedService::factory()->create([
        'external_carrier_id' => 'ONTRAC',
        'external_service_id' => 'ONTRAC_MFN_SUNRISE',
    ]);

    $neverOffered = ObservedService::factory()->neverEligible()->create([
        'external_carrier_id' => 'DHLMX',
        'external_service_id' => 'DHLMX_PTP_PACKAGE_EXPRESS',
    ]);

    Livewire::test(UnmappedObservedServices::class)
        ->filterTable('offered', true)
        ->assertCanSeeTableRecords([$offered])
        ->assertCanNotSeeTableRecords([$neverOffered])
        ->filterTable('offered', false)
        ->assertCanSeeTableRecords([$neverOffered])
        ->assertCanNotSeeTableRecords([$offered]);
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

it('refuses to map a service onto another carrier\'s', function (): void {
    Carrier::factory()->create(['name' => 'OnTrac']);
    $groundAdvantage = CarrierService::factory()
        ->for(Carrier::factory()->create(['name' => 'USPS']))
        ->create(['name' => 'Ground Advantage']);

    $observation = ObservedService::factory()->create();

    Livewire::test(UnmappedObservedServices::class)
        ->callAction(TestAction::make('assign')->table($observation), [
            'carrier_service_id' => $groundAdvantage->id,
        ])
        // Not even on the list: only OnTrac's services are offered.
        ->assertHasFormErrors(['carrier_service_id']);

    expect(SourceServiceMapping::count())->toBe(0);
});

it('counts a carrier alias when deciding which carrier a service belongs to', function (): void {
    $ontrac = Carrier::factory()->create(['name' => 'OnTrac Logistics']);
    CarrierAlias::factory()->for($ontrac)->create(['alias' => 'OnTrac']);
    $groundAdvantage = CarrierService::factory()
        ->for(Carrier::factory()->create(['name' => 'USPS']))
        ->create();

    expect(fn () => app(ObservedServiceMapper::class)->map(ObservedService::factory()->create(), $groundAdvantage))
        ->toThrow(CrossCarrierMappingException::class);
});

it('refuses to author a service for another carrier than the source named', function (): void {
    Carrier::factory()->create(['name' => 'OnTrac']);
    $usps = Carrier::factory()->create(['name' => 'USPS']);

    $observation = ObservedService::factory()->create();

    Livewire::test(UnmappedObservedServices::class)
        ->callAction(TestAction::make('author')->table($observation), [
            'carrier_id' => $usps->id,
            'service_code' => 'ONTRAC_MFN_GROUND',
            'name' => 'OnTrac Ground',
        ])
        ->assertNotified('Not created');

    expect(CarrierService::where('service_code', 'ONTRAC_MFN_GROUND')->exists())->toBeFalse()
        ->and(SourceServiceMapping::count())->toBe(0);
});

it('offers only the named carrier\'s services once that carrier is known', function (): void {
    $ontrac = Carrier::factory()->create(['name' => 'OnTrac']);
    $ontracGround = CarrierService::factory()->for($ontrac)->create(['name' => 'Ground']);
    $groundAdvantage = CarrierService::factory()
        ->for(Carrier::factory()->create(['name' => 'USPS']))
        ->create(['name' => 'Ground Advantage']);

    $known = ObservedService::factory()->create();
    $unknown = ObservedService::factory()->create([
        'external_carrier_id' => 'LSO',
        'external_carrier_name' => 'LSO',
        'external_service_id' => 'LSO_GROUND',
    ]);

    $options = fn (ObservedService $record): array => (new ReflectionMethod(UnmappedObservedServices::class, 'carrierServiceOptions'))
        ->invoke(null, $record);

    expect(array_keys($options($known)))->toBe([$ontracGround->id])
        // No carrier here matches LSO, so the choice is the person's.
        ->and(array_keys($options($unknown)))->toEqualCanonicalizing([$ontracGround->id, $groundAdvantage->id]);
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

it('unmaps a service, which narrows what automation buys', function (): void {
    // carrier-catalog-reset/13: a method allowing Amazon only its listed
    // services buys a service only while it is mapped.
    $observation = ObservedService::factory()->mapped()->create();

    Livewire::test(UnmappedObservedServices::class)
        ->filterTable('mapped', true)
        ->callAction(TestAction::make('unmap')->table($observation))
        ->assertNotified();

    expect($observation->isMapped())->toBeFalse();
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
