<?php

namespace App\Http\Integrations\Ecb\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The ECB's euro reference rates as its gesmes XML: the latest day by default,
 * or the last 90 days for a backfill.
 *
 * Both files share one shape, a `Cube` per day with a `time` attribute and a
 * `Cube` per currency inside it with `currency` and `rate`.
 */
class GetEuroReferenceRates extends Request
{
    protected Method $method = Method::GET;

    /**
     * @param  bool  $lastNinetyDays  Fetch `eurofxref-hist-90d.xml` instead of the daily file
     */
    public function __construct(private readonly bool $lastNinetyDays = false) {}

    public function resolveEndpoint(): string
    {
        return $this->lastNinetyDays
            ? '/stats/eurofxref/eurofxref-hist-90d.xml'
            : '/stats/eurofxref/eurofxref-daily.xml';
    }
}
