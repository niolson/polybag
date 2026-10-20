<?php

use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\ScanFormRequest;
use App\Services\ManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

function fakeUspsScanForm(array $carrierAccountIds): void
{
    $boundary = 'test-boundary';
    $jsonPart = json_encode(['manifestNumber' => 'MN12345', 'trackingNumbers' => ['9400111']]);
    $pdfPart = base64_encode('fake-manifest-pdf');

    $multipartBody = "--{$boundary}\r\n"
        ."Content-Type: application/json\r\n\r\n{$jsonPart}\r\n"
        ."--{$boundary}\r\n"
        ."Content-Type: application/pdf\r\n\r\n{$pdfPart}\r\n"
        ."--{$boundary}--";

    // Pre-cache a fake authenticator per account so no OAuth request is made.
    foreach ($carrierAccountIds as $id) {
        Cache::put("usps_authenticator:{$id}", [
            'access_token' => 'fake-test-token',
            'refresh_token' => null,
            'expires_at' => (new DateTimeImmutable('+1 hour'))->getTimestamp(),
        ], 3600);
    }

    Saloon::fake([
        ScanForm::class => MockResponse::make(
            body: $multipartBody,
            status: 200,
            headers: ['Content-Type' => "multipart/mixed; boundary={$boundary}"],
        ),
    ]);
}

it('returns unmanifested packages grouped by carrier', function (): void {
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111']);
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400222']);
    Package::factory()->shipped()->create(['carrier' => 'FedEx', 'tracking_number' => '7890001']);

    $grouped = app(ManifestService::class)->getUnmanifestedPackages();

    expect($grouped)->toHaveKey('USPS')
        ->and($grouped)->toHaveKey('FedEx')
        ->and($grouped['USPS'])->toHaveCount(2)
        ->and($grouped['FedEx'])->toHaveCount(1);
});

it('excludes a USPS package whose postage was bought through Shopify', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'carrier_account_id' => null,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory(),
    ]);

    $grouped = app(ManifestService::class)->getUnmanifestedPackages();

    expect($grouped)->toBeEmpty()
        ->and($package->carrier)->toBe('USPS');
});

it('refuses to manifest a Shopify-bought package handed to it directly', function (): void {
    // The queries that feed createManifest() filter on provenance, but the
    // guard is what protects the USPS request itself from a caller that
    // assembled its own collection.
    Saloon::fake([]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'carrier_account_id' => null,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory(),
    ]);

    $response = app(ManifestService::class)->createManifest('USPS', collect([$package]));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('not bought on a carrier account of ours');

    Saloon::assertNothingSent();
});

it('excludes already manifested packages', function (): void {
    $manifest = Manifest::factory()->create();
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'manifest_id' => $manifest->id,
    ]);
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400222',
    ]);

    $grouped = app(ManifestService::class)->getUnmanifestedPackages();

    expect($grouped['USPS'])->toHaveCount(1);
});

it('excludes unshipped packages', function (): void {
    Package::factory()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'status' => PackageStatus::Unshipped,
    ]);

    $grouped = app(ManifestService::class)->getUnmanifestedPackages();

    expect($grouped)->toBeEmpty();
});

it('excludes packages without tracking numbers', function (): void {
    Package::factory()->create([
        'carrier' => 'USPS',
        'tracking_number' => null,
        'status' => PackageStatus::Shipped,
        'shipped_at' => now(),
    ]);

    $grouped = app(ManifestService::class)->getUnmanifestedPackages();

    expect($grouped)->toBeEmpty();
});

it('returns failure for unsupported carrier', function (): void {
    $packages = collect([Package::factory()->shipped()->create(['carrier' => 'DHL'])]);

    $result = app(ManifestService::class)->createManifest('DHL', $packages);

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('does not support end-of-day manifests');
});

it('returns failure for FedEx manifest stub', function (): void {
    $packages = collect([Package::factory()->shipped()->create(['carrier' => 'FedEx'])]);

    $result = app(ManifestService::class)->createManifest('FedEx', $packages);

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('not yet implemented');
});

it('creates a separate USPS SCAN form per purchasing carrier account', function (): void {
    // The manifest resolves each package's stored purchasing account directly, so the
    // accounts need credentials but no scopes (avoiding the global-scope unique index).
    $carrier = Carrier::firstOrCreate(['name' => 'USPS']);
    $makeAccount = fn (): CarrierAccount => CarrierAccount::create([
        'carrier_id' => $carrier->id,
        'name' => 'USPS '.fake()->unique()->numerify('Account-####'),
        'active' => true,
        'credentials' => ['crid' => 'test_crid'],
        'secret_credentials' => ['client_id' => 'test_client_id', 'client_secret' => 'test_client_secret'],
    ]);

    $accountA = $makeAccount();
    $accountB = $makeAccount();

    $packageA = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111111111111111111',
        'carrier_account_id' => $accountA->id,
    ]);
    $packageB = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400222222222222222222',
        'carrier_account_id' => $accountB->id,
    ]);

    fakeUspsScanForm([$accountA->id, $accountB->id]);

    $result = app(ManifestService::class)->createManifest('USPS', collect([$packageA, $packageB]));

    expect($result->success)->toBeTrue();

    // One SCAN form request per purchasing account (authenticators are cached, so
    // the only requests sent are the two ScanForm calls).
    Saloon::assertSentCount(2);
    Saloon::assertSent(ScanForm::class);
});

it('fails the USPS manifest for a package whose buying account is gone, even with another account in scope', function (): void {
    // The scope-preferred account never bought this label, so it cannot tender it.
    createUspsAccount();
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400333333333333333333',
        'carrier_account_id' => null,
    ]);

    $result = app(ManifestService::class)->createManifest('USPS', collect([$package]));

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('No USPS carrier account could be resolved');
});

it('reports the status and body when USPS answers a SCAN form request with something that is not JSON', function (): void {
    $carrier = Carrier::firstOrCreate(['name' => 'USPS']);
    $account = CarrierAccount::create([
        'carrier_id' => $carrier->id,
        'name' => 'USPS Account-9001',
        'active' => true,
        'credentials' => ['crid' => 'test_crid'],
        'secret_credentials' => ['client_id' => 'test_client_id', 'client_secret' => 'test_client_secret'],
    ]);
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111111111111111111',
        'carrier_account_id' => $account->id,
    ]);

    Cache::put("usps_authenticator:{$account->id}", [
        'access_token' => 'fake-test-token',
        'refresh_token' => null,
        'expires_at' => (new DateTimeImmutable('+1 hour'))->getTimestamp(),
    ], 3600);

    Saloon::fake([
        ScanForm::class => MockResponse::make(
            body: '<html><body><h1>Service Unavailable</h1></body></html>',
            status: 503,
            headers: ['Content-Type' => 'text/html'],
        ),
    ]);

    $result = app(ManifestService::class)->createManifest('USPS', collect([$package]));

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('error 503')
        ->and($result->errorMessage)->toContain('Service Unavailable')
        ->and($result->errorMessage)->not->toContain('Syntax error');
});

/**
 * A USPS account with a cached token and one shipped package on it.
 */
function scanFormRequestPackage(): Package
{
    $carrier = Carrier::firstOrCreate(['name' => 'USPS']);
    $account = CarrierAccount::create([
        'carrier_id' => $carrier->id,
        'name' => 'USPS '.fake()->unique()->numerify('Account-####'),
        'active' => true,
        'credentials' => ['crid' => 'test_crid'],
        'secret_credentials' => ['client_id' => 'test_client_id', 'client_secret' => 'test_client_secret'],
    ]);

    Cache::put("usps_authenticator:{$account->id}", [
        'access_token' => 'fake-test-token',
        'refresh_token' => null,
        'expires_at' => (new DateTimeImmutable('+1 hour'))->getTimestamp(),
    ], 3600);

    return Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111111111111111111',
        'carrier_account_id' => $account->id,
    ]);
}

it('asks for the SCAN form image through imageInfo and sends the company as the firm', function (): void {
    $location = Location::factory()->default()->create([
        'first_name' => 'Shipping',
        'last_name' => 'Center',
        'company' => 'Acme Fulfillment',
        'address1' => '15100 Woodinville-Redmond Rd NE',
        'address2' => 'Ste A-600',
        'postal_code' => '98072',
    ]);
    $package = scanFormRequestPackage();
    fakeUspsScanForm([$package->carrier_account_id]);

    app(ManifestService::class)->createManifest('USPS', collect([$package]), null, $location->id);

    Saloon::assertSent(function (ScanForm $request): bool {
        $body = $request->body()->all();

        return $body['imageInfo'] === ['imageType' => 'PDF', 'labelType' => '8.5X11LABEL']
            && ! array_key_exists('form', $body)
            && ! array_key_exists('imageType', $body)
            && ! array_key_exists('labelType', $body)
            && $body['fromAddress']['firstName'] === 'Shipping'
            && $body['fromAddress']['lastName'] === 'Center'
            && $body['fromAddress']['firm'] === 'Acme Fulfillment'
            && $body['fromAddress']['secondaryAddress'] === 'Ste A-600';
    });
});

it('sends a UUID idempotency key with each SCAN form request', function (): void {
    $location = Location::factory()->default()->create();
    $package = scanFormRequestPackage();
    fakeUspsScanForm([$package->carrier_account_id]);

    app(ManifestService::class)->createManifest('USPS', collect([$package]), null, $location->id);

    Saloon::assertSent(function (ScanForm $request): bool {
        $key = $request->headers()->get('X-Idempotency-Key');

        return is_string($key) && Str::isUuid($key);
    });
});

it('sends the same idempotency key again when the SCAN form reply never arrived', function (): void {
    $location = Location::factory()->default()->create();
    $package = scanFormRequestPackage();
    fakeUspsScanForm([$package->carrier_account_id]);
    Saloon::fake([
        ScanForm::class => MockResponse::make(
            body: json_encode(['error' => ['code' => '400', 'message' => 'Bad Request', 'errors' => []]]),
            status: 400,
            headers: ['Content-Type' => 'application/json'],
        ),
    ]);

    $service = app(ManifestService::class);
    $service->createManifest('USPS', collect([$package]), null, $location->id);
    $service->createManifest('USPS', collect([$package]), null, $location->id);

    $keys = array_map(
        fn ($response) => $response->getPendingRequest()->headers()->get('X-Idempotency-Key'),
        Saloon::mockClient()->getRecordedResponses(),
    );

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1])
        ->and(ScanFormRequest::count())->toBe(1);
});

it('forgets the idempotency key once the SCAN form is saved', function (): void {
    $location = Location::factory()->default()->create();
    $package = scanFormRequestPackage();
    fakeUspsScanForm([$package->carrier_account_id]);

    app(ManifestService::class)->createManifest('USPS', collect([$package]), null, $location->id);

    expect(ScanFormRequest::count())->toBe(0);
});

it('uses a different idempotency key for a different set of packages', function (): void {
    $first = ScanFormRequest::forTrackingNumbers('2026-10-19', ['9400111', '9400222']);

    expect(ScanFormRequest::forTrackingNumbers('2026-10-19', ['9400222', '9400111'])->is($first))->toBeTrue()
        ->and(ScanFormRequest::forTrackingNumbers('2026-10-19', ['9400111'])->idempotency_key)->not->toBe($first->idempotency_key)
        ->and(ScanFormRequest::forTrackingNumbers('2026-10-20', ['9400111', '9400222'])->idempotency_key)->not->toBe($first->idempotency_key);
});

it('sends a single shipper name as the firm', function (): void {
    $location = Location::factory()->default()->create([
        'first_name' => 'Shipping',
        'last_name' => '',
        'company' => null,
    ]);
    $package = scanFormRequestPackage();
    fakeUspsScanForm([$package->carrier_account_id]);

    app(ManifestService::class)->createManifest('USPS', collect([$package]), null, $location->id);

    Saloon::assertSent(fn (ScanForm $request): bool => $request->body()->all()['fromAddress']['firm'] === 'Shipping'
        && ! array_key_exists('firstName', $request->body()->all()['fromAddress']));
});

it('reports the detail of each USPS error rather than its status text', function (): void {
    Location::factory()->default()->create();
    $package = scanFormRequestPackage();

    Saloon::fake([
        ScanForm::class => MockResponse::make([
            'apiVersion' => '/scan-forms/v3',
            'error' => [
                'code' => '400',
                'message' => 'Bad Request',
                'errors' => [[
                    'title' => 'Bad Request',
                    'detail' => 'The address you entered was found but more information is needed (such as an apartment, suite, or box number) to match to a specific address.',
                    'code' => '010007',
                    'source' => ['parameter' => 'fromAddress'],
                ]],
            ],
        ], 400),
    ]);

    $result = app(ManifestService::class)->createManifest('USPS', collect([$package]));

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('error 400')
        ->and($result->errorMessage)->toContain('more information is needed')
        ->and($result->errorMessage)->not->toContain('Bad Request');
});
