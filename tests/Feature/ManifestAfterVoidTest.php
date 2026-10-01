<?php

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PostageSource;
use App\Enums\VoidReason;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Services\ManifestService;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * A SCAN form reply naming the given tracking numbers.
 */
function scanFormReply(string $manifestNumber, string ...$trackingNumbers): MockResponse
{
    $boundary = 'test-boundary';
    $json = json_encode(['manifestNumber' => $manifestNumber, 'trackingNumbers' => $trackingNumbers]);

    return MockResponse::make(
        body: "--{$boundary}\r\nContent-Type: application/json\r\n\r\n{$json}\r\n"
            ."--{$boundary}\r\nContent-Type: application/pdf\r\n\r\n".base64_encode('pdf')."\r\n--{$boundary}--",
        status: 200,
        headers: ['Content-Type' => "multipart/mixed; boundary={$boundary}"],
    );
}

function manifestablePackage(string $trackingNumber): Package
{
    $account = createUspsAccount();

    Cache::put("usps_authenticator:{$account->id}", [
        'access_token' => 'fake-test-token',
        'refresh_token' => null,
        'expires_at' => (new DateTimeImmutable('+1 hour'))->getTimestamp(),
    ], 3600);

    Location::factory()->default()->create([
        'first_name' => 'Test',
        'last_name' => 'Shipper',
        'address1' => '123 Main St',
        'city' => 'Anytown',
        'state_or_province' => 'NY',
        'postal_code' => '10001',
    ]);

    return Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $account->id,
        'postage_source' => PostageSource::CarrierAccount,
        'tracking_number' => $trackingNumber,
    ]);
}

it('puts a label re-shipped after a void on the next manifest', function (): void {
    $package = Package::factory()->shipped()->for(Shipment::factory())->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400100000000000000001',
        'postage_source' => PostageSource::CarrierAccount,
    ]);
    $manifest = Manifest::create([
        'carrier' => 'USPS', 'manifest_number' => 'M1', 'manifest_date' => now()->toDateString(), 'package_count' => 1,
    ]);
    $package->forceFill(['manifest_id' => $manifest->id])->save();
    PackageLabel::query()->where('package_id', $package->id)->update(['manifest_id' => $manifest->id]);

    $package->clearShipping(VoidReason::Operator);
    $package->markShipped(
        new ShipResponse(success: true, trackingNumber: '9400100000000000000002', cost: 5.0, carrier: 'USPS', service: 'Ground Advantage', labelData: 'x'),
        PostageSource::CarrierAccount,
    );

    $voided = PackageLabel::query()->where('package_id', $package->id)->whereNotNull('voided_at')->sole();

    expect($package->fresh()->manifest_id)->toBeNull()
        ->and($voided->manifest_id)->toBe($manifest->id)
        ->and(app(ManifestService::class)->getUnmanifestedPackages()->flatten()->pluck('id')->all())
        ->toContain($package->id);
});

it('puts the package and its active label on the SCAN form together', function (): void {
    $package = manifestablePackage('9400100000000000000011');
    Saloon::fake([ScanForm::class => scanFormReply('MN1', '9400100000000000000011')]);

    $result = app(ManifestService::class)->createManifest('USPS', collect([$package]));

    $manifestId = $package->fresh()->manifest_id;

    expect($result->success)->toBeTrue()
        ->and($manifestId)->not->toBeNull()
        ->and(PackageLabel::query()->where('package_id', $package->id)->whereNull('voided_at')->value('manifest_id'))
        ->toBe($manifestId);
});

it('does not stamp a package voided while USPS was answering', function (): void {
    $package = manifestablePackage('9400100000000000000021');

    Saloon::fake([
        ScanForm::class => function () use ($package): MockResponse {
            $package->fresh()->clearShipping(VoidReason::Operator);

            return scanFormReply('MN2', '9400100000000000000021');
        },
    ]);

    app(ManifestService::class)->createManifest('USPS', collect([$package]));

    expect($package->fresh()->manifest_id)->toBeNull()
        ->and(PackageLabel::query()->where('package_id', $package->id)->value('manifest_id'))->toBeNull();
});
