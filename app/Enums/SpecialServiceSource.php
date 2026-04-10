<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SpecialServiceSource: string implements HasLabel
{
    /** Applied because the shipping method has this service as default or required. */
    case ShippingMethod = 'shipping_method';

    /** Applied because a product in the package requires it (e.g. hazmat, alcohol). */
    case Product = 'product';

    /** Selected manually by the operator on the ship page. */
    case Manual = 'manual';

    /** Applied automatically by the system (e.g. Saturday delivery eligibility check). */
    case System = 'system';

    /** Reserved for V2 shipping rule engine integration. */
    case Rule = 'rule';

    public function getLabel(): string
    {
        return match ($this) {
            self::ShippingMethod => 'Shipping Method',
            self::Product => 'Product',
            self::Manual => 'Manual',
            self::System => 'System',
            self::Rule => 'Rule',
        };
    }
}
