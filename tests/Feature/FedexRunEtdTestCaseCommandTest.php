<?php

use App\Console\Commands\FedexRunEtdTestCase;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\UploadEtdImage;
use App\Models\Package;
use App\Models\Shipment;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

it('builds the US09 variant A payload with FedEx-generated ETD document enums', function (): void {
    $command = app(FedexRunEtdTestCase::class);
    $method = new ReflectionMethod(FedexRunEtdTestCase::class, 'buildShipPayload');
    $method->setAccessible(true);

    /** @var array<string, mixed> $payload */
    $payload = $method->invoke(
        $command,
        '700257037',
        'PERSONAL_STATE',
        'letterhead.png',
        'Letterhead',
        true,
    );

    expect(data_get($payload, 'requestedShipment.shipmentSpecialServices.etdDetail.requestedDocumentTypes'))
        ->toBe(['COMMERCIAL_INVOICE'])
        ->and(data_get($payload, 'requestedShipment.shipmentSpecialServices.etdDetail.attachedDocuments'))->toBeNull()
        ->and(data_get($payload, 'requestedShipment.shippingDocumentSpecification.commercialInvoiceDetail.customerImageUsages.0.type'))->toBe('LETTER_HEAD')
        ->and(data_get($payload, 'requestedShipment.shippingDocumentSpecification.commercialInvoiceDetail.customerImageUsages.0.providedImageType'))->toBe('LETTER_HEAD')
        ->and(data_get($payload, 'requestedShipment.shippingDocumentSpecification.commercialInvoiceDetail.customerImageUsages.1.type'))->toBe('SIGNATURE')
        ->and(data_get($payload, 'requestedShipment.shippingDocumentSpecification.commercialInvoiceDetail.customerImageUsages.1.providedImageType'))->toBe('SIGNATURE')
        ->and(data_get($payload, 'requestedShipment.customsClearanceDetail.commodities.0.quantityUnits'))->toBe('PCS');
});

it('builds the US09 variant B payload with uploaded document references', function (): void {
    $command = app(FedexRunEtdTestCase::class);
    $method = new ReflectionMethod(FedexRunEtdTestCase::class, 'buildShipPayload');
    $method->setAccessible(true);

    /** @var array<string, mixed> $payload */
    $payload = $method->invoke(
        $command,
        '700257037',
        'IntegratorUS13',
        'document-doc-id',
        'CommercialInvoice',
        false,
    );

    expect(data_get($payload, 'requestedShipment.shipmentSpecialServices.etdDetail.attachedDocuments.0.documentType'))->toBe('COMMERCIAL_INVOICE')
        ->and(data_get($payload, 'requestedShipment.shipmentSpecialServices.etdDetail.attachedDocuments.0.documentId'))->toBe('document-doc-id')
        ->and(data_get($payload, 'requestedShipment.shipmentSpecialServices.etdDetail.requestedDocumentTypes'))->toBeNull()
        ->and(data_get($payload, 'requestedShipment.shippingDocumentSpecification.commercialInvoiceDetail.customerImageUsages'))->toBeNull();
});

it('creates package records for a successful US09 variant A run', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        UploadEtdImage::class => MockResponse::make([
            'output' => [
                'documentReferenceId' => 'letterhead.png',
            ],
        ]),
        CreateShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'serviceType' => 'FEDEX_INTERNATIONAL_PRIORITY',
                        'pieceResponses' => [
                            [
                                'trackingNumber' => '794804131756',
                                'packageDocuments' => [
                                    [
                                        'encodedLabel' => base64_encode('etd-label-pdf'),
                                    ],
                                ],
                            ],
                        ],
                        'completedShipmentDetail' => [
                            'shipmentRating' => [
                                'shipmentRateDetails' => [
                                    [
                                        'totalNetFedExCharge' => 45.67,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('fedex:run-etd-test', ['--variant' => 'a'])
        ->expectsOutputToContain('Running IntegratorUS09 Variant A')
        ->expectsOutputToContain('Shipment created — Tracking: 794804131756')
        ->expectsOutputToContain('Package record created:')
        ->assertSuccessful();

    expect(Shipment::count())->toBe(1)
        ->and(Package::count())->toBe(1)
        ->and(Package::query()->first()?->tracking_number)->toBe('794804131756')
        ->and(Package::query()->first()?->label_format)->toBe('pdf');
});
