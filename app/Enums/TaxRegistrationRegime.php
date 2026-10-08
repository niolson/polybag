<?php

namespace App\Enums;

use App\DataTransferObjects\Shipping\AddressData;
use Filament\Support\Contracts\HasLabel;

/**
 * A seller tax registration scheme that proves import VAT or GST was collected
 * at checkout (ADR-0008 decision 3, PRD *Tax registration regimes*).
 *
 * The regime, not the operator, fixes which destinations a registration covers,
 * the format of its number and the order value above which it does not apply.
 */
enum TaxRegistrationRegime: string implements HasLabel
{
    /** EU Import One-Stop Shop. */
    case Ioss = 'ioss';

    /** UK VAT on low-value imports, Northern Ireland included. */
    case UkVat = 'uk_vat';

    /** Norway's VAT on E-Commerce. */
    case Voec = 'voec';

    /** Australian GST on low-value imports, under an ATO Reference Number. */
    case Arn = 'arn';

    /**
     * The regime an import or form value names, ignoring case and surrounding
     * space, or null when it names none.
     */
    public static function fromInput(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Ioss => 'IOSS (EU)',
            self::UkVat => 'UK VAT (GB)',
            self::Voec => 'VOEC (Norway)',
            self::Arn => 'ARN (Australia)',
        };
    }

    /**
     * Whether a registration under this regime covers a parcel to the
     * destination. Northern Ireland addresses are `GB`, so UK VAT covers them;
     * IOSS covers them too, at £135 ({@see self::lowValueThresholdFor()}),
     * because Northern Ireland stays in the EU customs territory for goods
     * (HMRC; PRD *Tax registration regimes*).
     */
    public function covers(AddressData $destination): bool
    {
        $country = strtoupper(trim($destination->country));

        return match ($this) {
            self::Ioss => $destination->isInEuropeanUnion() || $destination->isNorthernIreland(),
            self::UkVat => $country === 'GB',
            self::Voec => $country === 'NO',
            self::Arn => $country === 'AU',
        };
    }

    /**
     * The anchored pattern a normalized registration number must match.
     */
    public function numberPattern(): string
    {
        return match ($this) {
            self::Ioss => '/^IM\d{10}$/',
            self::UkVat => '/^GB(\d{9}|\d{12})$/',
            self::Voec => '/^\d{7}$/',
            self::Arn => '/^\d{12}$/',
        };
    }

    /**
     * The format a number must have, as an operator would read it.
     */
    public function numberFormat(): string
    {
        return match ($this) {
            self::Ioss => 'IM followed by 10 digits',
            self::UkVat => 'GB followed by 9 or 12 digits',
            self::Voec => '7 digits',
            self::Arn => '12 digits',
        };
    }

    /**
     * The number as stored and declared: upper case, with spaces removed.
     */
    public function normalizeNumber(string $number): string
    {
        return strtoupper(preg_replace('/\s+/', '', $number) ?? $number);
    }

    public function isValidNumber(?string $number): bool
    {
        return $number !== null && preg_match($this->numberPattern(), $this->normalizeNumber($number)) === 1;
    }

    /**
     * Why a number is not valid under this regime, or null when it is.
     */
    public function numberError(?string $number): ?string
    {
        return $this->isValidNumber($number)
            ? null
            : "A {$this->getLabel()} number must be {$this->numberFormat()}.";
    }

    /**
     * The order value above which the registration does not apply and is not
     * declared, in {@see self::thresholdCurrency()}.
     */
    public function lowValueThreshold(): int
    {
        return match ($this) {
            self::Ioss => 150,
            self::UkVat => 135,
            self::Voec => 3000,
            self::Arn => 1000,
        };
    }

    /**
     * The ISO 4217 currency of {@see self::lowValueThreshold()}.
     */
    public function thresholdCurrency(): string
    {
        return match ($this) {
            self::Ioss => 'EUR',
            self::UkVat => 'GBP',
            self::Voec => 'NOK',
            self::Arn => 'AUD',
        };
    }

    /**
     * The low-value threshold for a parcel to this destination. The same as
     * {@see self::lowValueThreshold()} everywhere but one place: IOSS into
     * Northern Ireland applies to consignments of £135 or less (HMRC).
     */
    public function lowValueThresholdFor(AddressData $destination): int
    {
        return $this === self::Ioss && $destination->isNorthernIreland()
            ? self::UkVat->lowValueThreshold()
            : $this->lowValueThreshold();
    }

    /**
     * The currency of {@see self::lowValueThresholdFor()}.
     */
    public function thresholdCurrencyFor(AddressData $destination): string
    {
        return $this === self::Ioss && $destination->isNorthernIreland()
            ? self::UkVat->thresholdCurrency()
            : $this->thresholdCurrency();
    }

    /**
     * Whether the threshold is measured on each item rather than on the whole
     * consignment. VOEC and ARN test each item; IOSS and UK VAT test the
     * consignment's goods value (PRD *Tax registration regimes*).
     */
    public function measuresEachItem(): bool
    {
        return match ($this) {
            self::Ioss, self::UkVat => false,
            self::Voec, self::Arn => true,
        };
    }

    /**
     * Whether a value, in the threshold's currency, is over the threshold.
     *
     * VOEC applies to items *under* NOK 3,000, so one at exactly 3,000 is
     * over. The others apply up to and including their figure: €150, £135
     * and AUD 1,000 or less.
     */
    public function exceedsThreshold(float $value, int $threshold): bool
    {
        $value = round($value, 2);

        return $this === self::Voec ? $value >= $threshold : $value > $threshold;
    }
}
