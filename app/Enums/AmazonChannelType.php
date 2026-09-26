<?php

namespace App\Enums;

/**
 * Which kind of order Amazon Buy Shipping was asked to sell postage for —
 * Shipping v2's `channelType`.
 *
 * `Amazon` is an Amazon order, bought on its own connection with Buy Shipping's
 * protections. `External` is an order from another channel, sold Amazon
 * Shipping by the connection scoped to it (ADR-0002, 2026-09-22), with its own
 * prices and none of those protections.
 *
 * The two are separated by source, not by a setting: an Amazon order buys
 * through Buy Shipping, and another channel's order buys Amazon Shipping as a
 * direct rate (`carrier-catalog-reset/15`). The service identities are the
 * same on both, and so is what we call them: observations and mappings are
 * not split by channel.
 */
enum AmazonChannelType: string
{
    case Amazon = 'amazon';
    case External = 'external';

    /**
     * The value Shipping v2 sends as `channelDetails.channelType`.
     */
    public function apiValue(): string
    {
        return match ($this) {
            self::Amazon => 'AMAZON',
            self::External => 'EXTERNAL',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Amazon => 'Amazon orders',
            self::External => 'Orders from other channels',
        };
    }
}
