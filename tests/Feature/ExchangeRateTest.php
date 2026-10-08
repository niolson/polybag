<?php

use App\Http\Integrations\Ecb\Requests\GetEuroReferenceRates;
use App\Models\ExchangeRate;
use App\Services\ExchangeRates\EcbReferenceRateFetcher;
use App\Services\ExchangeRates\ExchangeRateConverter;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `international-customs-terms/04`, decided in its Comments on 2026-10-08:
 * thresholds are tested at the ECB's daily euro reference rates, stored per
 * day. Every request here is faked; nothing reaches ecb.europa.eu. The rates
 * are synthetic.
 *
 * @param  array<string, array<string, string>>  $days  Reference date to currency to rate
 */
function ecbRateFile(array $days): string
{
    $cubes = '';

    foreach ($days as $date => $rates) {
        $cubes .= "<Cube time=\"{$date}\">";

        foreach ($rates as $currency => $rate) {
            $cubes .= "<Cube currency=\"{$currency}\" rate=\"{$rate}\"/>";
        }

        $cubes .= '</Cube>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
        .'<gesmes:subject>Reference rates</gesmes:subject>'
        .'<gesmes:Sender><gesmes:name>European Central Bank</gesmes:name></gesmes:Sender>'
        ."<Cube>{$cubes}</Cube>"
        .'</gesmes:Envelope>';
}

describe('the converter', function (): void {
    it('converts USD into each threshold currency through the euro, on the day asked', function (): void {
        foreach (['USD' => 1.25, 'GBP' => 0.80, 'NOK' => 12.50, 'AUD' => 1.60] as $currency => $rate) {
            ExchangeRate::factory()->quoting($currency, $rate, '2026-10-07')->create();
        }

        $converter = app(ExchangeRateConverter::class);
        $day = CarbonImmutable::parse('2026-10-07');

        expect($converter->convert(100.0, 'USD', 'EUR', $day)?->amount)->toEqualWithDelta(80.0, 0.0001)
            ->and($converter->convert(100.0, 'USD', 'GBP', $day)?->amount)->toEqualWithDelta(64.0, 0.0001)
            ->and($converter->convert(100.0, 'USD', 'NOK', $day)?->amount)->toEqualWithDelta(1000.0, 0.0001)
            ->and($converter->convert(100.0, 'USD', 'AUD', $day)?->amount)->toEqualWithDelta(128.0, 0.0001)
            ->and($converter->convert(100.0, 'USD', 'USD', $day)?->amount)->toBe(100.0);
    });

    it('falls back to the latest earlier day that quotes both currencies, never a later one', function (): void {
        ExchangeRate::factory()->quoting('USD', 1.00, '2026-10-01')->create();
        ExchangeRate::factory()->quoting('GBP', 0.50, '2026-10-01')->create();
        // A later day with only one of the two currencies is passed over.
        ExchangeRate::factory()->quoting('USD', 2.00, '2026-10-02')->create();
        // A day after the order date is never used.
        ExchangeRate::factory()->quoting('USD', 4.00, '2026-10-06')->create();
        ExchangeRate::factory()->quoting('GBP', 4.00, '2026-10-06')->create();

        $converted = app(ExchangeRateConverter::class)->convert(100.0, 'USD', 'GBP', CarbonImmutable::parse('2026-10-04 18:00'));

        expect($converted?->amount)->toEqualWithDelta(50.0, 0.0001)
            ->and($converted?->rateDate->toDateString())->toBe('2026-10-01');
    });

    it('answers nothing when no day on or before the date is stored', function (): void {
        ExchangeRate::factory()->quoting('USD', 1.25, '2026-10-07')->create();

        expect(app(ExchangeRateConverter::class)->convert(100.0, 'USD', 'EUR', CarbonImmutable::parse('2026-10-06')))->toBeNull()
            ->and(app(ExchangeRateConverter::class)->convert(100.0, 'USD', 'NOK', CarbonImmutable::parse('2026-10-08')))->toBeNull();
    });
});

describe('the ECB fetch', function (): void {
    it('stores every currency of the daily file, and running it twice changes nothing', function (): void {
        Saloon::fake([
            GetEuroReferenceRates::class => MockResponse::make(ecbRateFile([
                '2026-10-07' => ['USD' => '1.2500', 'GBP' => '0.80000', 'NOK' => '12.5000', 'AUD' => '1.6000', 'JPY' => '160.00'],
            ]), 200, ['Content-Type' => 'text/xml']),
        ]);

        $fetcher = app(EcbReferenceRateFetcher::class);

        expect($fetcher->fetch())->toBe(['2026-10-07'])
            ->and($fetcher->fetch())->toBe(['2026-10-07'])
            ->and(ExchangeRate::query()->count())->toBe(5)
            ->and((float) ExchangeRate::query()->where('currency', 'NOK')->value('rate'))->toBe(12.5);

        Saloon::assertSent(fn (GetEuroReferenceRates $request): bool => $request->resolveEndpoint() === '/stats/eurofxref/eurofxref-daily.xml');
    });

    it('refuses a response that holds no rates rather than storing nothing', function (): void {
        Saloon::fake([
            GetEuroReferenceRates::class => MockResponse::make('<html>maintenance</html>', 200),
        ]);

        expect(fn () => app(EcbReferenceRateFetcher::class)->fetch())->toThrow(RuntimeException::class);
    });

    it('backfills ninety days on the first run, then fetches the daily file', function (): void {
        $endpoints = [];

        Saloon::fake([
            GetEuroReferenceRates::class => function (PendingRequest $pending) use (&$endpoints): MockResponse {
                $endpoints[] = $pending->getRequest()->resolveEndpoint();

                return MockResponse::make(ecbRateFile(count($endpoints) === 1
                    ? ['2026-10-06' => ['USD' => '1.20'], '2026-10-07' => ['USD' => '1.25']]
                    : ['2026-10-08' => ['USD' => '1.30']]), 200);
            },
        ]);

        $this->artisan('exchange-rates:fetch')
            ->expectsOutputToContain('2 days, 2026-10-06 to 2026-10-07')
            ->assertSuccessful();
        $this->artisan('exchange-rates:fetch')
            ->expectsOutputToContain('2026-10-08')
            ->assertSuccessful();

        expect($endpoints)->toBe(['/stats/eurofxref/eurofxref-hist-90d.xml', '/stats/eurofxref/eurofxref-daily.xml'])
            ->and(ExchangeRate::query()->count())->toBe(3);
    });

    it('logs a warning and fails when the ECB cannot be reached', function (): void {
        $warnings = [];
        Log::listen(function (MessageLogged $event) use (&$warnings): void {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });
        Saloon::fake([
            GetEuroReferenceRates::class => MockResponse::make('', 503),
        ]);

        $this->artisan('exchange-rates:fetch')->assertFailed();

        expect(ExchangeRate::query()->count())->toBe(0)
            ->and($warnings)->toBe(['Could not fetch the ECB euro reference rates']);
    });

    it('is scheduled', function (): void {
        $this->artisan('schedule:list')->expectsOutputToContain('exchange-rates:fetch');
    });
});
