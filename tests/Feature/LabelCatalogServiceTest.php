<?php

use App\DataTransferObjects\Shipping\ServiceInference;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PostageSource;
use App\Enums\PostageSourceKind;
use App\Enums\Role;
use App\Enums\ServiceEvidence;
use App\Filament\Resources\LabelBatchResource\Pages\ViewLabelBatch;
use App\Filament\Resources\LabelBatchResource\RelationManagers\LabelBatchItemsRelationManager;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\SourceServiceMapping;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\CatalogServiceResolver;
use App\Services\ServiceInference\ServiceRuleset;
use Database\Seeders\CarrierSeeder;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

/**
 * Issue `postage-source-split/15`: the catalog service a Label was bought as,
 * snapshotted beside the source's own name for it.
 */
beforeEach(function (): void {
    $this->seed(CarrierSeeder::class);
});

function catalogService(string $carrier, string $serviceCode): CarrierService
{
    return CarrierService::query()
        ->whereBelongsTo(Carrier::where('name', $carrier)->sole())
        ->where('service_code', $serviceCode)
        ->sole();
}

function carrierId(string $carrier): int
{
    return Carrier::where('name', $carrier)->value('id');
}

/**
 * A shipped package whose Label carries these service facts and no catalog
 * service, as every Label bought before the column did.
 *
 * @param  array<string, mixed>  $attributes
 */
function labelWithoutCatalogService(string $carrier, ?string $service, ServiceEvidence $evidence, array $attributes = []): Package
{
    $package = Package::factory()->shipped()->create([
        'carrier' => $carrier,
        'service' => $service,
        'service_evidence' => $evidence,
        'service_inference_method' => $evidence === ServiceEvidence::Inferred ? 'usps-impb-stc' : null,
        'service_ruleset_version' => $evidence === ServiceEvidence::Inferred ? '2026-09-17' : null,
        ...$attributes,
    ]);

    expect($package->activeLabel->carrier_service_id)->toBeNull();

    return $package;
}

// --- The ruleset table -------------------------------------------------------

it('names only catalog services the seeder holds', function (): void {
    $table = json_decode((string) file_get_contents(resource_path('data/service-inference/catalog-services.json')), true);
    $ruleset = new ServiceRuleset;

    foreach ($table['carriers'] as $carrierKey => $services) {
        $carrier = Carrier::query()->get()->first(
            fn (Carrier $carrier): bool => mb_strtolower($carrier->name) === $carrierKey,
        );

        expect($carrier)->not->toBeNull("no seeded carrier for {$carrierKey}");

        foreach ($services as $service => $serviceCode) {
            expect($ruleset->catalogServiceCodeFor($carrier->name, $service))->toBe($serviceCode)
                ->and($carrier->carrierServices()->where('service_code', $serviceCode)->exists())
                ->toBeTrue("{$carrier->name} has no {$serviceCode} for {$service}");
        }
    }
});

it('maps every service name the shared inference rungs emit, bar the ones the catalog cannot hold', function (): void {
    $ruleset = new ServiceRuleset;

    // UPS Ground Saver is two catalog rows split by weight, and DHL eCommerce
    // has no row; both are documented as absent in the table itself.
    expect($ruleset->catalogServiceCodeFor('UPS', 'UPS Ground Saver'))->toBeNull()
        ->and($ruleset->catalogServiceCodeFor('DHL eCommerce', 'DHL SmartMail Parcel Ground'))->toBeNull();

    foreach (['01', '02', '03', '04', '12', '13', '66', '67', '68'] as $indicator) {
        $service = $ruleset->upsServiceForServiceIndicator($indicator);

        expect($ruleset->catalogServiceCodeFor('UPS', $service))->not->toBeNull("1Z {$indicator} names {$service}");
    }

    foreach (['usps:GroundAdvantage', 'usps:Priority', 'usps:PriorityExpress', 'usps:MediaMail', 'ups_shipping:03', 'dhl_express:P'] as $pair) {
        $selection = $ruleset->shopifySelection($pair);

        expect($ruleset->catalogServiceCodeFor($selection['carrier'], $selection['service']))->not->toBeNull($pair);
    }
});

// --- Resolution --------------------------------------------------------------

it('resolves every direct USPS variant to its mail class, and the longest mail class that fits', function (string $reported, string $serviceCode): void {
    expect(app(CatalogServiceResolver::class)->forReportedService(carrierId('USPS'), $reported))
        ->toBe(catalogService('USPS', $serviceCode)->id);
})->with([
    ['USPS Ground Advantage Machinable Single-piece', 'USPS_GROUND_ADVANTAGE'],
    ['USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 1', 'USPS_GROUND_ADVANTAGE'],
    ['USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2', 'USPS_GROUND_ADVANTAGE'],
    ['USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 3', 'USPS_GROUND_ADVANTAGE'],
    ['USPS Ground Advantage Nonstandard Single-piece', 'USPS_GROUND_ADVANTAGE'],
    ['Priority Mail Machinable Single-piece', 'PRIORITY_MAIL'],
    ['Priority Mail Machinable Cubic Non-Soft Pack Tier 1', 'PRIORITY_MAIL'],
    ['Priority Mail Express Machinable Single-piece', 'PRIORITY_MAIL_EXPRESS'],
    ['Priority Mail International Machinable ISC Single-piece', 'PRIORITY_MAIL_INTERNATIONAL'],
    ['First-Class Package International Service Machinable ISC Single-piece', 'FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE'],
    ['Media Mail Machinable Single-piece', 'MEDIA_MAIL'],
]);

it('matches other carriers only on the whole name, ignoring case and ®', function (): void {
    $resolver = app(CatalogServiceResolver::class);

    expect($resolver->forReportedService(carrierId('UPS'), 'UPS Ground'))->toBe(catalogService('UPS', '03')->id)
        ->and($resolver->forReportedService(carrierId('FedEx'), 'FedEx International Connect Plus'))
        ->toBe(catalogService('FedEx', 'FEDEX_INTERNATIONAL_CONNECT_PLUS')->id)
        // "UPS Ground" starts "UPS Ground Saver", and is not it.
        ->and($resolver->forReportedService(carrierId('UPS'), 'UPS Ground Saver'))->toBeNull()
        ->and($resolver->forReportedService(carrierId('USPS'), 'Something USPS never sold'))->toBeNull()
        ->and($resolver->forReportedService(null, 'UPS Ground'))->toBeNull();
});

it('resolves an inferred name through the ruleset, to the row a direct purchase records', function (): void {
    $resolver = app(CatalogServiceResolver::class);

    expect($resolver->forInferredService(carrierId('USPS'), 'USPS Ground Advantage'))
        ->toBe(catalogService('USPS', 'USPS_GROUND_ADVANTAGE')->id)
        ->and($resolver->forInferredService(carrierId('DHL Express'), 'DHL Express Worldwide'))
        ->toBe(catalogService('DHL Express', 'P')->id)
        ->and($resolver->forInferredService(carrierId('UPS'), 'UPS Ground Saver'))->toBeNull()
        ->and($resolver->forInferredService(null, 'USPS Ground Advantage'))->toBeNull();
});

// --- Snapshot at ship time -----------------------------------------------------

it('snapshots an inferred service onto the Label of a blind purchase', function (): void {
    $source = DataSource::factory()->create();
    $package = Package::factory()->create();

    $package->markShipped(new ShipResponse(
        success: true,
        trackingNumber: '9200190380793700250012',
        carrier: 'USPS',
        service: 'USPS Ground Advantage',
        serviceEvidence: ServiceEvidence::Inferred,
        serviceInferenceMethod: 'usps-impb-stc',
        serviceRulesetVersion: '2026-10-02',
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: $source->id,
    ), PostageSource::PostageDataSource);

    expect($package->activeLabel->carrier_service_id)->toBe(catalogService('USPS', 'USPS_GROUND_ADVANTAGE')->id)
        ->and($package->service)->toBe('USPS Ground Advantage')
        ->and($package->serviceDisplayName())->toBe('Ground Advantage');
});

it('ships an inferred service the catalog has no row for, naming none', function (): void {
    $source = DataSource::factory()->create();
    $package = Package::factory()->create();

    $package->markShipped(new ShipResponse(
        success: true,
        trackingNumber: '1Z28X87GYW27798425',
        carrier: 'UPS',
        service: 'UPS Ground Saver',
        serviceEvidence: ServiceEvidence::Inferred,
        serviceInferenceMethod: 'ups-1z-service-indicator',
        serviceRulesetVersion: '2026-10-02',
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: $source->id,
    ), PostageSource::PostageDataSource);

    expect($package->activeLabel->carrier_service_id)->toBeNull()
        ->and($package->serviceDisplayName())->toBe('UPS Ground Saver');
});

it('keeps the raw service exactly as the source reported it', function (): void {
    $package = Package::factory()->create();
    $groundAdvantage = catalogService('USPS', 'USPS_GROUND_ADVANTAGE');

    $package->markShipped(ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2',
    ), PostageSource::CarrierAccount, carrierServiceId: $groundAdvantage->id);

    expect($package->service)->toBe('USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2')
        ->and($package->activeLabel->service)->toBe('USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2')
        ->and($package->activeLabel->carrier_service_id)->toBe($groundAdvantage->id);
});

it('writes the catalog service with a later inference, and clears it when the inference is withdrawn', function (): void {
    $package = labelWithoutCatalogService('USPS', null, ServiceEvidence::Unknown);

    expect($package->recordInferredService(ServiceInference::resolved('Priority Mail', 'usps-impb-stc', '2026-10-02')))->toBeTrue()
        ->and($package->activeLabel()->value('carrier_service_id'))->toBe(catalogService('USPS', 'PRIORITY_MAIL')->id);

    expect($package->withdrawInferredService(ServiceInference::contradicted('disagree')))->toBeTrue()
        ->and($package->activeLabel()->value('carrier_service_id'))->toBeNull();
});

it('does not recompute a past Label when the catalog is edited, and refuses to delete a row a Label names', function (): void {
    $groundAdvantage = catalogService('USPS', 'USPS_GROUND_ADVANTAGE');
    $package = Package::factory()->shipped()->create(['carrier' => 'USPS', 'service' => 'USPS Ground Advantage Machinable Single-piece']);
    $package->activeLabel->update(['carrier_service_id' => $groundAdvantage->id]);

    // Moved to another code entirely: the Label still points at the row it
    // was bought as, and shows that row's current name.
    $groundAdvantage->update(['name' => 'Ground Advantage (renamed)', 'service_code' => 'RENAMED']);

    $package = $package->fresh();

    expect($package->activeLabel->carrier_service_id)->toBe($groundAdvantage->id)
        ->and($package->serviceDisplayName())->toBe('Ground Advantage (renamed)')
        ->and($package->service)->toBe('USPS Ground Advantage Machinable Single-piece');

    expect(fn () => $groundAdvantage->delete())->toThrow(QueryException::class);
});

// --- Backfill ------------------------------------------------------------------

it('reports without writing unless told to apply', function (): void {
    $package = labelWithoutCatalogService('USPS', 'USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2', ServiceEvidence::Confirmed);

    $this->artisan('app:backfill-label-catalog-services')
        ->expectsOutputToContain('Nothing written')
        ->assertSuccessful();

    expect($package->activeLabel()->value('carrier_service_id'))->toBeNull();
});

it('backfills reported, inferred and Amazon-mapped Labels, leaves the rest, and is idempotent', function (): void {
    $direct = labelWithoutCatalogService('USPS', 'USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2', ServiceEvidence::Confirmed);
    $inferred = labelWithoutCatalogService('USPS', 'Media Mail', ServiceEvidence::Inferred);
    $unknown = labelWithoutCatalogService('UPS', null, ServiceEvidence::Unknown);
    $groundSaver = labelWithoutCatalogService('UPS', 'UPS Ground Saver', ServiceEvidence::Inferred);

    // Amazon's own name for a service whose mapping says what it is.
    $amazon = DataSource::factory()->create(['name' => 'Amazon store']);
    $priority = catalogService('USPS', 'PRIORITY_MAIL');
    SourceServiceMapping::map(PostageSourceKind::Amazon, 'USPS', 'std-us-usps-priority', $priority->id);
    $mapped = labelWithoutCatalogService('USPS', 'Amazon says USPS Priority', ServiceEvidence::Confirmed, [
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => $amazon->id,
        'metadata' => [
            AmazonBuyShippingAdapter::CARRIER_ID_KEY => 'USPS',
            AmazonBuyShippingAdapter::SERVICE_ID_KEY => 'std-us-usps-priority',
        ],
    ]);

    $this->artisan('app:backfill-label-catalog-services', ['--apply' => true])
        ->expectsOutputToContain('Recorded the catalog service on 3 Label(s).')
        ->assertSuccessful();

    expect($direct->activeLabel()->value('carrier_service_id'))->toBe(catalogService('USPS', 'USPS_GROUND_ADVANTAGE')->id)
        ->and($inferred->activeLabel()->value('carrier_service_id'))->toBe(catalogService('USPS', 'MEDIA_MAIL')->id)
        ->and($mapped->activeLabel()->value('carrier_service_id'))->toBe($priority->id)
        ->and($unknown->activeLabel()->value('carrier_service_id'))->toBeNull()
        ->and($groundSaver->activeLabel()->value('carrier_service_id'))->toBeNull();

    $this->artisan('app:backfill-label-catalog-services', ['--apply' => true])
        ->expectsOutputToContain('Recorded the catalog service on 0 Label(s).')
        ->assertSuccessful();
});

it('backfills a Label older than its normalized carrier from the carrier the source named', function (): void {
    $package = labelWithoutCatalogService('USPS', 'Priority Mail Machinable Single-piece', ServiceEvidence::Confirmed, [
        'normalized_carrier_id' => null,
    ]);

    $this->artisan('app:backfill-label-catalog-services', ['--apply' => true])->assertSuccessful();

    $label = $package->activeLabel()->first();

    expect($label->carrier_service_id)->toBe(catalogService('USPS', 'PRIORITY_MAIL')->id)
        // Issue 03's column, which this does not backfill.
        ->and($label->normalized_carrier_id)->toBeNull();
});

it('never overwrites a Label that already names its catalog service', function (): void {
    $package = Package::factory()->shipped()->create(['carrier' => 'USPS', 'service' => 'Priority Mail Machinable Single-piece']);
    $groundAdvantage = catalogService('USPS', 'USPS_GROUND_ADVANTAGE');
    $package->activeLabel->update(['carrier_service_id' => $groundAdvantage->id]);

    $this->artisan('app:backfill-label-catalog-services', ['--apply' => true])->assertSuccessful();

    expect(PackageLabel::find($package->activeLabel->id)->carrier_service_id)->toBe($groundAdvantage->id);
});

// --- Packages list and view ----------------------------------------------------

it('lists the catalog name with the source name on hover, and filters by catalog row or unmapped', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    $groundAdvantage = catalogService('USPS', 'USPS_GROUND_ADVANTAGE');
    $cubic = Package::factory()->shipped()->create(['carrier' => 'USPS', 'service' => 'USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2']);
    $cubic->activeLabel->update(['carrier_service_id' => $groundAdvantage->id]);
    $inferred = Package::factory()->shipped()->create(['carrier' => 'USPS', 'service' => 'USPS Ground Advantage', 'service_evidence' => ServiceEvidence::Inferred, 'service_inference_method' => 'usps-impb-stc', 'service_ruleset_version' => '2026-10-02']);
    $inferred->activeLabel->update(['carrier_service_id' => $groundAdvantage->id]);
    $unmapped = Package::factory()->shipped()->create(['carrier' => 'UPS', 'service' => 'UPS Ground Saver']);

    Livewire::test(ListPackages::class)
        ->assertTableColumnStateSet('service', 'Ground Advantage', $cubic)
        ->assertTableColumnStateSet('service', 'UPS Ground Saver', $unmapped)
        ->assertTableColumnExists('service', fn ($column): bool => $column->getTooltip() === 'USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2', $cubic)
        // Nothing to add when the cell already shows the source's own name.
        ->assertTableColumnExists('service', fn ($column): bool => $column->getIcon('Ground Advantage') === 'heroicon-o-information-circle', $cubic)
        ->assertTableColumnExists('service', fn ($column): bool => $column->getTooltip() === null
            && $column->getIcon('UPS Ground Saver') === null, $unmapped)
        ->filterTable('service', $groundAdvantage->id)
        ->assertCanSeeTableRecords([$cubic, $inferred])
        ->assertCanNotSeeTableRecords([$unmapped])
        ->filterTable('service', 'unmapped')
        ->assertCanSeeTableRecords([$unmapped])
        ->assertCanNotSeeTableRecords([$cubic, $inferred]);
});

it('shows both names on the package page', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    $package = Package::factory()->shipped()->create(['carrier' => 'USPS', 'service' => 'USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2']);
    $package->activeLabel->update(['carrier_service_id' => catalogService('USPS', 'USPS_GROUND_ADVANTAGE')->id]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSee('Ground Advantage')
        ->assertSee('USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2');
});

it('shows a batch item\'s service as the catalog names the Label it bought, even after a re-buy', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    $raw = 'USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2';
    $package = Package::factory()->shipped()->create(['carrier' => 'USPS', 'service' => $raw]);
    $package->activeLabel->update(['carrier_service_id' => catalogService('USPS', 'USPS_GROUND_ADVANTAGE')->id]);

    $batch = LabelBatch::factory()->completed()->create();
    $bought = LabelBatchItem::factory()->success()->for($batch)->create([
        'package_id' => $package->id,
        'tracking_number' => $package->tracking_number,
        'service' => $raw,
    ]);
    // The source's own name, with no Label left to read a catalog name from.
    $unmatched = LabelBatchItem::factory()->success()->for($batch)->create(['service' => 'UPS Ground Saver']);

    // Voided and re-bought as something else since: the item still names
    // what this batch bought.
    $package->activeLabel->update(['voided_at' => now()]);
    PackageLabel::factory()->create([
        'package_id' => $package->id,
        'tracking_number' => 'REBOUGHT',
        'carrier_service_id' => catalogService('USPS', 'PRIORITY_MAIL')->id,
    ]);

    Livewire::test(LabelBatchItemsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewLabelBatch::class])
        ->assertTableColumnStateSet('service', 'Ground Advantage', $bought)
        ->assertTableColumnExists('service', fn ($column): bool => $column->getTooltip() === $raw
            && $column->getIcon('Ground Advantage') === 'heroicon-o-information-circle', $bought)
        ->assertTableColumnStateSet('service', 'UPS Ground Saver', $unmatched)
        ->assertTableColumnExists('service', fn ($column): bool => $column->getTooltip() === null, $unmatched);
});

it('names each Label in the package\'s history as the catalog does, with the source\'s name on hover', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    $raw = 'USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 2';
    $package = Package::factory()->shipped()->create(['carrier' => 'USPS', 'service' => 'Priority Mail Machinable Single-piece']);
    $package->activeLabel->update(['carrier_service_id' => catalogService('USPS', 'PRIORITY_MAIL')->id]);
    // An earlier, voided Label bought as something else keeps its own name.
    PackageLabel::factory()->create([
        'package_id' => $package->id,
        'carrier' => 'USPS',
        'service' => $raw,
        'carrier_service_id' => catalogService('USPS', 'USPS_GROUND_ADVANTAGE')->id,
        'voided_at' => now(),
    ]);
    // A Label whose source named the service as the catalog does: no hover.
    PackageLabel::factory()->create([
        'package_id' => $package->id,
        'carrier' => 'UPS',
        'service' => 'UPS Ground Saver',
        'voided_at' => now(),
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSeeHtml('title="'.e($raw).'"')
        ->assertSeeHtml('title="Priority Mail Machinable Single-piece"')
        ->assertSee('Ground Advantage')
        ->assertDontSeeHtml('title="UPS Ground Saver"');
});
