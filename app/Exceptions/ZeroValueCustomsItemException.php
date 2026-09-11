<?php

namespace App\Exceptions;

use App\DataTransferObjects\Shipping\CustomsItem;

/**
 * A customs declaration was about to carry an item worth nothing, so the
 * purchase was withheld before it was made.
 *
 * `shipment_items.value` is what goes on the commercial invoice, and an
 * import writes an explicit zero on purpose for a free gift, a sample or a
 * warranty replacement. All-free, UPS refuses the label after the box is taped
 * shut with a code naming a field nobody has heard of (`120502`); one free
 * line among paid ones goes through and prints an understated declaration
 * that nothing flags. Both are the same defect and get the same answer: the
 * value is fixed where it lives, by whoever can see the goods. PolyBag does
 * not write a nominal value onto a customs form on anybody's behalf.
 *
 * A precondition put to the operator the way
 * {@see MissingDeclaredValueException} is — nothing was bought and nothing
 * failed — so it must not be caught as a carrier error.
 */
class ZeroValueCustomsItemException extends \Exception
{
    /**
     * @param  list<CustomsItem>  $items  The offending lines, never empty
     */
    public function __construct(public readonly array $items)
    {
        $names = array_map(fn (CustomsItem $item): string => $item->description, $items);

        parent::__construct(sprintf(
            'This shipment needs a customs declaration, but %s no value: %s. '
            .'A customs form cannot declare an item at $0.00. Set the value on the shipment item, then refresh rates.',
            count($names) === 1 ? 'one item has' : count($names).' items have',
            implode(', ', $names),
        ));
    }
}
