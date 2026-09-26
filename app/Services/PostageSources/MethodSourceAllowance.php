<?php

namespace App\Services\PostageSources;

use App\Enums\PostageSourceKind;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Models\CarrierService;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\SourceServiceMapping;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use Illuminate\Support\Collection;

/**
 * What a shipping method allows a *Use* rule to pick from — ADR-0006 decision
 * 12, `carrier-catalog-reset/07`.
 *
 * A rule picks within this and grants nothing. The sources are the method's
 * source policy rows (`carrier-catalog-reset/09`):
 *
 * - Direct is allowed by the method's `direct` row.
 * - Shopify is allowed by its `shopify` row. It sells the method's services it
 *   has a mapping for, and `auto` when the row allows Shopify's own choice.
 * - Amazon Buy Shipping is still allowed when the method lists the `Amazon`
 *   hook row, until `carrier-catalog-reset/12` moves it to the policy.
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

        $listsAmazon = $this->listedServices($method)->contains(fn (CarrierService $service): bool => $this->isAmazonHookRow($service));

        return array_values(array_filter(
            PostageSourceKind::cases(),
            fn (PostageSourceKind $kind): bool => $kind === PostageSourceKind::Amazon
                ? $listsAmazon
                : $method->allowsSource($kind),
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
     * Whether a *Use* rule on this method may leave the service to the source:
     * Amazon Buy Shipping, whose services are discovered per quote, or Shopify
     * when the method allows its own choice (`auto`).
     */
    public function allowsAnyServiceFor(?ShippingMethod $method, ?ShippingRuleSource $source): bool
    {
        return match ($source) {
            ShippingRuleSource::Amazon => true,
            ShippingRuleSource::Shopify => $method?->allowsUnlistedServices(PostageSourceKind::Shopify) ?? false,
            default => false,
        };
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
            return $this->allowsAnyServiceFor($method, $source);
        }

        $service = $rule->carrierService;

        if ($service === null || $this->isAmazonHookRow($service)) {
            return false;
        }

        if ($source === ShippingRuleSource::Shopify && ! $this->shopifySells($service)) {
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
        $services = ($method !== null
            ? $this->listedServices($method)
            : CarrierService::query()->with('carrier')->get())
            ->reject(fn (CarrierService $service): bool => $this->isAmazonHookRow($service));

        if ($source === ShippingRuleSource::Shopify) {
            $mapped = SourceServiceMapping::forServices(PostageSourceKind::Shopify, $services->pluck('id'));
            $services = $services->filter(fn (CarrierService $service): bool => $mapped->has($service->id));
        }

        return $services->values();
    }

    /**
     * Whether Shopify has a mapping for this service, so can be asked for it.
     */
    private function shopifySells(CarrierService $service): bool
    {
        return SourceServiceMapping::forServices(PostageSourceKind::Shopify, [$service->id])->isNotEmpty();
    }

    /**
     * The `Amazon` hook row poses as a carrier's service until `12` removes it.
     */
    private function isAmazonHookRow(CarrierService $service): bool
    {
        return $service->carrier?->name === AmazonBuyShippingAdapter::SOURCE_NAME;
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
