<?php

use App\DataTransferObjects\Shipping\RateResponse;
use App\Models\Package;
use App\Models\RateQuote;
use App\Services\RateQuoteLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs rate quotes for a package', function (): void {
    $package = Package::factory()->create();

    $rates = collect([
        new RateResponse('USPS', 'PRIORITY', 'Priority Mail', 8.50, deliveryDate: '2026-03-01'),
        new RateResponse('FedEx', 'GROUND', 'FedEx Ground', 12.00, transitTime: '5-7 days'),
    ]);

    app(RateQuoteLogger::class)->logRates($package->id, $rates);

    expect(RateQuote::where('package_id', $package->id)->count())->toBe(2);

    $uspsQuote = RateQuote::where('package_id', $package->id)->where('carrier', 'USPS')->first();
    expect($uspsQuote->service_code)->toBe('PRIORITY')
        ->and($uspsQuote->service_name)->toBe('Priority Mail')
        ->and((float) $uspsQuote->quoted_price)->toBe(8.50)
        ->and($uspsQuote->quoted_delivery_date)->toBe('2026-03-01')
        ->and($uspsQuote->selected)->toBeFalse();
});

it('marks selected rate quote', function (): void {
    $package = Package::factory()->create();

    $rates = collect([
        new RateResponse('USPS', 'PRIORITY', 'Priority Mail', 8.50),
        new RateResponse('FedEx', 'GROUND', 'FedEx Ground', 12.00),
    ]);

    app(RateQuoteLogger::class)->logRates($package->id, $rates);

    $selectedRate = new RateResponse('USPS', 'PRIORITY', 'Priority Mail', 8.50);
    app(RateQuoteLogger::class)->markSelected($package->id, $selectedRate);

    $uspsQuote = RateQuote::where('package_id', $package->id)->where('carrier', 'USPS')->first();
    $fedexQuote = RateQuote::where('package_id', $package->id)->where('carrier', 'FedEx')->first();

    expect($uspsQuote->selected)->toBeTrue()
        ->and($fedexQuote->selected)->toBeFalse();
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

    app(RateQuoteLogger::class)->logRates($package->id, $rates);

    $selectedRate = new RateResponse('USPS', 'PRIORITY', 'Priority Mail', 8.50);
    app(RateQuoteLogger::class)->markSelected($package->id, $selectedRate);
    app(RateQuoteLogger::class)->markSelected($package->id, $selectedRate);

    expect(RateQuote::where('package_id', $package->id)->where('selected', true)->count())->toBe(1);
});
