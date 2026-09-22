<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\RuleEvaluationResult;
use App\Enums\DestinationZone;
use App\Enums\ShippingRuleAction;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingRule;
use App\Services\Carriers\CarrierRegistry;

class RuleEvaluator
{
    public function __construct(
        private readonly CarrierRegistry $carrierRegistry,
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
            ->with('carrierService.carrier')
            ->get();

        $excludedServiceCodes = [];
        $excludedBlindPurchaseIds = [];

        foreach ($rules as $rule) {
            if (! $this->conditionsMatch($rule->conditions, $shipment, $package)) {
                continue;
            }

            $service = $rule->carrierService;
            $carrier = $service->carrier;
            $action = $rule->getAttribute('action');
            $blindPurchaseSource = $this->carrierRegistry->blindPurchaseSourceFor($carrier->name);

            if ($action === ShippingRuleAction::ExcludeService) {
                if ($blindPurchaseSource) {
                    $excludedBlindPurchaseIds[] = BlindPurchaseOffer::identifier(
                        $carrier->name,
                        $service->service_code,
                    );
                } else {
                    $excludedServiceCodes[] = $service->service_code;
                }

                continue;
            }

            if ($action === ShippingRuleAction::UseService) {
                if ($blindPurchaseSource) {
                    return new RuleEvaluationResult(
                        preSelectedBlindPurchaseId: BlindPurchaseOffer::identifier(
                            $carrier->name,
                            $service->service_code,
                        ),
                        excludedServiceCodes: $excludedServiceCodes,
                        excludedBlindPurchaseIds: $excludedBlindPurchaseIds,
                    );
                }

                // A rule names a service, never a packaging (ADR-0005 decision 4).
                $preSelectedRate = new RateResponse(
                    carrier: $carrier->name,
                    serviceCode: $service->service_code,
                    serviceName: $service->name,
                    price: 0.0,
                    packagingRequirement: PackagingRequirement::shipperPackaging(),
                );

                return new RuleEvaluationResult(
                    preSelectedRate: $preSelectedRate,
                    excludedServiceCodes: $excludedServiceCodes,
                    excludedBlindPurchaseIds: $excludedBlindPurchaseIds,
                );
            }
        }

        return new RuleEvaluationResult(
            excludedServiceCodes: $excludedServiceCodes,
            excludedBlindPurchaseIds: $excludedBlindPurchaseIds,
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
