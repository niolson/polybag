<?php

use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\Shipping\ServiceInference;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\VoidReason;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function shipResponse(string $trackingNumber = '9400111899223456789012', float $cost = 8.50): ShipResponse
{
    return ShipResponse::success(
        trackingNumber: $trackingNumber,
        cost: $cost,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('PDF content'),
    );
}

/**
 * Every projected column on the package equals its counterpart on the active
 * label, compared as the raw database values.
 */
function expectProjectionToMatchLabel(Package $package): void
{
    $packageRow = DB::table('packages')->where('id', $package->id)->first();
    $labelRow = DB::table('package_labels')->where('package_id', $package->id)->whereNull('voided_at')->first();

    foreach (PackageLabel::PROJECTED_COLUMNS as $packageColumn => $labelColumn) {
        expect((string) $labelRow->{$labelColumn})->toBe((string) $packageRow->{$packageColumn}, "on {$packageColumn}");
    }
}

it('has no second hand-written projected column list under app/', function (): void {
    // The constant is the one place the shared columns are named; a writer
    // that lists them again is a writer that can drift. The migration keeps
    // its own frozen copy on purpose, and lives outside app/.
    $needle = "'shipped_by_user_id' => 'purchased_by_user_id'";
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    $matches = [];

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php' && str_contains(file_get_contents($file->getPathname()), $needle)) {
            $matches[] = str_replace(base_path('/'), '', $file->getPathname());
        }
    }

    expect($matches)->toBe(['app/Models/PackageLabel.php']);
});

// --- markShipped() ---------------------------------------------------------

it('records a label row atomically with the projection when a package ships', function (): void {
    $account = CarrierAccount::factory()->create();
    $user = User::factory()->create();
    $package = Package::factory()->create();

    $package->markShipped(ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        carrierAccountId: $account->id,
    ), PostageSource::CarrierAccount, $user->id);

    $label = $package->activeLabel;

    expect($label)->not->toBeNull()
        ->and($label->tracking_number)->toBe('9400111899223456789012')
        ->and((float) $label->cost)->toBe(8.50)
        ->and($label->carrier)->toBe('USPS')
        ->and($label->service)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($label->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($label->postage_source)->toBe(PostageSource::CarrierAccount)
        ->and($label->carrier_account_id)->toBe($account->id)
        ->and($label->purchased_by_user_id)->toBe($user->id)
        ->and($label->purchased_at->toDateTimeString())->toBe($package->shipped_at->toDateTimeString())
        ->and($label->voided_at)->toBeNull()
        ->and($label->last_printed_at)->toBeNull();

    expectProjectionToMatchLabel($package);
});

it('records the postage source\'s own label identifier at purchase', function (): void {
    $source = DataSource::factory()->create();
    $package = Package::factory()->create();

    $package->markShipped(new ShipResponse(
        success: true,
        trackingNumber: '1Z999AA10123456784',
        cost: 12.00,
        carrier: 'UPS',
        service: 'Ground',
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: $source->id,
        sourceLabelReference: 'gid://shopify/ShippingLabel/42',
        metadata: ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/42'],
    ), PostageSource::PostageDataSource);

    expect($package->activeLabel->source_label_reference)->toBe('gid://shopify/ShippingLabel/42')
        ->and($package->activeLabel->postage_data_source_id)->toBe($source->id);
});

it('inserts no label row when the ship loses the optimistic-lock race', function (): void {
    $package = Package::factory()->shipped()->create();

    expect(fn () => $package->markShipped(shipResponse('OTHER'), PostageSource::CarrierAccount))
        ->toThrow(RuntimeException::class);

    expect($package->labels()->count())->toBe(1)
        ->and($package->activeLabel->tracking_number)->not->toBe('OTHER');
});

// --- clearShipping() -------------------------------------------------------

it('marks the label voided rather than deleting it, and keeps its facts', function (): void {
    $voider = User::factory()->create();
    $package = Package::factory()->shipped()->create([
        'tracking_number' => '9400111899223456789012',
        'cost' => 12.34,
        'label_printed_at' => now()->subMinute(),
    ]);

    $package->clearShipping(VoidReason::Operator, $voider->id);

    $label = $package->labels()->sole();

    expect($package->refresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($package->tracking_number)->toBeNull()
        ->and($package->ship_date)->toBeNull()
        ->and($package->activeLabel)->toBeNull()
        ->and($label->voided_at)->not->toBeNull()
        ->and($label->voided_by_user_id)->toBe($voider->id)
        ->and($label->void_reason)->toBe(VoidReason::Operator)
        ->and($label->tracking_number)->toBe('9400111899223456789012')
        ->and((float) $label->cost)->toBe(12.34)
        ->and($label->last_printed_at)->not->toBeNull();
});

it('records an upstream void with no user', function (): void {
    $package = Package::factory()->shipped()->create();

    $package->clearShipping(VoidReason::VoidedUpstream);

    $label = $package->labels()->sole();

    expect($label->void_reason)->toBe(VoidReason::VoidedUpstream)
        ->and($label->voided_by_user_id)->toBeNull();
});

it('refuses to void a shipped package that has no active label, and rolls back', function (): void {
    $package = Package::factory()->shipped()->create();
    PackageLabel::query()->where('package_id', $package->id)->delete();

    expect(fn () => $package->clearShipping(VoidReason::Operator))->toThrow(LogicException::class);

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('leaves two rows after ship, void and ship again, with the projection equal to the active one', function (): void {
    $package = Package::factory()->create();

    $package->markShipped(shipResponse('FIRST', 5.00), PostageSource::CarrierAccount);
    $package->clearShipping(VoidReason::Operator);
    $package->markShipped(shipResponse('SECOND', 6.00), PostageSource::CarrierAccount);

    $labels = $package->labels()->orderBy('id')->get();

    expect($labels)->toHaveCount(2)
        ->and($labels[0]->tracking_number)->toBe('FIRST')
        ->and($labels[0]->isVoided())->toBeTrue()
        ->and($labels[1]->tracking_number)->toBe('SECOND')
        ->and($labels[1]->isVoided())->toBeFalse()
        ->and($package->refresh()->tracking_number)->toBe('SECOND');

    expectProjectionToMatchLabel($package);
});

// --- recordInferredService() ----------------------------------------------

it('upgrades the active label together with the package when a service is inferred', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    expect($package->recordInferredService(ServiceInference::resolved('USPS Ground Advantage', 'usps-impb-stc', '2026-09-06')))->toBeTrue();

    $label = $package->activeLabel;

    expect($label->service)->toBe('USPS Ground Advantage')
        ->and($label->service_evidence)->toBe(ServiceEvidence::Inferred)
        ->and($label->service_inference_method)->toBe('usps-impb-stc')
        ->and($label->service_ruleset_version)->toBe('2026-09-06');
});

it('leaves both rows alone when the inference is refused', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Confirmed,
    ]);

    expect($package->recordInferredService(ServiceInference::resolved('Ground', 'usps-impb-stc', '2026-09-06')))->toBeFalse()
        ->and($package->activeLabel->service)->toBe('Priority Mail')
        ->and($package->activeLabel->service_evidence)->toBe(ServiceEvidence::Confirmed);
});

it('refuses to infer a service onto an unshipped package', function (): void {
    $package = Package::factory()->create([
        'carrier' => 'USPS',
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    expect($package->recordInferredService(ServiceInference::resolved('Ground', 'usps-impb-stc', '2026-09-06')))->toBeFalse()
        ->and($package->fresh()->service)->toBeNull();
});

// --- markLabelPrinted() ----------------------------------------------------

it('stamps the package and the active label with one print timestamp', function (): void {
    $package = Package::factory()->shipped()->create(['label_printed_at' => null]);

    app(PackageLabelWorkflow::class)->markLabelPrinted($package, User::factory()->create());

    $package->refresh();

    expect($package->label_printed_at)->not->toBeNull()
        ->and($package->activeLabel->last_printed_at->toDateTimeString())->toBe($package->label_printed_at->toDateTimeString());
});

it('refuses to record a print on a package with no active label, and rolls back', function (): void {
    $package = Package::factory()->shipped()->create(['label_printed_at' => null]);
    PackageLabel::query()->where('package_id', $package->id)->delete();

    expect(fn () => app(PackageLabelWorkflow::class)->markLabelPrinted($package))->toThrow(LogicException::class);

    expect($package->fresh()->label_printed_at)->toBeNull();
});

// --- the invariant ---------------------------------------------------------

it('rolls a writer back when it would leave the label state inconsistent', function (): void {
    // A second active label planted underneath the writer, on an engine that
    // lets one through: SQLite and MySQL both enforce the unique index, so the
    // planted row has to be voided first and un-voided after the guard.
    $package = Package::factory()->create();
    $package->markShipped(shipResponse(), PostageSource::CarrierAccount);

    // Break the invariant behind the writer's back: void the package's own
    // label without touching its status.
    PackageLabel::query()->where('package_id', $package->id)->update(['voided_at' => now()]);

    expect(fn () => app(PackageLabelWorkflow::class)->markLabelPrinted($package->fresh()))
        ->toThrow(LogicException::class);

    expect($package->fresh()->label_printed_at)->toBeNull();
});

it('accepts an unshipped package with projected values set', function (): void {
    $package = Package::factory()->withLabel()->create();

    $package->assertLabelStateIsConsistent();

    expect($package->labels()->count())->toBe(0);
});

it('creates a label row for a fixture with a bare shipped status', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Shipped]);

    expect($package->activeLabel)->not->toBeNull()
        ->and($package->activeLabel->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->activeLabel->label_format)->toBe('pdf');
});

it('creates a label row for the shipped factory state from its own columns', function (): void {
    $package = Package::factory()->shipped()->create(['tracking_number' => 'ABC123', 'cost' => 9.99]);

    expect($package->activeLabel->tracking_number)->toBe('ABC123')
        ->and((float) $package->activeLabel->cost)->toBe(9.99)
        ->and($package->activeLabel->purchased_by_user_id)->toBe($package->shipped_by_user_id);
});

// --- pointers --------------------------------------------------------------

it('nulls the label pointer along with the package pointer when a carrier account is deleted', function (): void {
    $account = CarrierAccount::factory()->create();
    $package = Package::factory()->shipped()->create(['carrier_account_id' => $account->id]);

    $account->delete();

    expect($package->fresh()->carrier_account_id)->toBeNull()
        ->and($package->activeLabel->carrier_account_id)->toBeNull();
});

it('nulls the label pointer along with the package pointer when a data source is deleted', function (): void {
    $source = DataSource::factory()->create();
    $package = Package::factory()->shipped()->create([
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => $source->id,
    ]);

    $source->delete();

    expect($package->fresh()->postage_data_source_id)->toBeNull()
        ->and($package->activeLabel->postage_data_source_id)->toBeNull();
});

it('lets the users who bought and voided a label be deleted, keeping the times', function (): void {
    $buyer = User::factory()->create();
    $voider = User::factory()->create();
    $package = Package::factory()->shipped()->create(['shipped_by_user_id' => $buyer->id]);
    $package->clearShipping(VoidReason::Operator, $voider->id);

    $buyer->delete();
    $voider->delete();

    $label = $package->labels()->sole();

    expect($label->purchased_by_user_id)->toBeNull()
        ->and($label->voided_by_user_id)->toBeNull()
        ->and($label->purchased_at)->not->toBeNull()
        ->and($label->voided_at)->not->toBeNull();
});

// --- the database guard and the cascade (run on MySQL in CI too) -----------

it('refuses a second unvoided label for the same package', function (): void {
    $package = Package::factory()->shipped()->create();

    expect(fn () => PackageLabel::factory()->active()->create(['package_id' => $package->id]))
        ->toThrow(QueryException::class);
})->group('mysql');

it('lets voided labels pile up beside the active one', function (): void {
    $package = Package::factory()->shipped()->create();
    PackageLabel::factory()->count(3)->voided()->create(['package_id' => $package->id]);

    expect($package->labels()->count())->toBe(4)
        ->and($package->labels()->active()->count())->toBe(1);
})->group('mysql');

it('deletes every label row with its package', function (): void {
    $package = Package::factory()->shipped()->create();
    $package->clearShipping(VoidReason::Operator);
    $package->markShipped(shipResponse('AGAIN'), PostageSource::CarrierAccount);

    expect(PackageLabel::query()->where('package_id', $package->id)->count())->toBe(2);

    $packageId = $package->id;
    $package->delete();

    expect(PackageLabel::query()->where('package_id', $packageId)->count())->toBe(0);
})->group('mysql');

it('backfills one label row per shipped package and nothing for the rest, idempotently', function (): void {
    $shipped = Package::factory()->shipped()->create([
        'label_printed_at' => now()->subHour(),
        'metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/7'],
    ]);
    $unshipped = Package::factory()->create();
    PackageLabel::query()->delete();

    $migration = require base_path('database/migrations/2026_09_14_000100_backfill_package_labels.php');
    $migration->up();
    $migration->up();

    $labels = PackageLabel::query()->get();

    expect($labels)->toHaveCount(1)
        ->and($labels->sole()->package_id)->toBe($shipped->id)
        ->and($labels->sole()->tracking_number)->toBe($shipped->tracking_number)
        ->and($labels->sole()->source_label_reference)->toBe('gid://shopify/ShippingLabel/7')
        ->and($labels->sole()->last_printed_at->toDateTimeString())->toBe($shipped->label_printed_at->toDateTimeString())
        ->and($labels->sole()->purchased_by_user_id)->toBe($shipped->shipped_by_user_id)
        ->and($unshipped->labels()->count())->toBe(0);
})->group('mysql');

it('is ready to ship a package the backfill covered', function (): void {
    $package = Package::factory()->shipped()->create();
    PackageLabel::query()->delete();

    (require base_path('database/migrations/2026_09_14_000100_backfill_package_labels.php'))->up();

    $package->clearShipping(VoidReason::Operator);

    expect($package->labels()->sole()->isVoided())->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

// --- Shipment relationship -------------------------------------------------

it('reaches a label\'s shipment through its package', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create();

    expect($package->activeLabel->package->shipment->is($shipment))->toBeTrue();
});
