<?php

namespace App\Enums;

use App\Services\SettingsService;

/**
 * Which of a postage source's two worlds a quote or an observation came from.
 *
 * Not cosmetic. Amazon's sandbox and production catalogs are not merely stale
 * relative to each other — they disagree: a sandbox `getRates` returned only
 * Amazon Shipping where production for the same channel returned OnTrac, UPS
 * and USPS and no Amazon Shipping at all. So an observation and an Offer are
 * recorded against the environment they came from. What automation may buy is
 * not: the shipping method's allowance applies in both alike (ADR-0006,
 * `carrier-catalog-reset/13`).
 *
 * Derived from the shared `sandbox_mode` setting rather than stored per source,
 * because that setting is what actually decides which host a connector talks
 * to. Recorded on the row at write time so a later flip cannot retroactively
 * relabel what was already observed.
 */
enum SourceEnvironment: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';

    public static function current(): self
    {
        return app(SettingsService::class)->get('sandbox_mode', false)
            ? self::Sandbox
            : self::Production;
    }

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production',
            self::Sandbox => 'Sandbox',
        };
    }
}
