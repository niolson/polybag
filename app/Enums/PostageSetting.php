<?php

namespace App\Enums;

use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Filament\Support\Contracts\HasLabel;

/**
 * A connection's consent to sell channel postage — ADR-0006 decision 6,
 * `carrier-catalog-reset/10`.
 *
 * Covers Shopify's blind purchase and Amazon Buy Shipping for the connection's
 * own Amazon orders. Not Amazon Shipping sold to orders from other channels,
 * which `offers_off_amazon_shipping` and the scope decide.
 *
 * It only narrows. A value that sells still needs the shipping method to allow
 * the source, and, until `13`, an approval before automation buys an Amazon
 * service.
 */
enum PostageSetting: string implements HasLabel
{
    case DoesNotSell = 'none';
    case PackerOnly = 'packer';
    case PackerAndAutomation = 'automation';

    /**
     * What a new connection of this driver starts with, or null for a driver
     * that sells no postage.
     *
     * Shopify starts off, as the client opt-in it replaces did. Amazon starts
     * at *packer only*, which is what an Amazon connection with nothing
     * approved has always done.
     */
    public static function defaultFor(?string $sourceType): ?self
    {
        return match ($sourceType) {
            ShopifySource::class => self::DoesNotSell,
            AmazonSource::class => self::PackerOnly,
            default => null,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DoesNotSell => 'Does not sell postage',
            self::PackerOnly => 'Packer only',
            self::PackerAndAutomation => 'Packer and automation',
        };
    }

    /**
     * Whether the connection is asked for postage at all.
     */
    public function sells(): bool
    {
        return $this !== self::DoesNotSell;
    }

    /**
     * Whether auto-ship, batch ship and shipping rules may buy what it sells.
     */
    public function allowsAutomation(): bool
    {
        return $this === self::PackerAndAutomation;
    }
}
