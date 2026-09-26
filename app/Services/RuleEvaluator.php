<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\RuleEvaluationResult;
use App\DataTransferObjects\Shipping\RuleExclusion;
use App\DataTransferObjects\Shipping\RuleRateScope;
use App\Enums\AmazonOrderProgram;
use App\Enums\DestinationZone;
use App\Enums\PostageSourceKind;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\PostageSources\MethodSourceAllowance;

/**
 * Which rules apply to a shipment, and what they choose and exclude.
 *
 * A rule names its source and its service (`carrier-catalog-reset/07`), so
 * nothing here asks what kind of source a catalog row poses as. A *Use* rule
 * picks within what the shipment's method allows ({@see MethodSourceAllowance})
 * and is skipped, not bought, when it names something outside that.
 */
class RuleEvaluator
{
    public function __construct(
        private readonly MethodSourceAllowance $allowance,
    ) {}

    public function evaluate(Shipment $shipment, ?Package $package = null): RuleEvaluationResult
    {
        $rules = ShippingRule::query()
            ->active()
            ->where(function ($query) use ($shipment): void {
                $query->whereNull('shipping_method_id')
                    ->orWhere('shipping_method_id', $shipment->shipping_method_id);
            })
            ->where(function ($query) use ($shipment): void {
                $query->whereNull('client_id')
                    ->orWhere('client_id', $shipment->client_id);
            })
            ->with(['carrierService.carrier', 'carrier'])
            ->get();

        $shipment->loadMissing('shippingMethod.postageSources');
        $method = $shipment->shippingMethod;
        $exclusions = [];

        foreach ($rules as $rule) {
            if (! $this->conditionsMatch($rule->conditions, $shipment, $package)) {
                continue;
            }

            if ($rule->action === ShippingRuleAction::ExcludeService) {
                $exclusions[] = $this->exclusionFor($rule);

                continue;
            }

            if (! $this->allowance->permits($rule, $method)) {
                logger()->debug('Skipped a shipping rule naming something the shipping method does not allow', [
                    'shipping_rule_id' => $rule->id,
                    'shipment_id' => $shipment->id,
                    'shipping_method_id' => $method?->id,
                ]);

                continue;
            }

            return $this->useResult($rule, $method, $exclusions);
        }

        return new RuleEvaluationResult(exclusions: $exclusions);
    }

    /**
     * @param  list<RuleExclusion>  $exclusions
     */
    private function useResult(ShippingRule $rule, ?ShippingMethod $method, array $exclusions): RuleEvaluationResult
    {
        $service = $rule->carrierService;

        return match ($rule->source) {
            // A blind purchase has no rate to pre-select, and nothing invents
            // one (ADR-0003 decision 5). *Any service* is Shopify's own choice.
            ShippingRuleSource::Shopify => new RuleEvaluationResult(
                preSelectedBlindPurchaseId: BlindPurchaseOffer::identifier(
                    ShopifyAdapter::CARRIER_NAME,
                    $rule->any_service ? ShopifyAdapter::AUTO_SERVICE_CODE : (string) ShopifyAdapter::serviceCodeFor($service->id),
                ),
                exclusions: $exclusions,
            ),

            // A rule names a service, never a packaging (ADR-0005 decision 4).
            // It does name the catalog service, so the contents drop can judge
            // a rate an adapter hands back unquoted.
            ShippingRuleSource::Direct => new RuleEvaluationResult(
                preSelectedRate: new RateResponse(
                    carrier: $service->carrier->name,
                    serviceCode: $service->service_code,
                    serviceName: $service->name,
                    price: 0.0,
                    packagingRequirement: PackagingRequirement::shipperPackaging(),
                    carrierServiceId: $service->id,
                    carrierId: $service->carrier_id,
                ),
                exclusions: $exclusions,
            ),

            // Amazon's services are discovered per quote, so there is no rate
            // to pre-select: the caller chooses among what it quotes. An
            // acceptable Amazon offer or nothing (`amazon-buy-shipping/19`).
            ShippingRuleSource::Amazon => new RuleEvaluationResult(
                preSelectedScope: new RuleRateScope(
                    kinds: [PostageSourceKind::Amazon],
                    carrierServiceId: $rule->any_service ? null : $service?->id,
                    strict: true,
                ),
                exclusions: $exclusions,
            ),

            ShippingRuleSource::AnyPriced => new RuleEvaluationResult(
                preSelectedScope: new RuleRateScope(
                    kinds: $this->allowance->pricedKindsFor($method),
                    carrierServiceId: $service->id,
                    strict: false,
                ),
                exclusions: $exclusions,
            ),

            ShippingRuleSource::Any => throw new \LogicException('A Use rule cannot name any source.'),
        };
    }

    private function exclusionFor(ShippingRule $rule): RuleExclusion
    {
        $service = $rule->any_service ? null : $rule->carrierService;

        return new RuleExclusion(
            kind: $rule->source->kind(),
            carrierId: $rule->carrier_id,
            carrierServiceId: $service?->id,
        );
    }

    private function conditionsMatch(?array $conditions, Shipment $shipment, ?Package $package): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! $this->evaluateCondition($condition, $shipment, $package)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateCondition(array $condition, Shipment $shipment, ?Package $package): bool
    {
        $type = $condition['type'] ?? null;
        $data = $condition['data'] ?? [];

        return match ($type) {
            'weight' => $this->evaluateWeight($data, $shipment, $package),
            'order_value' => $this->evaluateOrderValue($data, $shipment),
            'item_count' => $this->evaluateItemCount($data, $shipment),
            'destination_zone' => $this->evaluateDestinationZone($data, $shipment),
            'destination_state' => $this->evaluateDestinationState($data, $shipment),
            'channel' => $this->evaluateChannel($data, $shipment),
            'residential' => $this->evaluateResidential($data, $shipment),
            'amazon_program' => $this->evaluateAmazonProgram($data, $shipment),
            default => true, // Unknown condition types pass (forward compat)
        };
    }

    private function evaluateWeight(array $data, Shipment $shipment, ?Package $package): bool
    {
        if ($package) {
            $weight = (float) $package->weight;
        } else {
            $shipment->loadMissing('shipmentItems.product');
            $weight = $shipment->shipmentItems->sum(fn ($i): int|float => $i->quantity * ($i->product->weight ?? 0));
        }

        return $this->compareNumeric($data, $weight);
    }

    private function evaluateOrderValue(array $data, Shipment $shipment): bool
    {
        return $this->compareNumeric($data, (float) $shipment->value);
    }

    private function evaluateItemCount(array $data, Shipment $shipment): bool
    {
        $shipment->loadMissing('shipmentItems');
        $count = $shipment->shipmentItems->sum('quantity');

        return $this->compareNumeric($data, $count);
    }

    private function evaluateDestinationZone(array $data, Shipment $shipment): bool
    {
        $zone = DestinationZone::tryFrom($data['zone'] ?? '');

        if (! $zone) {
            return true;
        }

        return DestinationZone::matchesShipment($zone, $shipment);
    }

    private function evaluateDestinationState(array $data, Shipment $shipment): bool
    {
        $operator = $data['operator'] ?? 'in';
        $states = $data['states'] ?? [];

        if (empty($states)) {
            return true;
        }

        $state = strtoupper($shipment->validated_state_or_province ?? $shipment->state_or_province ?? '');

        return match ($operator) {
            'in' => in_array($state, $states),
            'not_in' => ! in_array($state, $states),
            default => true,
        };
    }

    private function evaluateChannel(array $data, Shipment $shipment): bool
    {
        $operator = $data['operator'] ?? 'is';
        $channelId = $data['channel_id'] ?? null;

        if ($channelId === null) {
            return true;
        }

        return match ($operator) {
            'is' => $shipment->channel_id == $channelId,
            'is_not' => $shipment->channel_id != $channelId,
            default => true,
        };
    }

    private function evaluateResidential(array $data, Shipment $shipment): bool
    {
        $isResidential = $data['is_residential'] ?? null;

        if ($isResidential === null) {
            return true;
        }

        $shipmentResidential = AddressData::fromShipment($shipment)->isResidential();

        return (bool) $shipmentResidential === (bool) $isResidential;
    }

    private function evaluateAmazonProgram(array $data, Shipment $shipment): bool
    {
        $program = AmazonOrderProgram::tryFrom($data['program'] ?? '');

        if (! $program) {
            return true;
        }

        return $program->appliesTo($shipment);
    }

    private function compareNumeric(array $data, float|int $actual): bool
    {
        $operator = $data['operator'] ?? '>=';
        $value = (float) ($data['value'] ?? 0);

        return match ($operator) {
            '<=' => $actual <= $value,
            '>=' => $actual >= $value,
            'between' => $actual >= $value && $actual <= (float) ($data['max_value'] ?? $value),
            default => true,
        };
    }
}
