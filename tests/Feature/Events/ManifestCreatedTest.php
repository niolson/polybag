<?php

use App\Events\ManifestCreated;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Models\Package;
use App\Services\ManifestService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

it('dispatches ManifestCreated after successful USPS manifest creation', function (): void {
    Event::fake([ManifestCreated::class]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $boundary = 'test-boundary';
    $jsonPart = json_encode(['manifestNumber' => 'MN12345', 'trackingNumbers' => ['9400111899223456789012']]);
    $pdfPart = base64_encode('fake-manifest-pdf');

    $multipartBody = "--{$boundary}\r\n"
        ."Content-Type: application/json\r\n"
        ."\r\n"
        ."{$jsonPart}\r\n"
        ."--{$boundary}\r\n"
        ."Content-Type: application/pdf\r\n"
        ."\r\n"
        ."{$pdfPart}\r\n"
        ."--{$boundary}--";

    Cache::put('usps_authenticator', new AccessTokenAuthenticator(
        accessToken: 'fake-test-token',
        expiresAt: new \DateTimeImmutable('+1 hour'),
    ), 3600);

    Saloon::fake([
        ScanForm::class => MockResponse::make(
            body: $multipartBody,
            status: 200,
            headers: ['Content-Type' => "multipart/mixed; boundary={$boundary}"],
        ),
    ]);

    // Need from_address config for scan form
    config([
        'shipping.from_address' => [
            'first_name' => 'Test',
            'last_name' => 'Shipper',
            'street_address' => '123 Main St',
            'city' => 'Anytown',
            'state_or_province' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
        ],
    ]);

    $packages = Package::where('carrier', 'USPS')
        ->where('shipped', true)
        ->where('manifested', false)
        ->get();

    $result = app(ManifestService::class)->createManifest('USPS', $packages);

    expect($result->success)->toBeTrue();

    Event::assertDispatched(ManifestCreated::class, function (ManifestCreated $event): bool {
        return $event->packageCount === 1
            && $event->manifest->carrier === 'USPS';
    });
});
