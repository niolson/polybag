<?php

namespace App\Http\Integrations\Ecb;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

/**
 * The European Central Bank's public statistics site, which publishes the
 * daily euro foreign exchange reference rates (`international-customs-terms/04`).
 *
 * No credentials: the files are public. It is the only outbound call PolyBag
 * makes to a service that is neither a carrier, a sales channel nor one the
 * operator configured, so an install without outbound HTTPS to
 * ecb.europa.eu stores no rates; see `docs/self-hosting.md`.
 */
class EcbConnector extends Connector
{
    use AlwaysThrowOnErrors;

    public function resolveBaseUrl(): string
    {
        return 'https://www.ecb.europa.eu';
    }

    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/xml, text/xml',
        ];
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => 20,
        ];
    }
}
