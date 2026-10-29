<?php

namespace App\Http\Integrations\Amazon;

use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use RuntimeException;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * Sends SP-API requests at the rate Amazon reports for each operation, and
 * retries a 429 (`product-catalog-sync/04`, generalized from the catalog
 * lookup's own pacing).
 *
 * Each request class is paced on its own: the first request of a class is
 * sent at once, and each later one is sent no sooner than one interval, at
 * the rate the last answer's `x-amzn-RateLimit-Limit` gave (the request's
 * default until one does), after the previous one *started*. A request that
 * took longer than the interval is followed at once, so latency is not paid
 * on top of the interval (`product-catalog-sync/04`: waiting a full interval
 * after each answer made a 29,728-SKU catalog look-up about 65% slower than
 * its rate allows). A 429 waits for its `Retry-After`, else doubles the
 * interval, up to {@see MAX_ATTEMPTS} attempts; the last answer is returned
 * whatever it is. One pacer is one run: a new run starts with no request
 * sent.
 *
 * Time is read from Carbon, so a test can move it.
 */
class AmazonRequestPacer
{
    public const MAX_ATTEMPTS = 5;

    /** @var array<class-string<Request>, float> requests per second, by request class */
    private array $rates = [];

    /** @var array<class-string<Request>, float> when the last request of each class started, in milliseconds */
    private array $startedAt = [];

    public function __construct(private readonly AmazonSpApiConnector $connector) {}

    /**
     * @param  float  $defaultRate  requests per second until Amazon says otherwise
     */
    public function send(Request $request, float $defaultRate): Response
    {
        $operation = $request::class;
        $this->rates[$operation] ??= $defaultRate;
        $retryDelayMilliseconds = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if (isset($this->startedAt[$operation])) {
                $waitMilliseconds = $retryDelayMilliseconds
                    ?? (int) ceil($this->intervalMilliseconds($operation) - (self::nowMilliseconds() - $this->startedAt[$operation]));

                if ($waitMilliseconds > 0) {
                    Sleep::for($waitMilliseconds)->milliseconds();
                }
            }

            $this->startedAt[$operation] = self::nowMilliseconds();
            $response = $this->connector->send($request);
            $this->updateRate($operation, $response);

            if ($response->status() !== 429 || $attempt === self::MAX_ATTEMPTS) {
                return $response;
            }

            $retryDelayMilliseconds = $this->retryDelayMilliseconds($operation, $response, $attempt);
        }

        throw new RuntimeException('Amazon request retry loop ended unexpectedly.');
    }

    private static function nowMilliseconds(): float
    {
        return (float) CarbonImmutable::now()->getPreciseTimestamp(3);
    }

    /**
     * @param  class-string<Request>  $operation
     */
    private function updateRate(string $operation, Response $response): void
    {
        $rateLimit = $response->header('x-amzn-RateLimit-Limit');

        if (is_array($rateLimit)) {
            $rateLimit = $rateLimit[0] ?? null;
        }

        if (is_string($rateLimit) && is_numeric($rateLimit) && (float) $rateLimit > 0) {
            $this->rates[$operation] = (float) $rateLimit;
        }
    }

    /**
     * @param  class-string<Request>  $operation
     */
    private function intervalMilliseconds(string $operation): int
    {
        return max(1, (int) ceil(1000 / $this->rates[$operation]));
    }

    /**
     * @param  class-string<Request>  $operation
     */
    private function retryDelayMilliseconds(string $operation, Response $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_array($retryAfter)) {
            $retryAfter = $retryAfter[0] ?? null;
        }

        if (is_string($retryAfter) && is_numeric($retryAfter)) {
            return max($this->intervalMilliseconds($operation), (int) ceil((float) $retryAfter * 1000));
        }

        if (is_string($retryAfter) && ($retryAt = strtotime($retryAfter)) !== false) {
            return max($this->intervalMilliseconds($operation), ($retryAt - time()) * 1000);
        }

        return $this->intervalMilliseconds($operation) * (2 ** ($attempt - 1));
    }
}
