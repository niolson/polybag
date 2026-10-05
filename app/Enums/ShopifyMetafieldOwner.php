<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The Shopify resource a metafield hangs off, as far as an import reads one.
 */
enum ShopifyMetafieldOwner: string implements HasLabel
{
    case Variant = 'variant';
    case Product = 'product';

    /**
     * The Admin GraphQL `MetafieldOwnerType` value.
     */
    public function ownerType(): string
    {
        return match ($this) {
            self::Variant => 'PRODUCTVARIANT',
            self::Product => 'PRODUCT',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Variant => 'Variant',
            self::Product => 'Product',
        };
    }
}
