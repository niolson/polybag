<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageLabelWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Pages\Reports\RateComparison;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Product;
use App\Models\RateQuote;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\RateQuoteLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => app(CarrierRegistry::class)->reset());
afterEach(fn () => app(CarrierRegistry::class)->reset());

/**
 * A shipped Package whose active Label was bought from the first of one quote
 * session's quotes.
 *
 * @param  list<array{string, float}>  $quotes  carrier and price; the first is the one bought
 */
function packageBoughtFromQuotes(array $quotes): Package
{
    $package = Package::factory()->shipped()->create(['shipped_at' => now()]);
    $quotedAt = now()->subMinutes(5)->startOfSecond();

    $quoteIds = array_map(fn (array $quote): int => RateQuote::factory()->create([
        'package_id' => $package->id, 'carrier' => $quote[0], 'quoted_price' => $quote[1], 'created_at' => $quotedAt,
    ])->id, $quotes);

    PackageLabel::query()->where('package_id', $package->id)->whereNull('voided_at')->update(['rate_quote_id' => $quoteIds[0]]);

    return $package;
}

it('renders rate comparison page', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(RateComparison::class)
        ->assertOk()
        ->assertSee('Rate Comparison');
});

it('shows packages with multiple rate quotes', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $package = packageBoughtFromQuotes([['USPS', 8.50], ['FedEx', 12.00]]);

    Livewire::actingAs($user)
        ->test(RateComparison::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$package]);
});

it('calculates total potential savings', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    packageBoughtFromQuotes([['FedEx', 15.00], ['USPS', 10.00]]);

    $component = Livewire::actingAs($user)->test(RateComparison::class);

    // The total potential savings should reflect the difference
    expect($component->instance()->getTotalPotentialSavings())->toBe(5.0);
});

it('restricts access to managers and above', function (): void {
    $user = User::factory()->create(['role' => Role::Packer]);
    $this->actingAs($user);

    expect(RateComparison::canAccess())->toBeFalse();

    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);

    expect(RateComparison::canAccess())->toBeTrue();
});

it('labels the savings with its period, its share of spend and how it is counted', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    packageBoughtFromQuotes([['FedEx', 15.00], ['USPS', 10.00]]);

    $component = Livewire::actingAs($user)->test(RateComparison::class);

    $component
        ->assertSee('Last 30 days')
        ->assertSee('33.3% of the $15.00 spent on postage for 1 compared packages')
        ->assertSee('whatever delivery time that cheaper rate offered');
});

it('leaves out a Label bought from a single quote, and one with no quote behind it', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $onlyRate = packageBoughtFromQuotes([['USPS', 8.50]]);
    $blind = Package::factory()->shipped()->create(['shipped_at' => now()]);
    RateQuote::factory()->create(['package_id' => $blind->id, 'carrier' => 'USPS', 'quoted_price' => 8.50, 'selected' => true]);
    RateQuote::factory()->create(['package_id' => $blind->id, 'carrier' => 'FedEx', 'quoted_price' => 12.00]);

    Livewire::actingAs($user)
        ->test(RateComparison::class)
        ->assertCanNotSeeTableRecords([$onlyRate, $blind]);
});

it('compares the active Label with its own quote session after a void and re-buy', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $product = Product::factory()->create(['weight' => 1.5]);
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $method = ShippingMethod::factory()->create();
    foreach (['GROUND' => 'Ground', 'EXPRESS' => 'Express'] as $code => $name) {
        $method->carrierServices()->attach(CarrierService::factory()->create([
            'carrier_id' => $carrier->id, 'name' => $name, 'service_code' => $code, 'active' => true,
        ])->id);
    }
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id, 'quantity' => 1]);
    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0, 'height' => 10, 'width' => 8, 'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);
    $package->packageItems()->create(['shipment_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 1]);

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->twice()->andReturn(
        ShipResponse::success(trackingNumber: 'TRACK-FIRST', cost: 7.25, carrier: 'MockCarrier', service: 'Ground', labelData: base64_encode('LABEL'), labelFormat: 'pdf'),
        ShipResponse::success(trackingNumber: 'TRACK-SECOND', cost: 11.00, carrier: 'MockCarrier', service: 'Express', labelData: base64_encode('LABEL'), labelFormat: 'pdf'),
    );
    $adapter->shouldReceive('cancelShipment')->once()->andReturn(CancelResponse::success('Label voided.'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    // Quote a session through the log, as rate shopping does, and buy one of its rates.
    $buy = function (array $session, int $bought) use ($package): void {
        $rates = collect($session)->map(fn (array $rate): RateResponse => new RateResponse(...$rate));
        $quoteIds = app(RateQuoteLogger::class)->logRates($package->id, $rates);
        $offer = ShippingOffer::factory()->direct()->forRate($rates[$bought])
            ->quotedFor(Package::with('shipment')->find($package->id))
            ->create(['rate_quote_id' => $quoteIds[$bought]]);

        $result = app(PackageShippingWorkflow::class)->ship(
            $package->fresh(),
            new PackageShippingRequest(selectedRate: RateResponse::fromArray($rates[$bought]->withOfferId($offer->public_id)->toArray())),
        );

        expect($result->success)->toBeTrue();
    };

    // First session: Ground bought at $7.25, beside a cheaper $6.00 elsewhere.
    $this->travelTo(now()->subHour());
    $buy([
        ['MockCarrier', 'GROUND', 'Ground', 7.25, '3 days'],
        ['MockCarrier', 'EXPRESS', 'Express', 12.00, '1 day'],
        ['CheapCarrier', 'SAVER', 'Saver', 6.00, '5 days'],
    ], 0);
    $voided = app(PackageLabelWorkflow::class)->voidLabel($package->fresh(), User::factory()->manager()->create());
    expect($voided->success)->toBeTrue();
    $this->travelBack();

    // A day's surcharges later: Express bought at $11.00, where Ground was $9.00.
    $buy([
        ['MockCarrier', 'GROUND', 'Ground', 9.00, '3 days'],
        ['MockCarrier', 'EXPRESS', 'Express', 11.00, '1 day'],
    ], 1);

    // The voided Label's quote is still selected; only the active Label's counts.
    expect(RateQuote::query()->where('package_id', $package->id)->where('selected', true)->count())->toBe(2);

    $component = Livewire::actingAs($user)->test(RateComparison::class)
        ->assertCanSeeTableRecords([$package])
        ->assertSeeInOrder(['MockCarrier — $11.00', 'MockCarrier — $9.00', '$2.00'])
        ->assertDontSee('CheapCarrier')
        ->assertDontSee('$7.25')
        ->assertDontSee('$6.00');

    expect($component->instance()->getSavingsSummary())->toBe(['savings' => 2.0, 'spend' => 11.0, 'packages' => 1]);
})->group('mysql');
