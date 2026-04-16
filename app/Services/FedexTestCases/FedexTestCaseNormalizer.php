<?php

namespace App\Services\FedexTestCases;

use App\DataTransferObjects\FedexTestCases\FedexTestCaseData;
use Carbon\CarbonImmutable;

class FedexTestCaseNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(FedexTestCaseData $testCase, string $shipperAccountNumber): array
    {
        $payload = $this->resolveArray($testCase->request, $shipperAccountNumber);

        return $this->normalizePayload($payload, $shipperAccountNumber);
    }

    private function resolveValue(mixed $value, string $shipperAccountNumber): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($value) {
            'CURRENT_DATE', '{{ current_date }}' => CarbonImmutable::today()->format('Y-m-d'),
            'NEXT_FRIDAY', '{{ next_friday }}' => CarbonImmutable::today()->next('Friday')->format('Y-m-d'),
            'USE_1_WEEK_POST', '{{ one_week_from_today }}' => CarbonImmutable::today()->addWeek()->format('Y-m-d'),
            'USE_SHIPPER_ACCOUNT_NUMBER', '{{ shipper_account_number }}' => $shipperAccountNumber,
            default => $value,
        };
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @return array<int|string, mixed>|\stdClass
     */
    private function resolveArray(array $data, string $shipperAccountNumber): array|\stdClass
    {
        $isList = array_is_list($data);
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $isChildList = array_is_list($value);
                $resolved = $this->resolveArray($value, $shipperAccountNumber);

                if ($isList) {
                    if ($isChildList && is_array($resolved) && empty($resolved)) {
                        if ($value === []) {
                            $result[] = new \stdClass;
                        }

                        continue;
                    }

                    $result[] = $resolved;

                    continue;
                }

                if ((is_array($resolved) && empty($resolved)) || ($resolved instanceof \stdClass && empty((array) $resolved))) {
                    continue;
                }

                $result[$key] = $resolved;

                continue;
            }

            $resolved = $this->resolveValue($value, $shipperAccountNumber);

            if ($resolved === 'COMMENT_OMITTED') {
                continue;
            }

            $result[$key] = $resolved;
        }

        if ($isList) {
            return array_values($result);
        }

        return empty($result) ? new \stdClass : $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, string $shipperAccountNumber): array
    {
        $labelResponseOptions = $payload['labelResponseOptions']
            ?? data_get($payload, 'requestedShipment.labelResponseOptions')
            ?? 'LABEL';

        unset($payload['labelResponseOptions'], $payload['requestedShipment']['labelResponseOptions']);

        $topLevelAccount = $payload['accountNumber']['value'] ?? $shipperAccountNumber;
        unset($payload['accountNumber']);

        $paymentType = data_get($payload, 'requestedShipment.shippingChargesPayment.paymentType');

        if (! data_get($payload, 'requestedShipment.shippingChargesPayment.payor.responsibleParty.accountNumber')) {
            $payload['requestedShipment']['shippingChargesPayment']['payor']['responsibleParty']['accountNumber'] = [
                'value' => $paymentType === 'SENDER' ? $shipperAccountNumber : $topLevelAccount,
            ];
        }

        $commodities = data_get($payload, 'requestedShipment.customsClearanceDetail.commodities', []);
        $totalCustomsValue = 0.0;

        foreach ($commodities as $index => $commodity) {
            if (isset($commodity['unitPrice']['amount']) && (float) $commodity['unitPrice']['amount'] === 0.0) {
                $payload['requestedShipment']['customsClearanceDetail']['commodities'][$index]['unitPrice']['amount'] = 1.00;
                $commodity['unitPrice']['amount'] = 1.00;
            }

            $quantity = (float) ($commodity['quantity'] ?? 1);
            $totalCustomsValue += $quantity * (float) ($commodity['unitPrice']['amount'] ?? 0);
        }

        $existingTotal = (float) data_get($payload, 'requestedShipment.customsClearanceDetail.totalCustomsValue.amount', 0);

        if ($existingTotal === 0.0 && $totalCustomsValue > 0) {
            $currency = data_get($payload, 'requestedShipment.customsClearanceDetail.commodities.0.unitPrice.currency', 'USD');
            $payload['requestedShipment']['customsClearanceDetail']['totalCustomsValue'] = [
                'amount' => $totalCustomsValue,
                'currency' => $currency,
            ];
        }

        if (
            isset($payload['requestedShipment']['customsClearanceDetail'])
            && ! isset($payload['requestedShipment']['customsClearanceDetail']['customsOption'])
        ) {
            $specialTypes = data_get($payload, 'requestedShipment.shipmentSpecialServices.specialServiceTypes', []);

            if (in_array('RETURN_SHIPMENT', $specialTypes, true)) {
                $payload['requestedShipment']['customsClearanceDetail']['customsOption'] = [
                    'type' => 'OTHER',
                    'description' => 'Return shipment',
                ];
            }
        }

        unset($payload['requestedShipment']['blockInsightVisibility']);
        unset($payload['requestedShipment']['totalWeight']);

        if (isset($payload['requestedShipment']['rateRequestTypes']) && ! isset($payload['requestedShipment']['rateRequestType'])) {
            $payload['requestedShipment']['rateRequestType'] = $payload['requestedShipment']['rateRequestTypes'];
            unset($payload['requestedShipment']['rateRequestTypes']);
        }

        if (isset($payload['requestedShipment']['shipmentSpecialServices']['homeDeliveryPremiumDetail']['homeDeliveryPremiumType'])) {
            $detail = &$payload['requestedShipment']['shipmentSpecialServices']['homeDeliveryPremiumDetail'];
            $detail['homedeliveryPremiumType'] = $detail['homeDeliveryPremiumType'];
            unset($detail['homeDeliveryPremiumType']);
        }

        $items = data_get($payload, 'requestedShipment.requestedPackageLineItems', []);

        foreach ($items as $index => $item) {
            if (! is_array($item) || ! isset($item['weight'])) {
                if (! is_array($payload['requestedShipment']['requestedPackageLineItems'][$index] ?? null)) {
                    $payload['requestedShipment']['requestedPackageLineItems'][$index] = [];
                }

                $payload['requestedShipment']['requestedPackageLineItems'][$index]['weight'] = [
                    'units' => 'LB',
                    'value' => 1,
                ];
            }
        }

        return array_merge(
            [
                'labelResponseOptions' => $labelResponseOptions,
                'accountNumber' => ['value' => $topLevelAccount],
            ],
            $payload,
        );
    }
}
