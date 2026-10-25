<?php

namespace App\Services\Carriers;

use Illuminate\Support\Sleep;

/**
 * How long {@see FakeCarrierAdapter} takes to answer, so a load test holds a
 * worker as long as a real carrier would (`capacity-audit/01`). Both delays
 * default to zero, which keeps demos and tests instant.
 *
 * Real rate shopping quotes every carrier at once, so a rate shop waits as
 * long as its slowest carrier, not the sum of them. The fakes are asked one
 * after another, so the rate delay is taken once per rate shop: bound as
 * scoped, it is reset for each request and each queued job.
 */
class FakeCarrierLatency
{
    private bool $ratesWaited = false;

    public function __construct(
        private readonly int $rateMilliseconds,
        private readonly int $labelMilliseconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rateMilliseconds: max(0, (int) config('app.fake_carrier_latency.rates_ms')),
            labelMilliseconds: max(0, (int) config('app.fake_carrier_latency.label_ms')),
        );
    }

    public function waitForRates(): void
    {
        if ($this->ratesWaited) {
            return;
        }

        $this->ratesWaited = true;

        if ($this->rateMilliseconds > 0) {
            Sleep::for($this->rateMilliseconds)->milliseconds();
        }
    }

    public function waitForLabel(): void
    {
        if ($this->labelMilliseconds > 0) {
            Sleep::for($this->labelMilliseconds)->milliseconds();
        }
    }
}
