<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Filament\Pages\Pack;
use App\Filament\Pages\Ship;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * A workflow offering one rate whose purchase always asks for the customs
 * weight override, and records each request it was sent.
 */
function customsDialogWorkflow(?ArrayObject $requests = null): PackageShippingWorkflow
{
    return new class($requests ?? new ArrayObject) implements PackageShippingWorkflow
    {
        /** @param  ArrayObject<int, PackageShippingRequest>  $requests */
        public function __construct(private ArrayObject $requests) {}

        public function prepareRates(Package $package): PackageShippingOptions
        {
            return new PackageShippingOptions(
                rateOptions: [0 => (new RateResponse('USPS', 'PRIORITY', 'Priority Mail International', 42.10))->toArray()],
                rateOptionLabels: [0 => '[USPS] Priority Mail International'],
                rateOptionDescriptions: [0 => ''],
                deliverByDate: null,
                allRatesLate: false,
                selectedRateIndex: 0,
            );
        }

        public function ship(Package $package, PackageShippingRequest $request): PackageShippingResult
        {
            $this->requests[] = $request;

            return PackageShippingResult::customsWeightOverrideRequired(1.25, 0.3);
        }

        public function autoShip(Package $package, PackageAutoShippingRequest $request): PackageShippingResult
        {
            return PackageShippingResult::failed('Unused', 'Unused');
        }

        public function checkEarlierPurchases(Package $package, string $labelFormat = 'pdf', ?int $labelDpi = null, ?int $userId = null): ?PackageShippingResult
        {
            return null;
        }
    };
}

function customsDialogPackage(): Package
{
    return Package::create([
        'shipment_id' => Shipment::factory()->create(['country' => 'CA'])->id,
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 0.3, 'height' => 10, 'width' => 8, 'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);
}

it('names both weights and leads with reweighing at Pack', function (): void {
    $package = customsDialogPackage();
    app()->instance(PackageShippingWorkflow::class, customsDialogWorkflow());

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->call('ship')
        ->assertDispatched('open-modal', id: 'customs-weight-override')
        ->assertSee('The customs items add up to 1.25 lb, but this box weighed 0.30 lb.')
        ->assertSee('Back to Pack to Reweigh')
        ->assertSee(Pack::getUrl(['shipment_id' => $package->shipment_id]), escape: false)
        ->assertSee('Override &amp; Ship', escape: false);
});

it('still ships with the override when the operator chooses it', function (): void {
    $package = customsDialogPackage();
    /** @var ArrayObject<int, PackageShippingRequest> $requests */
    $requests = new ArrayObject;
    app()->instance(PackageShippingWorkflow::class, customsDialogWorkflow($requests));

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->call('ship')
        ->call('confirmCustomsWeightOverride');

    expect(array_map(fn (PackageShippingRequest $request): bool => $request->overrideCustomsWeights, $requests->getArrayCopy()))
        ->toBe([false, true]);
});
