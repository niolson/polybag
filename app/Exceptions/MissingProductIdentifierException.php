<?php

namespace App\Exceptions;

use App\DataTransferObjects\Shipping\CustomsItem;

/**
 * An EU consumer label was about to declare a line without the merchant or
 * manufacturer product identifier, so the purchase was withheld before it
 * was made.
 *
 * From 1 November 2026 EU customs holds a B2C parcel whose lines lack either,
 * while the carriers' APIs go on accepting the label, so the operator would
 * hear of it from the customer weeks later. The identifiers belong to the
 * product, not the label, which is why there is no override: the refusal
 * sends the operator to the product form (`eu-product-identifiers/04`).
 *
 * A precondition put to the operator the way
 * {@see ZeroValueCustomsItemException} is — nothing was bought and nothing
 * failed — so it must not be caught as a carrier error.
 */
class MissingProductIdentifierException extends \Exception
{
    /**
     * @param  list<CustomsItem>  $items  The offending lines, never empty
     */
    public function __construct(public readonly array $items)
    {
        parent::__construct(sprintf(
            'EU customs will hold this parcel: %s missing the product identifiers an EU consumer shipment needs: %s. '
            .'Add them on the product form (SKU, and Manufacturer Part Number under Customs), then refresh rates.',
            count($items) === 1 ? 'one item is' : count($items).' items are',
            implode('; ', array_map(self::describe(...), $items)),
        ));
    }

    /**
     * The line named by its SKU when it has one and by its description
     * otherwise — a missing SKU is one of the two things being reported —
     * followed by what it lacks.
     */
    private static function describe(CustomsItem $item): string
    {
        $missing = array_keys(array_filter([
            'SKU' => $item->merchantProductId === null,
            'Manufacturer Part Number' => $item->manufacturerProductId === null,
        ]));

        return sprintf('%s (no %s)', $item->merchantProductId ?? $item->description, implode(' or ', $missing));
    }
}
