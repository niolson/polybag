<?php

namespace App\Console\Commands;

use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\AddConsolidationShipment;
use App\Http\Integrations\Fedex\Requests\ConfirmConsolidation;
use App\Http\Integrations\Fedex\Requests\CreateConsolidation;
use App\Http\Integrations\Fedex\Requests\GetConsolidationResults;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Request;

/**
 * Run FedEx IntegratorUS10 — International Priority Distribution Consolidation.
 *
 * Nine-step flow:
 *   1. Create consolidation → extract consolidation key
 *   2–7. Add 6 shipments using the consolidation key
 *   8. Confirm consolidation → extract jobId
 *   9. Get consolidation results using jobId
 *
 * Usage:
 *   php artisan fedex:run-consolidation-test --save-artifacts
 */
class FedexRunConsolidationTestCase extends Command
{
    private const RESULTS_POLL_MAX_ATTEMPTS = 5;

    private const RESULTS_POLL_DELAY_MICROSECONDS = 200_000;

    protected $signature = 'fedex:run-consolidation-test
        {--save-artifacts : Save request/response/label files under storage/app/dev/fedex-test-runs/}';

    protected $description = 'Run FedEx IntegratorUS10 consolidation test case (International Priority Distribution)';

    /** @var array<string, mixed> */
    private array $consolidationKey = [];

    private ?string $jobId = null;

    public function handle(SettingsService $settings): int
    {
        $shipperAccountNumber = (string) $settings->get('fedex.account_number', '');

        if (empty($shipperAccountNumber)) {
            $this->error('FedEx account number not configured in settings (fedex.account_number).');

            return self::FAILURE;
        }

        $saveArtifacts = (bool) $this->option('save-artifacts');
        $artifactDir = $saveArtifacts
            ? 'dev/fedex-test-runs/US10_'.now()->format('Ymd_His')
            : null;

        $connector = FedexConnector::getAuthenticatedConnector();

        $this->info('Running IntegratorUS10 — International Priority Distribution Consolidation');

        // Step 1 — Create consolidation
        if (! $this->step1CreateConsolidation($connector, $artifactDir)) {
            return self::FAILURE;
        }

        // Steps 2–7 — Add 6 shipments
        for ($i = 1; $i <= 6; $i++) {
            if (! $this->stepAddShipment($connector, $i, $artifactDir)) {
                return self::FAILURE;
            }
        }

        // Step 8 — Confirm consolidation
        if (! $this->step8ConfirmConsolidation($connector, $artifactDir)) {
            return self::FAILURE;
        }

        // Step 9 — Get results
        if (! $this->step9GetResults($connector, $artifactDir)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('IntegratorUS10 completed successfully.');

        if ($artifactDir) {
            $this->line("Artifacts saved under: storage/app/{$artifactDir}");
        }

        $this->line('Full request/response logged to: storage/logs/fedex-validation.log');

        return self::SUCCESS;
    }

    private function step1CreateConsolidation(FedexConnector $connector, ?string $artifactDir): bool
    {
        $this->line('  Step 1: Creating consolidation...');

        $payload = [
            'accountNumber' => ['value' => '740561073'],
            'customerTransactionId' => 'IntegratorUS10_Create consolidation',
            'requestedConsolidation' => [
                'consolidationType' => 'INTERNATIONAL_PRIORITY_DISTRIBUTION',
                'consolidationIndex' => '123',
                'specialServicesRequested' => [
                    'specialServiceTypes' => ['INTERNATIONAL_CONTROLLED_EXPORT_SERVICE'],
                    'internationalControlledExportDetail' => [
                        'licenseOrPermitExpirationDate' => '2026-12-18',
                        'licenseOrPermitNumber' => '123',
                        'type' => 'DEA_486',
                    ],
                ],
                'shipper' => [
                    'address' => [
                        'city' => 'MEMPHIS',
                        'countryCode' => 'US',
                        'stateOrProvinceCode' => 'TN',
                        'postalCode' => '38125',
                        'streetLines' => ['GRT TEST ACCOUNT- DO NOT TOUCH', 'DONG SAN HUAN BEI ROAD'],
                    ],
                    'contact' => ['personName' => 'XIAO LI', 'phoneNumber' => '12124567890', 'companyName' => 'ABC COMPANY'],
                    'tins' => [['number' => '59165821389', 'tinType' => 'PERSONAL_NATIONAL']],
                ],
                'consolidationDataSources' => [[
                    'consolidationDataType' => 'TOTAL_FREIGHT_CHARGES',
                    'consolidationDataSourceType' => 'ACCUMULATED',
                ]],
                'consolidationDocumentSpecification' => [
                    'consolidatedCommercialInvoiceDetail' => [
                        'format' => ['stockType' => 'PAPER_LETTER', 'docType' => 'PDF'],
                    ],
                    'consolidationDocumentTypes' => ['CONSOLIDATED_COMMERCIAL_INVOICE'],
                ],
                'soldTo' => [
                    'address' => [
                        'city' => 'TORONTO',
                        'countryCode' => 'CA',
                        'postalCode' => 'M1M1M1',
                        'streetLines' => ['MORELOS 417 COL CENTRO', 'Suite 101'],
                        'stateOrProvinceCode' => 'ON',
                    ],
                    'contact' => ['personName' => 'SHPC-440836-CL2203C8', 'phoneNumber' => '9012633035', 'companyName' => 'GRT'],
                    'accountNumber' => ['value' => '740561073'],
                ],
                'labelSpecification' => ['labelFormatType' => 'COMMON2D', 'labelStockType' => 'PAPER_4X6', 'imageType' => 'PNG'],
                'customsClearanceDetail' => [
                    'importerOfRecord' => [
                        'address' => [
                            'city' => 'TORONTO',
                            'countryCode' => 'CA',
                            'postalCode' => 'M1M1M1',
                            'streetLines' => ['MORELOS 417 COL CENTRO', 'Suite 101'],
                            'stateOrProvinceCode' => 'ON',
                        ],
                        'contact' => ['personName' => 'SHPC-440836-CL2203C8', 'phoneNumber' => '9012633035', 'companyName' => 'GRT'],
                    ],
                    'customsValue' => ['amount' => 200, 'currency' => 'USD'],
                    'dutiesPayment' => [
                        'payor' => ['responsibleParty' => ['address' => ['countryCode' => 'US'], 'accountNumber' => ['value' => '740561073']]],
                        'billingDetails' => '740561073',
                        'paymentType' => 'THIRD_PARTY',
                    ],
                    'documentContent' => 'NON_DOCUMENTS',
                    'recipientCustomsId' => ['type' => 'COMPANY', 'value' => '125'],
                ],
                'origin' => [
                    'address' => [
                        'city' => 'MEMPHIS',
                        'countryCode' => 'US',
                        'stateOrProvinceCode' => 'TN',
                        'postalCode' => '38125',
                        'streetLines' => ['GRT TEST ACCOUNT- DO NOT TOUCH', 'DONG SAN HUAN BEI ROAD'],
                    ],
                    'contact' => ['personName' => 'XIAO LI', 'phoneNumber' => '12124567890', 'companyName' => 'ABC COMPANY'],
                ],
                'internationalDistributionDetail' => [
                    'declarationCurrencies' => [['currency' => 'USD', 'value' => 'CUSTOMS_VALUE']],
                    'totalDimensions' => ['length' => 10, 'width' => 10, 'height' => 10, 'units' => 'IN'],
                    'clearanceFacilityLocationId' => 'YWGI',
                    'dropOffType' => 'REGULAR_PICKUP',
                    'totalInsuredValue' => ['amount' => 200, 'currency' => 'USD'],
                    'unitSystem' => 'ENGLISH',
                ],
                'shippingChargesPayment' => [
                    'payor' => ['responsibleParty' => ['address' => ['countryCode' => 'US'], 'accountNumber' => ['value' => '740561073']]],
                    'paymentType' => 'THIRD_PARTY',
                ],
            ],
        ];

        $response = $this->sendRequest($connector, new CreateConsolidation, $payload, 'Step1_CreateConsolidation', $artifactDir);

        if ($response === null) {
            return false;
        }

        $key = data_get($response, 'output.consolidationKey');

        if (! is_array($key) || empty($key['index'])) {
            $this->error('  ✗ No consolidationKey in create consolidation response.');
            Log::channel('fedex-validation')->error('US10: Missing consolidationKey in response', $response);

            return false;
        }

        $this->consolidationKey = $key;
        $this->line("  ✓ Consolidation created — key: type={$key['type']}, index={$key['index']}, date={$key['date']}");

        return true;
    }

    private function stepAddShipment(FedexConnector $connector, int $shipmentNumber, ?string $artifactDir): bool
    {
        $this->line('  Step '.($shipmentNumber + 1).": Adding shipment {$shipmentNumber}/6...");

        $payload = [
            'accountNumber' => ['value' => '740561073'],
            'customerTransactionId' => "IntegratorUS10_Add shipment {$shipmentNumber}",
            'consolidationKey' => $this->consolidationKey,
            'processingOptionType' => 'ALLOW_ASYNCHRONOUS',
            'labelResponseOptions' => 'LABEL',
            'shipAction' => 'CONFIRM',
            'requestedShipment' => [
                'serviceType' => 'INTERNATIONAL_PRIORITY_DISTRIBUTION',
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'dropoffType' => 'REGULAR_PICKUP',
                'shipDatestamp' => now()->format('Y-m-d'),
                'packagingType' => 'YOUR_PACKAGING',
                'shipper' => [
                    'address' => [
                        'city' => 'MEMPHIS',
                        'countryCode' => 'US',
                        'postalCode' => '38125',
                        'streetLines' => ['80 FedEx Parkway', 'Suite 101'],
                    ],
                    'contact' => ['personName' => 'Shipper name', 'phoneNumber' => '12124567890', 'companyName' => 'Shipper company'],
                ],
                'recipients' => [[
                    'address' => [
                        'streetLines' => ['RTC', '4011 MALDEN RD'],
                        'city' => 'TORONTO',
                        'countryCode' => 'CA',
                        'postalCode' => 'M1M1M1',
                        'stateOrProvinceCode' => 'ON',
                    ],
                    'contact' => ['personName' => 'TEst name', 'phoneNumber' => '19512390523', 'companyName' => 'Test company'],
                ]],
                'origin' => [
                    'address' => [
                        'city' => 'MEMPHIS',
                        'countryCode' => 'US',
                        'postalCode' => '38125',
                        'streetLines' => ['80 FedEx Parkway', 'Suite 101'],
                    ],
                    'contact' => ['personName' => 'Origin name', 'phoneNumber' => '12124567890', 'companyName' => 'Origin COMPANY'],
                ],
                'labelSpecification' => [
                    'labelFormatType' => 'COMMON2D',
                    'labelStockType' => 'PAPER_4X6',
                    'imageType' => 'PNG',
                    'printedLabelOrigin' => [
                        'address' => [
                            'streetLines' => ['RTC', '4011 MALDEN RD'],
                            'city' => 'TORONTO',
                            'countryCode' => 'CA',
                            'postalCode' => 'M1M1M1',
                            'stateOrProvinceCode' => 'ON',
                        ],
                        'contact' => [
                            'phoneExtension' => '10',
                            'personName' => 'TestAutomation',
                            'emailAddress' => 'abc123456@fedex.com',
                            'phoneNumber' => '19512390523',
                            'companyName' => 'COMPANYA',
                        ],
                    ],
                ],
                'customerReferences' => [
                    ['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => 'i-f89999-tc0203-c1'],
                    ['customerReferenceType' => 'INVOICE_NUMBER', 'value' => 'i-f89999-tc0203-c1'],
                ],
                'customsClearanceDetail' => [
                    'dutiesPayment' => [
                        'payor' => ['responsibleParty' => ['address' => ['countryCode' => 'US'], 'accountNumber' => ['value' => '740561073']]],
                        'billingDetails' => '740561073',
                        'paymentType' => 'THIRD_PARTY',
                    ],
                    'totalCustomsValue' => ['amount' => 500, 'currency' => 'USD'],
                    'isDocumentOnly' => false,
                ],
                'shippingChargesPayment' => [
                    'payor' => ['responsibleParty' => ['address' => ['countryCode' => 'US'], 'accountNumber' => ['value' => '740561073']]],
                    'paymentType' => 'THIRD_PARTY',
                ],
                'specialServicesRequested' => [
                    'specialServiceTypes' => ['INTERNATIONAL_CONTROLLED_EXPORT_SERVICE'],
                    'internationalControlledExportDetail' => [
                        'licenseOrPermitExpirationDate' => '2026-12-18',
                        'licenseOrPermitNumber' => '123',
                        'type' => 'DEA_486',
                    ],
                ],
                'requestedPackageLineItems' => [[
                    'groupPackageCount' => 35,
                    'declaredValue' => ['amount' => 14.28, 'currency' => 'USD'],
                    'weight' => ['units' => 'LB', 'value' => 120],
                    'commodities' => [[
                        'unitPrice' => ['amount' => 500, 'currency' => 'USD'],
                        'numberOfPieces' => 1,
                        'quantity' => 1,
                        'customsValue' => ['amount' => 500, 'currency' => 'USD'],
                        'countryOfManufacture' => 'CA',
                        'harmonizedCode' => '4901990010',
                        'description' => 'Textbooks',
                        'weight' => ['units' => 'LB', 'value' => 100],
                        'quantityUnits' => 'EA',
                    ]],
                ]],
                'processingOption' => [
                    'options' => ['PACKAGE_LEVEL_COMMODITIES'],
                ],
            ],
        ];

        $response = $this->sendRequest(
            $connector,
            new AddConsolidationShipment,
            $payload,
            'Step'.($shipmentNumber + 1)."_AddShipment{$shipmentNumber}",
            $artifactDir,
        );

        if ($response === null) {
            return false;
        }

        $tracking = data_get($response, 'output.transactionShipments.0.pieceResponses.0.trackingNumber')
            ?? data_get($response, 'output.transactionShipments.0.masterTrackingNumber')
            ?? 'N/A';

        $this->line("  ✓ Shipment {$shipmentNumber} added — Tracking: {$tracking}");

        if ($artifactDir) {
            $encodedLabel = data_get($response, 'output.transactionShipments.0.pieceResponses.0.packageDocuments.0.encodedLabel');

            if (is_string($encodedLabel) && $encodedLabel !== '') {
                Storage::put("{$artifactDir}/Step".($shipmentNumber + 1)."_AddShipment{$shipmentNumber}/label.png", base64_decode($encodedLabel));
            }
        }

        return true;
    }

    private function step8ConfirmConsolidation(FedexConnector $connector, ?string $artifactDir): bool
    {
        $this->line('  Step 8: Confirming consolidation...');

        $payload = [
            'accountNumber' => ['value' => '740561073'],
            'customerTransactionId' => 'IntegratorUS10_Confirm Consolidation',
            'consolidationKey' => $this->consolidationKey,
            'labelSpecification' => ['labelFormatType' => 'COMMON2D', 'labelStockType' => 'PAPER_4X6', 'imageType' => 'PDF'],
            'processingOptionType' => 'ALLOW_ASYNCHRONOUS',
            'edtRequestType' => 'ALL',
        ];

        $response = $this->sendRequest($connector, new ConfirmConsolidation, $payload, 'Step8_ConfirmConsolidation', $artifactDir);

        if ($response === null) {
            return false;
        }

        $this->jobId = data_get($response, 'output.jobId');

        if (! $this->jobId) {
            // Synchronous response — no jobId needed for Step 9
            $this->line('  ✓ Consolidation confirmed (synchronous response — no jobId)');

            return true;
        }

        $this->line("  ✓ Consolidation confirmed — jobId: {$this->jobId}");

        return true;
    }

    private function step9GetResults(FedexConnector $connector, ?string $artifactDir): bool
    {
        if (! $this->jobId) {
            $this->line('  Step 9: Skipped — synchronous confirmation returned no jobId.');

            return true;
        }

        $this->line('  Step 9: Getting consolidation results...');

        $payload = [
            'accountNumber' => ['value' => '740561073'],
            'jobId' => $this->jobId,
        ];

        for ($attempt = 1; $attempt <= self::RESULTS_POLL_MAX_ATTEMPTS; $attempt++) {
            $result = $this->sendRequestResult(
                $connector,
                new GetConsolidationResults,
                $payload,
                'Step9_GetResults',
                $artifactDir,
                emitError: false,
            );

            if ($result['success']) {
                /** @var array<string, mixed> $response */
                $response = $result['body'];
                $shipmentCount = count(data_get($response, 'output.transactionShipments', []));
                $this->line("  ✓ Results received — {$shipmentCount} transaction shipment(s).");

                return true;
            }

            $code = $result['error_code'] ?? 'UNKNOWN';
            $message = $result['message'] ?? 'No message';
            $status = $result['status'] ?? 'ERR';

            if ($code !== 'SHIPMENT.REPLYDATA.NOTREADY' || $attempt === self::RESULTS_POLL_MAX_ATTEMPTS) {
                $this->error("  ✗ Step9_GetResults failed ({$status}): [{$code}] {$message}");

                return false;
            }

            $this->line("  … Results not ready yet, retrying ({$attempt}/".self::RESULTS_POLL_MAX_ATTEMPTS.')');
            usleep(self::RESULTS_POLL_DELAY_MICROSECONDS);
        }

        return false;
    }

    /**
     * Send a request, log it, save artifacts if requested, and return the decoded body.
     * Returns null and prints an error on failure.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function sendRequest(
        FedexConnector $connector,
        Request $request,
        array $payload,
        string $stepLabel,
        ?string $artifactDir,
    ): ?array {
        $result = $this->sendRequestResult($connector, $request, $payload, $stepLabel, $artifactDir, emitError: true);

        return $result['success'] ? $result['body'] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, body: array<string, mixed>, status?: int, error_code?: string, message?: string}
     */
    private function sendRequestResult(
        FedexConnector $connector,
        Request $request,
        array $payload,
        string $stepLabel,
        ?string $artifactDir,
        bool $emitError,
    ): array {
        $request->body()->set($payload);

        Log::channel('fedex-validation')->info("=== US10 {$stepLabel} ===");
        Log::channel('fedex-validation')->info('REQUEST', ['payload' => $payload]);

        try {
            $response = $connector->send($request);
        } catch (\Throwable $e) {
            if (! method_exists($e, 'getResponse') || $e->getResponse() === null) {
                if ($emitError) {
                    $this->error("  ✗ {$stepLabel} exception: {$e->getMessage()}");
                }
                Log::channel('fedex-validation')->error("{$stepLabel} EXCEPTION: {$e->getMessage()}");

                return [
                    'success' => false,
                    'body' => [],
                    'message' => $e->getMessage(),
                ];
            }

            $response = $e->getResponse();
        }

        $responseBody = $response->json() ?? [];
        Log::channel('fedex-validation')->info("{$stepLabel} RESPONSE", ['status' => $response->status(), 'body' => $responseBody]);

        if ($artifactDir) {
            Storage::put("{$artifactDir}/{$stepLabel}/request.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Storage::put("{$artifactDir}/{$stepLabel}/response.json", json_encode($responseBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! $response->successful()) {
            $code = data_get($responseBody, 'errors.0.code', 'UNKNOWN');
            $message = data_get($responseBody, 'errors.0.message', 'No message');

            if ($emitError) {
                $this->error("  ✗ {$stepLabel} failed ({$response->status()}): [{$code}] {$message}");
            }

            return [
                'success' => false,
                'body' => is_array($responseBody) ? $responseBody : [],
                'status' => $response->status(),
                'error_code' => $code,
                'message' => $message,
            ];
        }

        return [
            'success' => true,
            'body' => is_array($responseBody) ? $responseBody : [],
        ];
    }
}
