<?php

use App\Models\Package;
use App\Models\RateQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cascade deletes rate quotes when package is deleted', function (): void {
    $package = Package::factory()->create();

    RateQuote::factory()->count(3)->create(['package_id' => $package->id]);

    expect(RateQuote::where('package_id', $package->id)->count())->toBe(3);

    $package->delete();

    expect(RateQuote::where('package_id', $package->id)->count())->toBe(0);
});

it('creates rate quotes with factory', function (): void {
    $quote = RateQuote::factory()->create();

    expect($quote)->toBeInstanceOf(RateQuote::class)
        ->and($quote->package)->toBeInstanceOf(Package::class)
        ->and($quote->selected)->toBeFalse();
});

it('creates selected rate quote with factory state', function (): void {
    $quote = RateQuote::factory()->selected()->create();

    expect($quote->selected)->toBeTrue();
});

it('relates rate quotes to package', function (): void {
    $package = Package::factory()->create();

    RateQuote::factory()->count(2)->create(['package_id' => $package->id]);

    expect($package->rateQuotes)->toHaveCount(2);
});
