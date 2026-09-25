<?php

use App\Enums\PostageSource;
use App\Filament\Pages\EndOfDay;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Models\Carrier;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Freeze time to a Wednesday at 10 AM to avoid USPS 8 PM cutoff and weekend issues
    $this->travelTo(now()->next('Wednesday')->setTime(10, 0));
    $this->actingAs(User::factory()->admin()->create());
});

function fakeUspsManifestResponse(int $carrierAccountId): void
{
    $boundary = 'test-boundary';
    $jsonPart = json_encode(['manifestNumber' => 'MN12345', 'trackingNumbers' => ['9400111']]);
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

    // Pre-cache a fake authenticator so no OAuth request is made
    Cache::put("usps_authenticator:{$carrierAccountId}", [
        'access_token' => 'fake-test-token',
        'refresh_token' => null,
        'expires_at' => (new DateTimeImmutable('+1 hour'))->getTimestamp(),
    ], 3600);

    Saloon::fake([
        ScanForm::class => MockResponse::make(
            body: $multipartBody,
            status: 200,
            headers: ['Content-Type' => "multipart/mixed; boundary={$boundary}"],
        ),
    ]);
}

it('generateManifest creates manifest and dispatches print event', function (): void {
    $account = createUspsAccount();

    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $account->id,
        'tracking_number' => '9400111899223456789012',
    ]);

    fakeUspsManifestResponse($account->id);

    Livewire::test(EndOfDay::class)
        ->call('generateManifest', Carrier::where('name', Carrier::USPS)->value('id'))
        ->assertDispatched('print-report')
        ->assertNotified();
});

it('generateManifest shows error on failure', function (): void {
    Carrier::factory()->create(['name' => Carrier::FEDEX]);

    Package::factory()->shipped()->create([
        'carrier' => 'FedEx',
        'tracking_number' => '789000100001',
    ]);

    Livewire::test(EndOfDay::class)
        ->call('generateManifest', Carrier::where('name', Carrier::FEDEX)->value('id'))
        ->assertNotDispatched('print-report')
        ->assertNotified();
});

it('generateManifest shows warning when no packages for carrier', function (): void {
    Carrier::factory()->usps()->create();

    Livewire::test(EndOfDay::class)
        ->call('generateManifest', Carrier::where('name', Carrier::USPS)->value('id'))
        ->assertNotDispatched('print-report')
        ->assertNotified();
});

it('does not manifest a USPS package bought through Shopify', function (): void {
    Saloon::fake([]);
    Carrier::factory()->usps()->create();

    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => null,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory(),
    ]);

    Livewire::test(EndOfDay::class)
        ->call('generateManifest', Carrier::where('name', Carrier::USPS)->value('id'))
        ->assertNotDispatched('print-report')
        ->assertNotified();

    Saloon::assertNothingSent();
});

it('generateManifest suppresses printing when suppress_printing setting is true', function (): void {
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();
    $account = createUspsAccount();

    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $account->id,
        'tracking_number' => '9400111899223456789012',
    ]);

    fakeUspsManifestResponse($account->id);

    Livewire::test(EndOfDay::class)
        ->call('generateManifest', Carrier::where('name', Carrier::USPS)->value('id'))
        ->assertNotDispatched('print-report')
        ->assertNotified();
});
