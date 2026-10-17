<?php

use App\Enums\PostageSource;
use App\Filament\Pages\EndOfDay;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Manifest;
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

/**
 * How many `print-report` events the End of Day page dispatched.
 */
function endOfDayPrintReportCount(mixed $page): int
{
    return collect($page->effects['dispatches'] ?? [])->where('name', 'print-report')->count();
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

it('puts labels still dated today on a form for today once the cutoff has passed', function (): void {
    $account = createUspsAccount();
    $today = now('America/New_York')->toDateString();

    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $account->id,
        'tracking_number' => '9400111899223456789012',
        'ship_date' => $today,
    ]);

    $this->travelTo(now('America/New_York')->setTime(20, 30));
    fakeUspsManifestResponse($account->id);

    Livewire::test(EndOfDay::class)
        ->call('generateManifest', Carrier::where('name', Carrier::USPS)->value('id'))
        ->assertNotified('Manifest Created');

    Saloon::assertSent(fn ($request): bool => $request instanceof ScanForm
        && $request->body()->all()['mailingDate'] === $today);
    expect(Package::first()->manifest_id)->not->toBeNull();
});

it('Generate Manifest saves the form without printing it', function (): void {
    $account = createUspsAccount();

    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $account->id,
        'tracking_number' => '9400111899223456789012',
    ]);

    fakeUspsManifestResponse($account->id);

    Livewire::test(EndOfDay::class)
        ->call('generateManifest', Carrier::where('name', Carrier::USPS)->value('id'), false)
        ->assertNotDispatched('print-report')
        ->assertNotified('Manifest Created');

    expect(Manifest::count())->toBe(1)
        ->and(Manifest::first()->image)->not->toBeEmpty();
});

it('Generate & Print prints every SCAN form a run across two USPS accounts makes', function (): void {
    $carrier = Carrier::where('name', Carrier::USPS)->first() ?? Carrier::factory()->usps()->create();
    $makeAccount = fn (): CarrierAccount => CarrierAccount::create([
        'carrier_id' => $carrier->id,
        'name' => 'USPS '.fake()->unique()->numerify('Account-####'),
        'active' => true,
        'credentials' => ['crid' => 'test_crid'],
        'secret_credentials' => ['client_id' => 'test_client_id', 'client_secret' => 'test_client_secret'],
    ]);
    $accountA = $makeAccount();
    $accountB = $makeAccount();

    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $accountA->id,
        'tracking_number' => '9400111111111111111111',
    ]);
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $accountB->id,
        'tracking_number' => '9400222222222222222222',
    ]);

    fakeUspsManifestResponse($accountA->id);
    Cache::put("usps_authenticator:{$accountB->id}", Cache::get("usps_authenticator:{$accountA->id}"), 3600);

    $page = Livewire::test(EndOfDay::class)
        ->call('generateManifest', $carrier->id, true)
        ->assertNotified('Manifest Created');

    expect(endOfDayPrintReportCount($page))->toBe(2)
        ->and(Manifest::count())->toBe(2)
        ->and(Manifest::whereNull('image')->count())->toBe(0);
});

it('hides Generate & Print when printing is suppressed', function (): void {
    $account = createUspsAccount();

    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $account->id,
        'tracking_number' => '9400111899223456789012',
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSee('Generate Manifest')
        ->assertSee('Generate &amp; Print', escape: false);

    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Livewire::test(EndOfDay::class)
        ->assertSee('Generate Manifest')
        ->assertDontSee('Generate &amp; Print', escape: false);
});

it('shows Reprint Manifest for a form with an image and No image for one without', function (): void {
    Manifest::create([
        'carrier' => Carrier::USPS,
        'manifest_number' => 'MN-WITH-IMAGE',
        'image' => base64_encode('fake-manifest-pdf'),
        'manifest_date' => now()->toDateString(),
        'package_count' => 1,
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSee('Reprint Manifest')
        ->assertDontSee('No image');

    Manifest::query()->delete();
    Manifest::create([
        'carrier' => Carrier::USPS,
        'manifest_number' => 'EXTERNAL-20261017100000',
        'manifest_date' => now()->toDateString(),
        'package_count' => 1,
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSee('No image')
        ->assertDontSee('Reprint Manifest');
});
