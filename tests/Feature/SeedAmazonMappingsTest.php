<?php

use App\Enums\ContentClass;
use App\Enums\PostageSourceKind;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\SourceServiceMapping;
use Database\Seeders\AmazonServiceMappingSeeder;
use Database\Seeders\CarrierSeeder;
use Database\Seeders\OnceOnlySeeder;

/**
 * `carrier-catalog-reset/11`: Amazon Shipping and OnTrac become carriers, and
 * the Amazon identifiers already seen are seeded once as authored mappings.
 */
function upsService(string $code): CarrierService
{
    return CarrierService::query()
        ->where('service_code', $code)
        ->whereHas('carrier', fn ($query) => $query->where('name', Carrier::UPS))
        ->sole();
}

function amazonMapping(string $carrierId, string $serviceId): ?SourceServiceMapping
{
    return SourceServiceMapping::query()->forIdentity(PostageSourceKind::Amazon, $carrierId, $serviceId)->first();
}

function groundSaverRenameMigration(): object
{
    return require database_path('migrations/2026_09_26_040921_name_ups_ground_saver_weight_bands.php');
}

describe('the catalog', function (): void {
    beforeEach(function (): void {
        $this->seed(CarrierSeeder::class);
    });

    it('seeds Amazon Shipping and OnTrac as system carriers, each with its one service', function (): void {
        $amazonShipping = Carrier::query()->where('name', Carrier::AMAZON_SHIPPING)->sole();
        $onTrac = Carrier::query()->where('name', Carrier::ONTRAC)->sole();

        expect($amazonShipping->is_system)->toBeTrue()
            ->and($amazonShipping->carrierServices()->pluck('name', 'service_code')->all())
            ->toBe(['std-us-swa-mfn' => 'Amazon Shipping Ground'])
            ->and($onTrac->is_system)->toBeTrue()
            // OnTrac publishes no API documentation: the code is the one
            // Amazon's Buy Shipping tutorial gives.
            ->and($onTrac->carrierServices()->pluck('name', 'service_code')->all())
            ->toBe(['ONTRAC_MFN_GROUND' => 'OnTrac Ground']);
    });

    it('seeds USPS Parcel Select and UPS Ground Saver Media, which requires media', function (): void {
        $groundSaverMedia = upsService('95');

        expect(CarrierService::query()->where('service_code', 'PARCEL_SELECT')->sole()->name)->toBe('Parcel Select')
            ->and($groundSaverMedia->name)->toBe('UPS Ground Saver Media')
            ->and($groundSaverMedia->required_contents)->toBe(ContentClass::Media)
            // Its last mile is USPS Media Mail.
            ->and($groundSaverMedia->can_ship_to_po_boxes)->toBeTrue()
            ->and($groundSaverMedia->can_ship_to_military_addresses)->toBeTrue();
    });

    it('names the two Ground Saver services by weight band, and leaves an Admin rename alone', function (): void {
        expect(upsService('92')->name)->toBe('UPS Ground Saver (under 1 lb)')
            ->and(upsService('93')->name)->toBe('UPS Ground Saver (1 lb and over)');

        upsService('92')->update(['name' => 'Ground Saver light']);
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        expect(upsService('92')->name)->toBe('Ground Saver light');
    });

    it('restores Ground Saver Media\'s requirement if it is taken off', function (): void {
        upsService('95')->update(['required_contents' => null]);

        $this->seed(CarrierSeeder::class);

        expect(upsService('95')->required_contents)->toBe(ContentClass::Media);
    });
});

describe('the Ground Saver rename migration', function (): void {
    it('renames rows still carrying the seeded name, and leaves an Admin rename alone', function (): void {
        $this->seed(CarrierSeeder::class);
        upsService('92')->update(['name' => 'UPS Ground Saver']);
        upsService('93')->update(['name' => 'Heavy Ground Saver']);

        groundSaverRenameMigration()->up();

        expect(upsService('92')->name)->toBe('UPS Ground Saver (under 1 lb)')
            ->and(upsService('93')->name)->toBe('Heavy Ground Saver');
    });

    it('touches no other carrier\'s service with the same code', function (): void {
        $other = CarrierService::factory()
            ->for(Carrier::factory()->create(['name' => 'Elsewhere']))
            ->create(['service_code' => '92', 'name' => 'UPS Ground Saver']);

        groundSaverRenameMigration()->up();

        expect($other->fresh()->name)->toBe('UPS Ground Saver');
    });
});

describe('the Amazon mappings', function (): void {
    beforeEach(function (): void {
        SourceServiceMapping::query()->delete();
    });

    it('are written by the first sync on a fresh database, and never again', function (): void {
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        $first = SourceServiceMapping::query()->where('source_kind', PostageSourceKind::Amazon)->orderBy('id')->get(['id', 'updated_at']);

        $this->artisan('app:sync-reference-data')->assertSuccessful();

        expect($first)->toHaveCount(count(AmazonServiceMappingSeeder::MAPPINGS))
            ->and($first)->toHaveCount(63)
            ->and(SourceServiceMapping::query()->where('source_kind', PostageSourceKind::Amazon)->orderBy('id')->get(['id', 'updated_at'])->toArray())
            ->toBe($first->toArray())
            ->and(OnceOnlySeeder::markerFor(AmazonServiceMappingSeeder::BATCH))->toBe('reference_data.seeded.amazon-mappings-v1');
    });

    it('name the catalog services signed off', function (): void {
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        $named = fn (string $carrierId, string $serviceId): string => amazonMapping($carrierId, $serviceId)->carrierService->carrier->name
            .' / '.amazonMapping($carrierId, $serviceId)->carrierService->name;

        expect($named('AMZN_US', 'std-us-swa-mfn'))->toBe('Amazon Shipping / Amazon Shipping Ground')
            ->and($named('ONTRAC', 'ONTRAC_MFN_GROUND'))->toBe('OnTrac / OnTrac Ground')
            ->and($named('USPS', 'USPS_PTP_FC'))->toBe('USPS / Ground Advantage')
            ->and($named('USPS', 'USPS_PTP_PSBN'))->toBe('USPS / Parcel Select')
            ->and($named('USPS', 'USPS_PTP_MM'))->toBe('USPS / Media Mail')
            ->and($named('UPS', 'UPS_PTP_NEXT_DAY_AIR_SAT'))->toBe('UPS / UPS Next Day Air')
            ->and($named('UPS', 'UPS_PTP_SUREPOST_L'))->toBe('UPS / UPS Ground Saver (under 1 lb)')
            ->and($named('UPS', 'UPS_PTP_SUREPOST_H'))->toBe('UPS / UPS Ground Saver (1 lb and over)')
            ->and($named('UPS', 'UPS_PTP_SUREPOST_MEDIA'))->toBe('UPS / UPS Ground Saver Media')
            ->and($named('FEDEX', 'FEDEX_PTP_PRI_OVERN_ONE_R_SAT'))->toBe('FedEx / FedEx Priority Overnight®')
            ->and($named('FEDEX', 'FEDEX_PTP_SMARTPOST'))->toBe('FedEx / FedEx Ground® Economy');
    });

    it('leave unmapped what the sign-off left to a person', function (string $carrierId, string $serviceId): void {
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        expect(amazonMapping($carrierId, $serviceId))->toBeNull();
    })->with([
        ['USPS', 'USPS_PTP_BPM'],
        ['UPS', 'UPS_PTP_SUREPOST_BPM'],
        ['UPS', 'UPS_PTP_GROUNDSAVER'],
        ['DHLMX', 'DHLMX_PTP_PACKAGE_EXPRESS'],
    ]);

    it('stay removed once an Admin removes one', function (): void {
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        amazonMapping('ONTRAC', 'ONTRAC_MFN_GROUND')->delete();
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        expect(amazonMapping('ONTRAC', 'ONTRAC_MFN_GROUND'))->toBeNull()
            ->and(SourceServiceMapping::query()->where('source_kind', PostageSourceKind::Amazon)->count())->toBe(62);
    });

    it('keep a mapping an Admin made before the batch first ran', function (): void {
        $this->seed(CarrierSeeder::class);
        $priorityMail = CarrierService::query()->where('service_code', 'PRIORITY_MAIL')->sole();
        SourceServiceMapping::map(PostageSourceKind::Amazon, 'USPS', 'USPS_PTP_FC', $priorityMail->id);

        $this->artisan('app:sync-reference-data')->assertSuccessful();

        expect(amazonMapping('USPS', 'USPS_PTP_FC')->carrier_service_id)->toBe($priorityMail->id)
            ->and(amazonMapping('USPS', 'USPS_PTP_GAH')->carrierService->service_code)->toBe('USPS_GROUND_ADVANTAGE');
    });
});
