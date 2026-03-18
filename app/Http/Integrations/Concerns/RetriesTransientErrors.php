<?php

namespace App\Http\Integrations\Concerns;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;

trait RetriesTransientErrors
{
    /**
     * Only retry on transient errors (429, 5xx, connection failures).
     * Client errors (400, 401, 403, 404, 422) fail immediately.
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        // Connection failures are always retryable
        if ($exception instanceof FatalRequestException) {
            return true;
        }

        $status = $exception->getResponse()->status();

        // 429 Too Many Requests — always retry (with backoff)
        if ($status === 429) {
            logger()->warning('Carrier API rate limited (429), retrying', [
                'connector' => static::class,
                'request' => $request::class,
            ]);

            return true;
        }

        // 5xx Server Errors — retry (server may recover)
        if ($status >= 500) {
            return true;
        }

        // 4xx Client Errors — don't retry (won't succeed)
        return false;
    }
}
