<?php

namespace App\DataTransferObjects\Customs;

use App\DataTransferObjects\Shipping\RateRequest;
use App\Enums\CustomsTermsOrigin;
use App\Enums\DutiesTerms;
use App\Services\Customs\CustomsTermsResolver;

/**
 * The customs terms one Shipment ships on to one destination, resolved by
 * {@see CustomsTermsResolver} (ADR-0008 decisions 1–4; PRD *Resolution*).
 *
 * Carried on the {@see RateRequest}, so its term and registration are part of
 * the Offer fingerprint, and read by the duties-support rate filter. Nothing
 * here is sent to a carrier yet: that is `international-customs-terms/06`–`08`.
 *
 * @param  bool  $applies  Whether the parcel crosses a customs border at all. A domestic or same-customs-zone label resolves to nothing, and every other field is empty.
 * @param  DutiesTerms|null  $dutiesTerms  Who pays duties; null when an EU destination has no term from the order or the client (*unresolved*), or when the source decides
 * @param  SellerTaxRegistration|null  $registration  The registration to declare: the applicable one, unless the consignment is over its regime's threshold
 * @param  SellerTaxRegistration|null  $applicableRegistration  The registration whose regime covers the destination, before the threshold is applied, so a withheld one can still be named
 * @param  bool  $overThreshold  The consignment's goods value is over an IOSS or UK VAT threshold, or no exchange rate was stored to tell; the registration is then not declared
 * @param  bool  $exchangeRateMissing  No ECB rate was stored on or before the order date, so the value could not be converted and the registration is withheld
 * @param  float  $consignmentValue  The goods value of the Package's customs lines, in USD
 * @param  ConvertedAmount|null  $convertedValue  The value compared with the threshold, in its currency: the consignment's for IOSS and UK VAT, null for the per-item regimes
 * @param  int|null  $threshold  The applicable regime's threshold for this destination
 * @param  string|null  $thresholdCurrency  Its currency
 * @param  bool  $recipientIsBusiness  The destination address names a company, the only business signal the app has; rules that bind only consumer parcels, such as USPS's IOSS prepaid-duties rule, do not apply
 * @param  list<CustomsLineOverLimit>  $linesOverLimit  For VOEC and ARN, which test each item, the lines with an item over the limit
 */
readonly class ResolvedCustomsTerms
{
    public function __construct(
        public bool $applies,
        public string $destinationCountry,
        public ?int $clientId = null,
        public ?DutiesTerms $dutiesTerms = null,
        public ?CustomsTermsOrigin $dutiesTermsOrigin = null,
        public ?SellerTaxRegistration $registration = null,
        public ?SellerTaxRegistration $applicableRegistration = null,
        public bool $overThreshold = false,
        public bool $exchangeRateMissing = false,
        public float $consignmentValue = 0.0,
        public ?ConvertedAmount $convertedValue = null,
        public ?int $threshold = null,
        public ?string $thresholdCurrency = null,
        public array $linesOverLimit = [],
        public bool $recipientIsBusiness = false,
    ) {}

    /**
     * Terms for a label that crosses no customs border.
     */
    public static function notApplicable(string $destinationCountry, ?int $clientId = null): self
    {
        return new self(applies: false, destinationCountry: strtoupper($destinationCountry), clientId: $clientId);
    }

    /**
     * An EU destination with no duties term from the order or the client.
     * The label is refused until someone chooses (ADR-0008 decision 1).
     */
    public function isUnresolved(): bool
    {
        return $this->applies
            && $this->dutiesTerms === null
            && $this->dutiesTermsOrigin !== CustomsTermsOrigin::SourceDecided;
    }

    /**
     * Whether a registration covers the destination but is not declared,
     * because the consignment is over its threshold or no rate could tell.
     */
    public function registrationWithheld(): bool
    {
        return $this->applicableRegistration !== null && $this->registration === null;
    }

    /**
     * The same Shipment bought through a source that takes no terms — Amazon
     * Buy Shipping or Shopify Shipping (ADR-0008 decision 5). Nothing PolyBag
     * resolved is declared; the source decides.
     */
    public function asSourceDecided(): self
    {
        if (! $this->applies) {
            return $this;
        }

        return new self(
            applies: true,
            destinationCountry: $this->destinationCountry,
            clientId: $this->clientId,
            dutiesTerms: null,
            dutiesTermsOrigin: CustomsTermsOrigin::SourceDecided,
            consignmentValue: $this->consignmentValue,
        );
    }

    /**
     * What the Offer fingerprint binds: the term and the registration
     * declared. A change to either — a manager editing `duties_terms`, a
     * client adding an IOSS number, a consignment crossing its threshold, a company name added to the recipient —
     * changes what would be bought, so an Offer quoted before must not stay
     * spendable after. Values and rate dates are left out: they matter only
     * through the registration they decide.
     *
     * @return array{applies: bool, duties_terms: string|null, registration: string|null, recipient_is_business: bool}
     */
    public function fingerprintInputs(): array
    {
        return [
            'applies' => $this->applies,
            'duties_terms' => $this->dutiesTerms?->value,
            'registration' => $this->registration === null
                ? null
                : $this->registration->regime->value.':'.$this->registration->number,
            'recipient_is_business' => $this->recipientIsBusiness,
        ];
    }
}
