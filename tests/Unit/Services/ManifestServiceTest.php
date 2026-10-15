<?php

use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Manifest;
use App\Models\Package;
use App\Services\ManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        ->and($result->errorMessage)->toContain('Unsupported carrier');
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

it('fails the USPS manifest when a package has no resolvable carrier account', function (): void {
    // Shipped package with no stored account and no carrier account configured to resolve.
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
        ->and($result->errorMessage)->toContain('HTTP 503')
        ->and($result->errorMessage)->toContain('Service Unavailable')
        ->and($result->errorMessage)->not->toContain('Syntax error');
});
