<?php

namespace App\DataTransferObjects\Shipping;

/**
 * The protections Amazon attaches to one Buy Shipping offer, read from the
 * `benefits` block the adapter stores in the rate's metadata.
 *
 * Amazon lists each benefit as included or excluded, and gives reason codes for
 * an exclusion. Production quotes name `OTDR_PROTECTED` and `CLAIMS_PROTECTED`,
 * and exclude OTDR protection for `LATE_DELIVERY_RISK`, `NON_SSA_ORDER` and
 * `NON_AHT_ORDER`, the last two being the seller's Seller Central automation
 * settings. So an unprotected offer is not necessarily a late one, and a late
 * one is judged by its delivery date, not by this (`amazon-buy-shipping/16`).
 */
readonly class BuyShippingBenefits
{
    public const OTDR_PROTECTED = 'OTDR_PROTECTED';

    public const RATE_METADATA_KEY = 'benefits';

    /** @var array<string, string> */
    private const REASON_LABELS = [
        'LATE_DELIVERY_RISK' => 'late-delivery risk',
        'NON_SSA_ORDER' => 'Shipping Settings Automation is off',
        'NON_AHT_ORDER' => 'Average Handling Time automation is off',
    ];

    /**
     * @param  list<string>  $included
     * @param  array<string, list<string>>  $excluded  Reason codes, keyed by the benefit they exclude
     */
    public function __construct(
        public array $included,
        public array $excluded,
    ) {}

    /**
     * Null when the metadata carries no `benefits` block: every direct-carrier
     * rate, and any Amazon offer Amazon sent without one.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function fromRateMetadata(array $metadata): ?self
    {
        $benefits = $metadata[self::RATE_METADATA_KEY] ?? null;

        if (! is_array($benefits)) {
            return null;
        }

        $excluded = [];

        foreach ($benefits['excludedBenefits'] ?? [] as $exclusion) {
            if (is_array($exclusion) && filled($exclusion['benefit'] ?? null)) {
                $excluded[(string) $exclusion['benefit']] = array_values(array_map('strval', $exclusion['reasonCodes'] ?? []));
            }
        }

        return new self(
            included: array_values(array_map('strval', $benefits['includedBenefits'] ?? [])),
            excluded: $excluded,
        );
    }

    public function isOtdrProtected(): bool
    {
        return in_array(self::OTDR_PROTECTED, $this->included, true);
    }

    /**
     * Why Amazon withheld OTDR protection, in words, for the Ship page.
     *
     * @return list<string>
     */
    public function otdrExclusionReasons(): array
    {
        return array_map(
            fn (string $code): string => self::REASON_LABELS[$code] ?? $code,
            $this->excluded[self::OTDR_PROTECTED] ?? [],
        );
    }
}
