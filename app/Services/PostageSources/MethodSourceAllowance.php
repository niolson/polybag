<?php

namespace App\Services\PostageSources;

use App\Enums\PostageSourceKind;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Models\CarrierService;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\ShopifyAdapter;
use Illuminate\Support\Collection;

/**
 * What a shipping method allows a *Use* rule to pick from — ADR-0006 decision
 * 12, `carrier-catalog-reset/07`.
 *
 * A rule picks within this and grants nothing. Until `09` and `12` give the
 * method a source policy of its own, the sources are read off today's catalog
 * rows, here and nowhere else:
 *
 * - Direct is always allowed.
 * - Amazon Buy Shipping is allowed when the method lists the `Amazon` hook row.
 * - Shopify is allowed when the method lists a row under the `Shopify` carrier.
 *
 * A shipment with no method is allowed every direct service, and nothing else.
 */
class MethodSourceAllowance
{
    /**
     * The source kinds that may sell for this method.
     *
     * @return list<PostageSourceKind>
     */
    public function kindsFor(?ShippingMethod $method): array
    {
        if ($method === null) {
            return [PostageSourceKind::Direct];
        }

        $listed = $this->listedServices($method)
            ->map(fn (CarrierService $service): PostageSourceKind => $this->rowKind($service))
            ->unique();

        return array_values(array_filter(
            PostageSourceKind::cases(),
            fn (PostageSourceKind $kind): bool => $kind === PostageSourceKind::Direct || $listed->contains($kind),
        ));
    }

    /**
     * The priced kinds *any priced source* rate-shops across for this method.
     * A blind purchase never enters it.
     *
     * @return list<PostageSourceKind>
     */
    public function pricedKindsFor(?ShippingMethod $method): array
    {
        return array_values(array_filter(
            $this->kindsFor($method),
            fn (PostageSourceKind $kind): bool => $kind !== PostageSourceKind::Shopify,
        ));
    }

    /**
     * The rule sources a rule on this method may name, for the form.
     *
     * @return list<ShippingRuleSource>
     */
    public function ruleSourcesFor(?ShippingMethod $method, ShippingRuleAction $action): array
    {
        if ($action === ShippingRuleAction::ExcludeService) {
            return ShippingRuleSource::casesFor($action);
        }

        $kinds = $this->kindsFor($method);

        return array_values(array_filter(
            ShippingRuleSource::casesFor($action),
            fn (ShippingRuleSource $source): bool => $source->kind() === null || in_array($source->kind(), $kinds, true),
        ));
    }

    /**
     * Whether a *Use* rule may pick this for a shipment on this method.
     *
     * Evaluated against the shipment's method, not the rule's: a rule with no
     * method still picks only within what the shipment's method lists.
     */
    public function permits(ShippingRule $rule, ?ShippingMethod $method): bool
    {
        $source = $rule->source;
        $kind = $source->kind();

        if ($kind !== null && ! in_array($kind, $this->kindsFor($method), true)) {
            return false;
        }

        if ($source === ShippingRuleSource::AnyPriced && $this->pricedKindsFor($method) === []) {
            return false;
        }

        if ($rule->any_service) {
            return $source === ShippingRuleSource::Amazon;
        }

        $service = $rule->carrierService;

        if ($service === null) {
            return false;
        }

        // Shopify's rows name its own selections until `09`; every other
        // source sells a catalog service a direct account could.
        $expectedRowKind = $source === ShippingRuleSource::Shopify
            ? PostageSourceKind::Shopify
            : PostageSourceKind::Direct;

        if ($this->rowKind($service) !== $expectedRowKind) {
            return false;
        }

        return $method === null || $this->listedServices($method)->contains('id', $service->id);
    }

    /**
     * The services the rule form offers for this source on this method.
     *
     * @return Collection<int, CarrierService>
     */
    public function serviceOptionsFor(?ShippingMethod $method, ?ShippingRuleSource $source): Collection
    {
        $services = $method !== null
            ? $this->listedServices($method)
            : CarrierService::query()->with('carrier')->get();

        return $services
            ->filter(fn (CarrierService $service): bool => match ($source) {
                ShippingRuleSource::Shopify => $this->rowKind($service) === PostageSourceKind::Shopify,
                ShippingRuleSource::Any, null => $this->rowKind($service) !== PostageSourceKind::Amazon,
                default => $this->rowKind($service) === PostageSourceKind::Direct,
            })
            ->values();
    }

    /**
     * Which kind of source a catalog row stands for. The `Amazon` hook row and
     * the `Shopify` rows pose as carriers until `09` and `12` remove them.
     */
    public function rowKind(CarrierService $service): PostageSourceKind
    {
        return match ($service->carrier?->name) {
            AmazonBuyShippingAdapter::SOURCE_NAME => PostageSourceKind::Amazon,
            ShopifyAdapter::CARRIER_NAME => PostageSourceKind::Shopify,
            default => PostageSourceKind::Direct,
        };
    }

    /**
     * @return Collection<int, CarrierService>
     */
    private function listedServices(ShippingMethod $method): Collection
    {
        $method->loadMissing('carrierServices.carrier');

        return $method->carrierServices;
    }
}
