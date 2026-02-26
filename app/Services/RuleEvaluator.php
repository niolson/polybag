<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\RuleEvaluationResult;
use App\Enums\DestinationZone;
use App\Enums\ShippingRuleAction;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingRule;

class RuleEvaluator
{
    public static function evaluate(Shipment $shipment, ?Package $package = null): RuleEvaluationResult
    {
        $rules = ShippingRule::query()
            ->active()
            ->where(function ($query) use ($shipment) {
                $query->whereNull('shipping_method_id')
                    ->orWhere('shipping_method_id', $shipment->shipping_method_id);
            })
            ->with('carrierService.carrier')
            ->get();

        $excludedServiceCodes = [];

        foreach ($rules as $rule) {
            if (! self::conditionsMatch($rule->conditions, $shipment, $package)) {
                continue;
            }

            $service = $rule->carrierService;
            $carrier = $service->carrier;

            match ($rule->action) {
                ShippingRuleAction::UseService => null,
                ShippingRuleAction::ExcludeService => $excludedServiceCodes[] = $service->service_code,
            };

            if ($rule->action === ShippingRuleAction::UseService) {
                $preSelectedRate = new RateResponse(
                    carrier: $carrier->name,
                    serviceCode: $service->service_code,
                    serviceName: $service->name,
                    price: 0.0,
                );

                return new RuleEvaluationResult(
                    preSelectedRate: $preSelectedRate,
                    excludedServiceCodes: $excludedServiceCodes,
                );
            }
        }

        return new RuleEvaluationResult(
            excludedServiceCodes: $excludedServiceCodes,
        );
    }

    private static function conditionsMatch(?array $conditions, Shipment $shipment, ?Package $package): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! self::evaluateCondition($condition, $shipment, $package)) {
                return false;
            }
        }

        return true;
    }

    private static function evaluateCondition(array $condition, Shipment $shipment, ?Package $package): bool
    {
        $type = $condition['type'] ?? null;
        $data = $condition['data'] ?? [];

        return match ($type) {
            'weight' => self::evaluateWeight($data, $shipment, $package),
            'order_value' => self::evaluateOrderValue($data, $shipment),
            'item_count' => self::evaluateItemCount($data, $shipment),
            'destination_zone' => self::evaluateDestinationZone($data, $shipment),
            'destination_state' => self::evaluateDestinationState($data, $shipment),
            'channel' => self::evaluateChannel($data, $shipment),
            'residential' => self::evaluateResidential($data, $shipment),
            default => true, // Unknown condition types pass (forward compat)
        };
    }

    private static function evaluateWeight(array $data, Shipment $shipment, ?Package $package): bool
    {
        if ($package) {
            $weight = (float) $package->weight;
        } else {
            $shipment->loadMissing('shipmentItems.product');
            $weight = $shipment->shipmentItems->sum(fn ($i) => $i->quantity * ($i->product->weight ?? 0));
        }

        return self::compareNumeric($data, $weight);
    }

    private static function evaluateOrderValue(array $data, Shipment $shipment): bool
    {
        return self::compareNumeric($data, (float) $shipment->value);
    }

    private static function evaluateItemCount(array $data, Shipment $shipment): bool
    {
        $shipment->loadMissing('shipmentItems');
        $count = $shipment->shipmentItems->sum('quantity');

        return self::compareNumeric($data, $count);
    }

    private static function evaluateDestinationZone(array $data, Shipment $shipment): bool
    {
        $zone = DestinationZone::tryFrom($data['zone'] ?? '');

        if (! $zone) {
            return true;
        }

        return DestinationZone::matchesShipment($zone, $shipment);
    }

    private static function evaluateDestinationState(array $data, Shipment $shipment): bool
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

    private static function evaluateChannel(array $data, Shipment $shipment): bool
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

    private static function evaluateResidential(array $data, Shipment $shipment): bool
    {
        $isResidential = $data['is_residential'] ?? null;

        if ($isResidential === null) {
            return true;
        }

        $shipmentResidential = $shipment->validated_residential ?? $shipment->residential ?? false;

        return (bool) $shipmentResidential === (bool) $isResidential;
    }

    private static function compareNumeric(array $data, float|int $actual): bool
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
