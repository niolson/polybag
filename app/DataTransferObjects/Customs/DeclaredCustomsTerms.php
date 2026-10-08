<?php

namespace App\DataTransferObjects\Customs;

use App\Contracts\SendsCustomsTerms;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;

/**
 * What an adapter actually put on the wire for a request, as reported by
 * {@see SendsCustomsTerms::declaredCustomsTerms()}: the label's snapshot
 * records this, not what the Shipment resolved, because an adapter may leave a
 * field out (an ITN with no EIN, a term with no invoice to carry it).
 */
readonly class DeclaredCustomsTerms
{
    public function __construct(
        public ?DutiesTerms $dutiesTerms = null,
        public ?SellerTaxRegistration $registration = null,
        public ?RecipientTaxIdType $recipientTaxIdType = null,
        public ?string $exportItn = null,
    ) {}

    /**
     * Nothing declared, as for a seller that decides the terms itself.
     */
    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->dutiesTerms === null
            && $this->registration === null
            && $this->recipientTaxIdType === null
            && $this->exportItn === null;
    }
}
