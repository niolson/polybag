<?php

use App\DataTransferObjects\Shipping\RateResponse;
use App\Models\Package;
use App\Models\RateQuote;
use App\Models\ShippingOffer;
use App\Services\RateQuoteLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs rate quotes for a package', function (): void {
    $package = Package::factory()->create();

    $rates = collect([
        new RateResponse('USPS', 'PRIORITY', 'Priority Mail', 8.50, deliveryDate: '2026-03-01'),
        new RateResponse('FedEx', 'GROUND', 'FedEx Ground', 12.00, transitTime: '5-7 days'),
    ]);

    $ids = app(RateQuoteLogger::class)->logRates($package->id, $rates);

    expect(RateQuote::where('package_id', $package->id)->count())->toBe(2);

    $uspsQuote = RateQuote::where('package_id', $package->id)->where('carrier', 'USPS')->first();
    $fedexQuote = RateQuote::where('package_id', $package->id)->where('carrier', 'FedEx')->first();

    // The ids come back in the order the rates went in, so an offer can be
    // pointed at the row logged for the same rate.
    expect($ids)->toBe([$uspsQuote->id, $fedexQuote->id])
        ->and($uspsQuote->service_code)->toBe('PRIORITY')
        ->and($uspsQuote->service_name)->toBe('Priority Mail')
        ->and((float) $uspsQuote->quoted_price)->toBe(8.50)
        ->and($uspsQuote->quoted_delivery_date)->toBe('2026-03-01')
        ->and($uspsQuote->selected)->toBeFalse();
});

it('marks the quote the bought offer points at', function (): void {
    $package = Package::factory()->create();

    $rates = collect([
        new RateResponse('USPS', 'PRIORITY', 'Priority Mail', 8.50),
        new RateResponse('FedEx', 'GROUND', 'FedEx Ground', 12.00),
    ]);

    [$uspsId] = app(RateQuoteLogger::class)->logRates($package->id, $rates);

    $offer = ShippingOffer::factory()->direct()->for($package)->create(['rate_quote_id' => $uspsId]);
    app(RateQuoteLogger::class)->markSelected($offer);

    $uspsQuote = RateQuote::where('package_id', $package->id)->where('carrier', 'USPS')->first();
    $fedexQuote = RateQuote::where('package_id', $package->id)->where('carrier', 'FedEx')->first();

    expect($uspsQuote->selected)->toBeTrue()
        ->and($fedexQuote->selected)->toBeFalse();
});

it('marks exactly the quoted variant when two share a carrier and service code', function (): void {
    // Two USPS variants of one mail class — single-piece and a cubic tier —
    // log two rows that a carrier + service-code match could not tell apart.
    // The offer points at its row, so only the variant bought is marked.
    $package = Package::factory()->create();

    $rates = collect([
        new RateResponse('USPS', 'PRIORITY_MAIL', 'Priority Mail', 9.65, metadata: ['rateIndicator' => 'SP']),
        new RateResponse('USPS', 'PRIORITY_MAIL', 'Priority Mail Cubic', 8.10, metadata: ['rateIndicator' => 'CP']),
    ]);

    [$singlePieceId, $cubicId] = app(RateQuoteLogger::class)->logRates($package->id, $rates);

    $offer = ShippingOffer::factory()->direct()->for($package)->create(['rate_quote_id' => $cubicId]);
    app(RateQuoteLogger::class)->markSelected($offer);

    expect(RateQuote::find($cubicId)->selected)->toBeTrue()
        ->and(RateQuote::find($singlePieceId)->selected)->toBeFalse();
});

it('marks nothing for an offer that never rate-shopped', function (): void {
    $package = Package::factory()->create();

    app(RateQuoteLogger::class)->logRates($package->id, collect([
        new RateResponse('USPS', 'PRIORITY', 'Priority Mail', 8.50),
    ]));

    $offer = ShippingOffer::factory()->direct()->for($package)->create(['rate_quote_id' => null]);
    app(RateQuoteLogger::class)->markSelected($offer);

    expect(RateQuote::where('package_id', $package->id)->where('selected', true)->count())->toBe(0);
});

it('handles empty rate collection', function (): void {
    $package = Package::factory()->create();

    app(RateQuoteLogger::class)->logRates($package->id, collect());

    expect(RateQuote::where('package_id', $package->id)->count())->toBe(0);
});

it('handles duplicate markSelected calls', function (): void {
    $package = Package::factory()->create();

    $rates = collect([
        new RateResponse('USPS', 'PRIORITY', 'Priority Mail', 8.50),
    ]);

    [$quoteId] = app(RateQuoteLogger::class)->logRates($package->id, $rates);

    $offer = ShippingOffer::factory()->direct()->for($package)->create(['rate_quote_id' => $quoteId]);
    app(RateQuoteLogger::class)->markSelected($offer);
    app(RateQuoteLogger::class)->markSelected($offer);

    expect(RateQuote::where('package_id', $package->id)->where('selected', true)->count())->toBe(1);
});
