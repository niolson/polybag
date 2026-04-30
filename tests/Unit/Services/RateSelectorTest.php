<?php

use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Services\RateSelector;
use Carbon\Carbon;

function makeRate(float $price, ?string $deliveryDate = null, string $carrier = 'USPS', string $serviceCode = 'GA'): RateResponse
{
    return new RateResponse(
        carrier: $carrier,
        serviceCode: $serviceCode,
        serviceName: 'Ground Advantage',
        price: $price,
        deliveryDate: $deliveryDate,
    );
}

it('classifies all rates as on-time when there is no deadline', function (): void {
    $rates = collect([makeRate(10.00), makeRate(5.00)]);

    $classified = app(RateSelector::class)->classify($rates, null);

    expect($classified)->toHaveCount(2)
        ->and($classified->every(fn (ClassifiedRate $cr) => $cr->isOnTime))->toBeTrue();
});

it('sorts on-time rates before late rates', function (): void {
    $deadline = Carbon::tomorrow()->endOfDay();
    $rates = collect([
        makeRate(12.00, Carbon::parse('+10 days')->toDateString()),
        makeRate(8.00, Carbon::today()->toDateString()),
    ]);

    $classified = app(RateSelector::class)->classify($rates, $deadline);

    expect($classified[0]->isOnTime)->toBeTrue()
        ->and($classified[1]->isOnTime)->toBeFalse();
});

it('sorts each group cheapest first', function (): void {
    $deadline = Carbon::tomorrow()->endOfDay();
    $rates = collect([
        makeRate(15.00, Carbon::today()->toDateString()),
        makeRate(8.00, Carbon::today()->toDateString()),
        makeRate(12.00, Carbon::parse('+10 days')->toDateString()),
        makeRate(6.00, Carbon::parse('+10 days')->toDateString()),
    ]);

    $classified = app(RateSelector::class)->classify($rates, $deadline);

    expect($classified[0]->rate->price)->toBe(8.00)
        ->and($classified[1]->rate->price)->toBe(15.00)
        ->and($classified[2]->rate->price)->toBe(6.00)
        ->and($classified[3]->rate->price)->toBe(12.00);
});

it('treats unknown delivery date as late when deadline exists', function (): void {
    $deadline = Carbon::tomorrow()->endOfDay();

    $classified = app(RateSelector::class)->classify(collect([makeRate(5.00, null)]), $deadline);

    expect($classified[0]->isOnTime)->toBeFalse();
});

it('treats unknown delivery date as on-time when no deadline', function (): void {
    $classified = app(RateSelector::class)->classify(collect([makeRate(5.00, null)]), null);

    expect($classified[0]->isOnTime)->toBeTrue();
});

it('selectBest returns cheapest on-time rate when deadline exists', function (): void {
    $deadline = Carbon::tomorrow()->endOfDay();
    $rates = collect([
        makeRate(8.00, Carbon::today()->toDateString()),
        makeRate(5.00, Carbon::today()->toDateString()),
        makeRate(3.00, Carbon::parse('+10 days')->toDateString()),
    ]);

    $best = app(RateSelector::class)->selectBest($rates, $deadline);

    expect($best->price)->toBe(5.00);
});

it('selectBest falls back to cheapest overall when all rates are late', function (): void {
    $deadline = Carbon::yesterday()->endOfDay();
    $rates = collect([
        makeRate(10.00, Carbon::today()->toDateString()),
        makeRate(7.00, Carbon::today()->toDateString()),
    ]);

    $best = app(RateSelector::class)->selectBest($rates, $deadline);

    expect($best->price)->toBe(7.00);
});

it('selectBest returns cheapest when no deadline', function (): void {
    $rates = collect([makeRate(10.00), makeRate(5.00), makeRate(8.00)]);

    $best = app(RateSelector::class)->selectBest($rates, null);

    expect($best->price)->toBe(5.00);
});
