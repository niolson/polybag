<?php

namespace App\DataTransferObjects\Customs;

use App\Enums\RecipientTaxIdType;
use App\Models\Shipment;

/**
 * The recipient's own tax ID as a label declares it. Personal data: the
 * number is sent to the carrier and goes nowhere else. The `customs_terms`
 * snapshot records only that one was sent, and its type.
 */
readonly class RecipientTaxId
{
    public function __construct(
        public RecipientTaxIdType $type,
        public string $number,
    ) {}

    /**
     * The Shipment's tax ID, or null when it has no number or no type.
     */
    public static function fromShipment(Shipment $shipment): ?self
    {
        if (blank($shipment->recipient_tax_id) || $shipment->recipient_tax_id_type === null) {
            return null;
        }

        return new self($shipment->recipient_tax_id_type, (string) $shipment->recipient_tax_id);
    }

    /**
     * @return array{type: RecipientTaxIdType, number: string}
     */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'number' => '[REDACTED]'];
    }
}
