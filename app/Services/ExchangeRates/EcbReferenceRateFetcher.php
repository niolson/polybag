<?php

namespace App\Services\ExchangeRates;

use App\Http\Integrations\Ecb\EcbConnector;
use App\Http\Integrations\Ecb\Requests\GetEuroReferenceRates;
use App\Models\ExchangeRate;
use RuntimeException;

/**
 * Fetches the ECB's euro reference rates and stores each published day
 * (`international-customs-terms/04`, decided in its Comments on 2026-10-08).
 *
 * Every currency in the file is kept, not only the four the thresholds use:
 * the file is small, and a rate row costs nothing beside the cost of a
 * missing one. A day already stored is updated in place, so running the
 * fetch twice, or a backfill over days already held, changes nothing.
 */
class EcbReferenceRateFetcher
{
    public function __construct(private readonly EcbConnector $connector) {}

    /**
     * Fetch and store the rates, returning the days stored.
     *
     * @param  bool  $lastNinetyDays  Backfill from the 90-day file instead of the daily one
     * @return list<string> The ECB reference dates stored, oldest first
     *
     * @throws RuntimeException when the response is not the ECB's rate file
     */
    public function fetch(bool $lastNinetyDays = false): array
    {
        $response = $this->connector->send(new GetEuroReferenceRates($lastNinetyDays));

        $days = $this->parse($response->body());
        $now = now();
        $rows = [];

        foreach ($days as $date => $rates) {
            foreach ($rates as $currency => $rate) {
                $rows[] = [
                    'rate_date' => $date,
                    'currency' => $currency,
                    'rate' => $rate,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ExchangeRate::query()->upsert($chunk, ['rate_date', 'currency'], ['rate', 'updated_at']);
        }

        $dates = array_keys($days);
        sort($dates);

        return $dates;
    }

    /**
     * The rates in an ECB reference rate file, by day and then currency.
     *
     * Read by element name rather than through the file's namespaces: the
     * `Cube` elements sit in the ECB's own default namespace, and matching
     * on the local name keeps a change of namespace URI from emptying the
     * table silently. A file with no day in it is refused rather than stored
     * as nothing.
     *
     * @return array<string, array<string, string>>
     *
     * @throws RuntimeException
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, options: LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            throw new RuntimeException('The ECB reference rate response is not XML.');
        }

        $days = [];

        foreach ($document->xpath('//*[local-name()="Cube"][@time]') ?: [] as $day) {
            $date = (string) $day['time'];

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            foreach ($day->xpath('./*[local-name()="Cube"][@currency][@rate]') ?: [] as $quote) {
                $currency = strtoupper((string) $quote['currency']);
                $rate = (string) $quote['rate'];

                if (preg_match('/^[A-Z]{3}$/', $currency) === 1 && is_numeric($rate) && (float) $rate > 0) {
                    $days[$date][$currency] = $rate;
                }
            }
        }

        if ($days === []) {
            throw new RuntimeException('The ECB reference rate response holds no rates.');
        }

        return $days;
    }
}
