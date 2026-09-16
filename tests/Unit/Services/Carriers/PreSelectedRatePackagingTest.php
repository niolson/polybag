<?php

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\CarrierPackaging;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Models\BoxSize;
use App\Models\Package;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\Carriers\FedexAdapter;
use App\Services\Carriers\UpsAdapter;
use App\Services\Carriers\UspsAdapter;
use Illuminate\Support\Collection;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * ADR-0005 decision 4, the pre-selection site: a rule's chosen service reaches
 * automation through `resolvePreSelectedRate()` without passing through rate
 * shopping, so every adapter runs the shared packaging filter there and null
 * is the answer when nothing survives.
 *
 * `box_sizes.carrier_packaging` is not a column until
 * packaging-form-and-carrier-identity/03, so a Package in carrier packaging is
 * built here by setting the attribute on the model by hand — the same read
 * `PackageData::fromPackage()` will make once the column exists.
 */
function packageIn(?CarrierPackaging $packaging): Package
{
    $boxSize = BoxSize::factory()->create();

    if ($packaging instanceof CarrierPackaging) {
        $boxSize->carrier_packaging = $packaging;
    }

    return Package::factory()->create(['box_size_id' => $boxSize->id])->setRelation('boxSize', $boxSize);
}

/**
 * The rate a rule hands over: a service, never a packaging.
 */
function ruleRate(string $carrier): RateResponse
{
    return new RateResponse(
        carrier: $carrier,
        serviceCode: 'GROUND',
        serviceName: 'Ground',
        price: 0.0,
        packagingRequirement: PackagingRequirement::shipperPackaging(),
    );
}

function uspsVariant(string $indicator, float $price, PackagingRequirement $requirement): RateResponse
{
    return new RateResponse(
        carrier: 'USPS',
        serviceCode: 'PRIORITY_MAIL',
        serviceName: 'Priority Mail',
        price: $price,
        metadata: ['mailClass' => 'PRIORITY_MAIL', 'rateIndicator' => $indicator],
        packagingRequirement: $requirement,
    );
}

/**
 * Only the variant choice is under test; fetching and parsing the variants is
 * `getRates()`'s job and has its own coverage.
 *
 * @param  list<RateResponse>  $variants
 */
function uspsAdapterQuoting(array $variants): UspsAdapter
{
    return new class($variants) extends UspsAdapter
    {
        /**
         * @param  list<RateResponse>  $variants
         */
        public function __construct(private readonly array $variants) {}

        public function getRates(RateRequest $request, array $serviceCodes): Collection
        {
            return collect($this->variants);
        }
    };
}

it('picks the cheapest USPS variant the packaging allows, not the cheapest outright', function (): void {
    $adapter = uspsAdapterQuoting([
        uspsVariant('SP', 8.50, PackagingRequirement::shipperPackaging()),
        uspsVariant('FE', 5.00, PackagingRequirement::exactly(CarrierPackaging::UspsFlatRateEnvelope)),
    ]);

    $resolved = $adapter->resolvePreSelectedRate(ruleRate('USPS'), packageIn(null));

    expect($resolved)->not->toBeNull()
        ->and($resolved->metadata['rateIndicator'])->toBe('SP')
        ->and($resolved->price)->toBe(8.50);
});

it('returns null when no USPS variant fits a Package in carrier packaging', function (): void {
    $adapter = uspsAdapterQuoting([
        uspsVariant('SP', 8.50, PackagingRequirement::shipperPackaging()),
        uspsVariant('CP', 9.10, PackagingRequirement::shipperPackaging()),
    ]);

    expect($adapter->resolvePreSelectedRate(ruleRate('USPS'), packageIn(CarrierPackaging::UspsFlatRateEnvelope)))
        ->toBeNull();
});

it('filters the rule rate itself when USPS quotes no variant at all', function (): void {
    $rate = ruleRate('USPS');

    expect(uspsAdapterQuoting([])->resolvePreSelectedRate($rate, packageIn(null)))->toBe($rate)
        ->and(uspsAdapterQuoting([])->resolvePreSelectedRate($rate, packageIn(CarrierPackaging::UspsSmallFlatRateBox)))->toBeNull();
});

it('pre-selects the flat-rate variant for a Package in a flat-rate box, through the real classifier', function (): void {
    // A rule says "Priority Mail"; USPS quotes the single-piece, cubic and
    // flat-rate variants; the Package is in a medium flat rate box. The
    // cheapest outright is the envelope, the cheapest shipper-packaging is
    // SP — and neither is the answer.
    $rate = fn (string $indicator, string $category, float $price): array => [
        'totalBasePrice' => $price,
        'rates' => [[
            'mailClass' => 'PRIORITY_MAIL',
            'processingCategory' => $category,
            'rateIndicator' => $indicator,
            'destinationEntryFacilityType' => 'NONE',
            'description' => "Priority Mail {$indicator}",
        ]],
    ];

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [[
                'shippingOptions' => [[
                    'rateOptions' => [
                        $rate('SP', 'MACHINABLE', 15.22),
                        $rate('CP', 'MACHINABLE', 15.51),
                        $rate('FE', 'FLATS', 11.12),
                        $rate('FB', 'MACHINABLE', 21.17),
                        $rate('PL', 'MACHINABLE', 31.00),
                    ],
                ]],
            ]],
        ]),
    ]);

    createUspsAccount();

    $package = packageIn(CarrierPackaging::UspsMediumFlatRateBox);
    $package->shipment->update(['postal_code' => '10001', 'validated_postal_code' => '10001']);

    $resolved = (new UspsAdapter)->resolvePreSelectedRate(new RateResponse(
        carrier: 'USPS',
        serviceCode: 'PRIORITY_MAIL',
        serviceName: 'Priority Mail',
        price: 0.0,
        packagingRequirement: PackagingRequirement::shipperPackaging(),
    ), $package);

    expect($resolved)->not->toBeNull()
        ->and($resolved->metadata['rateIndicator'])->toBe('FB')
        ->and($resolved->price)->toBe(21.17);
});

dataset('pass-through adapters', [
    'FedEx' => [fn (): CarrierAdapterInterface => new FedexAdapter, 'FedEx'],
    'UPS' => [fn (): CarrierAdapterInterface => new UpsAdapter, 'UPS'],
    'Amazon' => [fn (): CarrierAdapterInterface => new AmazonBuyShippingAdapter, 'Amazon'],
    'Fake' => [fn (): CarrierAdapterInterface => new FakeCarrierAdapter('USPS'), 'USPS'],
]);

it('returns a rule rate unchanged for a Package in the shipper\'s own packaging', function (Closure $adapter, string $carrier): void {
    $rate = ruleRate($carrier);

    expect($adapter()->resolvePreSelectedRate($rate, packageIn(null)))->toBe($rate);
})->with('pass-through adapters');

it('returns null for a rule rate against a Package in carrier packaging', function (Closure $adapter, string $carrier): void {
    expect($adapter()->resolvePreSelectedRate(ruleRate($carrier), packageIn(CarrierPackaging::FedexEnvelope)))
        ->toBeNull();
})->with('pass-through adapters');
