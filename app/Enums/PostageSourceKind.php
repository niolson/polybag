<?php

namespace App\Enums;

/**
 * The kinds of postage source a Label can be bought through — ADR-0006.
 *
 * A kind, not an instance: two Amazon connections are one kind, because they
 * sell from the same catalog under the same identifiers. The source mapping
 * table, shipping rules and a shipping method's source policy all key on this,
 * so there is one list of kinds. A new channel source is a new case here.
 *
 * Not {@see PostageSource}, which records whether one Label was bought on a
 * carrier account or through a connection.
 */
enum PostageSourceKind: string
{
    /** A carrier's own API, on one of our carrier accounts. */
    case Direct = 'direct';

    /** Shopify Shipping, through the Shipment's originating Shopify connection. */
    case Shopify = 'shopify';

    /** Amazon Buy Shipping, through a connected Amazon account. */
    case Amazon = 'amazon';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Shopify => 'Shopify',
            self::Amazon => 'Amazon Buy Shipping',
        };
    }
}
