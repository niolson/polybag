<?php

namespace App\Enums;

/**
 * Whether an Amazon connection's account can sell Amazon Shipping for orders
 * that did not come from Amazon (`channelType: EXTERNAL`).
 *
 * No Shipping v2 or Sellers API operation reports this, so it is inferred from
 * an `EXTERNAL` `getRates`: a `200` means the account can, and a `403 A-101`
 * means it has not finished Amazon Shipping sign-up
 * (`amazon-shipping-external-orders/01`). Anything else answers neither way.
 */
enum OffAmazonShippingStatus: string
{
    case Enabled = 'enabled';
    case NotSetUp = 'not_set_up';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Enabled => 'Enabled',
            self::NotSetUp => 'Not set up',
            self::Unknown => 'Unknown',
        };
    }
}
