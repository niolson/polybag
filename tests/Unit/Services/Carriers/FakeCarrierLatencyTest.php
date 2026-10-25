<?php

use App\Models\Carrier;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\Carriers\FakeCarrierLatency;
use Carbon\CarbonInterval;
use Database\Seeders\CarrierSeeder;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
});

it('answers instantly by default', function (): void {
    $latency = FakeCarrierLatency::fromConfig();

    $latency->waitForRates();
    $latency->waitForLabel();

    Sleep::assertNeverSlept();
});

it('waits once per rate shop, as concurrent quotes do, and once per label', function (): void {
    $latency = new FakeCarrierLatency(rateMilliseconds: 800, labelMilliseconds: 1200);

    $latency->waitForRates();
    $latency->waitForRates();
    $latency->waitForRates();
    $latency->waitForLabel();
    $latency->waitForLabel();

    Sleep::assertSequence([
        Sleep::for(800)->milliseconds(),
        Sleep::for(1200)->milliseconds(),
        Sleep::for(1200)->milliseconds(),
    ]);
});

it('slows a rate shop across every fake carrier by the configured delay only once', function (): void {
    $this->seed(CarrierSeeder::class);
    config(['app.fake_carrier_latency.rates_ms' => 500]);
    app()->forgetScopedInstances();

    foreach ([Carrier::USPS, Carrier::FEDEX, Carrier::UPS] as $carrier) {
        (new FakeCarrierAdapter($carrier))->getRates(rateRequestForClient(1), []);
    }

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 500);
});
