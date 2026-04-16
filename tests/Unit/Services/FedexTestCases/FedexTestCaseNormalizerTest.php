<?php

use App\DataTransferObjects\FedexTestCases\FedexTestCaseData;
use App\Services\FedexTestCases\FedexTestCaseNormalizer;

it('resolves placeholders and promotes top-level shipment fields', function (): void {
    $testCase = new FedexTestCaseData(
        id: 'Case01',
        description: 'Normalization test',
        requestType: 'create_shipment',
        request: [
            'requestedShipment' => [
                'shipDatestamp' => '{{ current_date }}',
                'labelResponseOptions' => 'LABEL',
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                    'payor' => [
                        'responsibleParty' => [],
                    ],
                ],
                'requestedPackageLineItems' => [
                    [],
                ],
            ],
        ],
    );

    $payload = app(FedexTestCaseNormalizer::class)->normalize($testCase, '123456789');

    expect($payload['labelResponseOptions'])->toBe('LABEL')
        ->and($payload['accountNumber']['value'])->toBe('123456789')
        ->and($payload['requestedShipment']['shipDatestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and(data_get($payload, 'requestedShipment.shippingChargesPayment.payor.responsibleParty.accountNumber.value'))->toBe('123456789')
        ->and(data_get($payload, 'requestedShipment.requestedPackageLineItems.0.weight.value'))->toBe(1);
});

it('preserves event notifications alongside Saturday-delivery special service types', function (): void {
    $testCase = new FedexTestCaseData(
        id: 'Case02',
        description: 'Saturday notification normalization',
        requestType: 'create_shipment',
        request: [
            'requestedShipment' => [
                'shipmentSpecialServices' => [
                    'specialServiceTypes' => ['EVENT_NOTIFICATION', 'SATURDAY_DELIVERY'],
                ],
                'requestedPackageLineItems' => [
                    ['weight' => ['units' => 'LB', 'value' => 2]],
                ],
            ],
        ],
    );

    $payload = app(FedexTestCaseNormalizer::class)->normalize($testCase, '123456789');

    expect(data_get($payload, 'requestedShipment.shipmentSpecialServices.specialServiceTypes'))
        ->toBe(['EVENT_NOTIFICATION', 'SATURDAY_DELIVERY']);
});
