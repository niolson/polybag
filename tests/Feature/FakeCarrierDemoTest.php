<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Events\ManifestCreated;
use App\Filament\Pages\EndOfDay;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\ManifestService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * The demo fallback runs on fake carriers from start to finish: a label bought
 * through the real purchase workflow prints the order's address, and End of
 * Day produces a sample form without calling USPS.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    Cache::flush();
    // A Wednesday morning, clear of the USPS cutoff and the weekend.
    $this->travelTo(now()->next('Wednesday')->setTime(10, 0));
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * An unshipped Package for a Springfield order, on a method that buys USPS
 * Priority Mail from the fake USPS adapter.
 */
function demoPackageOnFakeUsps(): Package
{
    $carrier = Carrier::factory()->usps()->create(['active' => true]);
    $account = CarrierAccount::factory()->create(['carrier_id' => $carrier->id, 'active' => true]);
    CarrierAccountScope::factory()->forAccount($account)->global()->create();

    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Priority Mail',
        'service_code' => 'PRIORITY_MAIL',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);

    $product = Product::factory()->create(['weight' => 1.5]);
    $shipment = Shipment::factory()->create([
        'shipping_method_id' => $method->id,
        'first_name' => 'Marge',
        'last_name' => 'Simpson',
        'company' => null,
        'address1' => '742 Evergreen Terrace',
        'address2' => null,
        'city' => 'Springfield',
        'state_or_province' => 'OR',
        'postal_code' => '97477',
        'country' => 'US',
    ]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);
    $package->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    app(CarrierRegistry::class)->registerInstance(Carrier::USPS, new FakeCarrierAdapter(Carrier::USPS));

    return $package;
}

it('buys a ZPL label on the fake carrier that prints the order\'s address and tracking number', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = demoPackageOnFakeUsps();

    $rate = RateResponse::fromArray(app(PackageShippingWorkflow::class)->prepareRates($package)->rateOptions[0]);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $rate, labelFormat: 'zpl', labelDpi: 300, userId: $user->id),
    );

    $package->refresh();
    $zpl = base64_decode($package->label_data, true);

    expect($result->success)->toBeTrue()
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->label_format)->toBe('zpl')
        ->and($package->label_dpi)->toBe(300)
        ->and($zpl)->toStartWith('^XA')
        ->toContain('^PW1200')
        ->toContain('^FDMARGE SIMPSON^FS')
        ->toContain('^FD742 EVERGREEN TERRACE^FS')
        ->toContain('^FDSPRINGFIELD, OR 97477^FS')
        ->toContain('SAMPLE')
        ->and(str_replace(' ', '', $zpl))->toContain($package->tracking_number);
});

it('creates a sample manifest through the fake carrier without calling USPS', function (): void {
    Event::fake([ManifestCreated::class]);
    Saloon::fake([]);
    app(CarrierRegistry::class)->registerInstance(Carrier::USPS, new FakeCarrierAdapter(Carrier::USPS));

    $packages = Package::factory()->usps()->count(2)->create(['carrier_account_id' => null]);

    $result = app(ManifestService::class)->createManifest(Carrier::USPS, $packages);
    $manifest = Manifest::sole();
    $form = base64_decode((string) $result->image, true);

    expect($result->success)->toBeTrue()
        ->and($result->manifestNumber)->toStartWith('SAMPLE-')
        ->and($form)->toStartWith('%PDF-')
        ->and($manifest->manifest_number)->toBe($result->manifestNumber)
        ->and($manifest->carrier)->toBe(Carrier::USPS)
        ->and($manifest->package_count)->toBe(2)
        ->and($manifest->image)->toBe($result->image)
        ->and($packages->map(fn (Package $package): ?int => $package->fresh()->manifest_id)->unique()->all())->toBe([$manifest->id]);

    foreach ($packages as $package) {
        expect($form)->toContain($package->tracking_number);
    }

    Saloon::assertNothingSent();
    Event::assertDispatched(ManifestCreated::class, fn (ManifestCreated $event): bool => $event->packageCount === 2);
});

it('offers the sample form on End of Day and sends it to the document printer', function (): void {
    Saloon::fake([]);
    $this->actingAs(User::factory()->admin()->create());
    app(CarrierRegistry::class)->registerInstance(Carrier::USPS, new FakeCarrierAdapter(Carrier::USPS));

    $carrier = Carrier::factory()->usps()->create(['active' => true]);
    Package::factory()->usps()->create(['carrier_account_id' => null]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary.0.supports_manifest', true)
        ->assertSet('carrierSummary.0.unmanifested_count', 1)
        ->call('generateManifest', $carrier->id)
        ->assertDispatched('print-report')
        ->assertNotified('Manifest Created');

    expect(Manifest::sole()->manifest_number)->toStartWith('SAMPLE-');
    Saloon::assertNothingSent();
});

it('still sends a USPS SCAN form request when fake carriers are off', function (): void {
    $account = createUspsAccount();
    $package = Package::factory()->usps()->create(['carrier_account_id' => $account->id]);

    Cache::put("usps_authenticator:{$account->id}", [
        'access_token' => 'fake-test-token',
        'refresh_token' => null,
        'expires_at' => (new DateTimeImmutable('+1 hour'))->getTimestamp(),
    ], 3600);

    $boundary = 'test-boundary';
    Saloon::fake([
        ScanForm::class => MockResponse::make(
            body: "--{$boundary}\r\nContent-Type: application/json\r\n\r\n"
                .json_encode(['manifestNumber' => 'MN12345'])
                ."\r\n--{$boundary}\r\nContent-Type: application/pdf\r\n\r\n"
                .base64_encode('usps-form')."\r\n--{$boundary}--",
            status: 200,
            headers: ['Content-Type' => "multipart/mixed; boundary={$boundary}"],
        ),
    ]);

    $result = app(ManifestService::class)->createManifest(Carrier::USPS, collect([$package]));

    expect($result->success)->toBeTrue()
        ->and($result->manifestNumber)->toBe('MN12345');

    Saloon::assertSent(ScanForm::class);
});
