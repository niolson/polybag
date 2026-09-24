<?php

namespace App\Enums;

use App\Models\Package;
use App\Models\Product;
use Filament\Support\Contracts\HasLabel;

/**
 * Contents that make a service allowed — ADR-0006 decision 11.
 *
 * A `CarrierService` holds at most one, as `required_contents`, and is offered
 * only to a Package that qualifies for it ({@see Package::qualifiesFor()}).
 * The opposite case, contents that rule a service out (hazmat, alcohol,
 * lithium batteries), is not a content class: those make a Package require a
 * special service, and services that cannot provide it are excluded.
 *
 * On the product side each class is a separate declaration by the seller, one
 * boolean each, because one product could qualify for several. A new class is
 * a new case here plus its product flag. Library Mail is not one: it takes
 * Media Mail's contents plus a condition on the sender and recipient, which is
 * a fact about the shipper rather than the product.
 */
enum ContentClass: string implements HasLabel
{
    case Media = 'media';

    public function getLabel(): string
    {
        return match ($this) {
            self::Media => 'Media',
        };
    }

    /**
     * Whether the seller declared this product as this class. Never inferred.
     */
    public function isDeclaredBy(Product $product): bool
    {
        return match ($this) {
            self::Media => $product->is_media,
        };
    }
}
