<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a shipping rule buys, or which purchases it excludes —
 * `carrier-catalog-reset/07`.
 *
 * The three named cases are the {@see PostageSourceKind} cases. The two *any*
 * values belong to rules alone: *any priced source* rate-shops one service
 * across every source that quotes a price, and *any source* lets an *Exclude*
 * rule match whoever sells the purchase.
 */
enum ShippingRuleSource: string implements HasLabel
{
    case Direct = 'direct';
    case Shopify = 'shopify';
    case Amazon = 'amazon';

    /** *Use* only. Never a blind purchase. */
    case AnyPriced = 'any_priced';

    /** *Exclude* only. */
    case Any = 'any';

    public function getLabel(): string
    {
        return match ($this) {
            self::Direct, self::Shopify, self::Amazon => PostageSourceKind::from($this->value)->label(),
            self::AnyPriced => 'Any priced source',
            self::Any => 'Any source',
        };
    }

    /**
     * The one kind this names, or null for the two *any* values.
     */
    public function kind(): ?PostageSourceKind
    {
        return PostageSourceKind::tryFrom($this->value);
    }

    public static function fromKind(PostageSourceKind $kind): self
    {
        return self::from($kind->value);
    }

    /**
     * The values a rule with this action may name.
     *
     * @return list<self>
     */
    public static function casesFor(ShippingRuleAction $action): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $source): bool => $source->allowedFor($action),
        ));
    }

    public function allowedFor(ShippingRuleAction $action): bool
    {
        return match ($this) {
            self::AnyPriced => $action === ShippingRuleAction::UseService,
            self::Any => $action === ShippingRuleAction::ExcludeService,
            default => true,
        };
    }
}
