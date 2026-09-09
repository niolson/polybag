<?php

namespace App\Exceptions;

use App\Exceptions\Carriers\ShopifyLabelPurchaseException;

/**
 * Shopify would declare more weight on the customs form than the box was
 * weighed at, so an international purchase was withheld before it was made.
 *
 * Shopify builds the customs declaration from its own product catalogue and
 * refuses a label whose `totalWeight` falls below the sum of it. What comes
 * back is `UNKNOWN_ERROR` — no field, no code, nothing an operator can act on —
 * and it arrives after the box is taped shut, having closed the fulfillment
 * order on the way out (issue `18`). Both numbers are readable beforehand,
 * which is why this is said here rather than left to Shopify to say badly.
 *
 * There is no PolyBag-side remedy. `ShipRequest::withScaledCustomsWeights()`
 * scales *our* declaration down to fit the box, which is the direction Shopify
 * forbids, and Shopify never sees that array anyway — the only lever we hold on
 * this path is the total, and raising it would pay postage on weight that is
 * not there and print it on a customs form. The fix is the merchant's
 * catalogue.
 *
 * Deliberately not a {@see ShopifyLabelPurchaseException}:
 * nothing was bought and nothing failed. It is a precondition put to the
 * operator the way {@see MissingDeclaredValueException} is, and both the
 * adapter and the workflow rely on it not being caught as a carrier error.
 */
class ShopifyDeclaredWeightException extends \Exception
{
    public function __construct(
        public readonly float $declaredWeight,
        public readonly float $packageWeight,
    ) {
        parent::__construct(sprintf(
            'Shopify declares %s lb of goods for this order, but this box weighed %s lb. '
            .'Shopify refuses an international label whose total weight is below its own customs '
            .'declaration, so buying this one would fail. The item weights come from the product '
            .'catalogue in the Shopify admin, not from the scale — correct them there, or ship this '
            .'package from a carrier account instead.',
            number_format($declaredWeight, 2),
            number_format($packageWeight, 2),
        ));
    }
}
