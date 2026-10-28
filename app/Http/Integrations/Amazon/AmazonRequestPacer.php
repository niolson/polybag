<?php

namespace App\Http\Integrations\Amazon;

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
 * sent at once, and each later one waits one interval at the rate the last
 * answer's `x-amzn-RateLimit-Limit` gave (the request's default until one
 * does). A 429 waits for its `Retry-After`, else doubles the interval, up to
 * {@see MAX_ATTEMPTS} attempts; the last answer is returned whatever it is.
 * One pacer is one run: a new run starts with no request sent.
 */
class AmazonRequestPacer
{
    public const MAX_ATTEMPTS = 5;

    /** @var array<class-string<Request>, float> requests per second, by request class */
    private array $rates = [];

    /** @var array<class-string<Request>, true> */
    private array $sent = [];

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
            if (isset($this->sent[$operation])) {
                Sleep::for($retryDelayMilliseconds ?? $this->intervalMilliseconds($operation))->milliseconds();
            }

            $this->sent[$operation] = true;
            $response = $this->connector->send($request);
            $this->updateRate($operation, $response);

            if ($response->status() !== 429 || $attempt === self::MAX_ATTEMPTS) {
                return $response;
            }

            $retryDelayMilliseconds = $this->retryDelayMilliseconds($operation, $response, $attempt);
        }

        throw new RuntimeException('Amazon request retry loop ended unexpectedly.');
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
