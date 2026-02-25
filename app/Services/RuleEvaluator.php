<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\RuleEvaluationResult;
use App\Enums\ShippingRuleAction;
use App\Models\Shipment;
use App\Models\ShippingRule;

class RuleEvaluator
{
    public static function evaluate(Shipment $shipment): RuleEvaluationResult
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
}
