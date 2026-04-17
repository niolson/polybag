<?php

namespace App\Console\Commands;

use App\Enums\PackageStatus;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\UploadEtdDocument;
use App\Http\Integrations\Fedex\Requests\UploadEtdImage;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Request;

/**
 * Run FedEx IntegratorUS09 — ETD (Electronic Trade Documents) test cases.
 *
 * Variant A: Upload letterhead + signature images, then ship (3 API calls).
 * Variant B: Upload a commercial invoice PDF, then ship (2 API calls).
 *
 * Usage:
 *   php artisan fedex:run-etd-test --variant=a --save-artifacts
 *   php artisan fedex:run-etd-test --variant=b --save-artifacts
 */
class FedexRunEtdTestCase extends Command
{
    protected $signature = 'fedex:run-etd-test
        {--variant=a : ETD variant to run — "a" (upload images) or "b" (upload document)}
        {--save-artifacts : Save request/response/label files under storage/app/dev/fedex-test-runs/}';

    protected $description = 'Run FedEx IntegratorUS09 ETD test case (Electronic Trade Documents)';

    private const ASSET_DIR = 'resources/data/carrier-test-cases/fedex/etd-assets';

    public function handle(SettingsService $settings): int
    {
        $variant = strtolower((string) $this->option('variant'));

        if (! in_array($variant, ['a', 'b'], true)) {
            $this->error('Invalid variant. Use --variant=a or --variant=b.');

            return self::FAILURE;
        }

        $shipperAccountNumber = (string) $settings->get('fedex.account_number', '');

        if (empty($shipperAccountNumber)) {
            $this->error('FedEx account number not configured in settings (fedex.account_number).');

            return self::FAILURE;
        }

        $saveArtifacts = (bool) $this->option('save-artifacts');
        $artifactDir = $saveArtifacts
            ? 'dev/fedex-test-runs/US09_'.now()->format('Ymd_His')
            : null;

        $connector = FedexConnector::getAuthenticatedConnector();

        return $variant === 'a'
            ? $this->runVariantA($connector, $shipperAccountNumber, $artifactDir)
            : $this->runVariantB($connector, $shipperAccountNumber, $artifactDir);
    }

    private function runVariantA(FedexConnector $connector, string $shipperAccountNumber, ?string $artifactDir): int
    {
        $this->info('Running IntegratorUS09 Variant A (letterhead + signature image upload)...');

        // Step 1 — Upload letterhead image
        $this->line('  Step 1: Uploading letterhead image...');
        $letterheadPath = base_path(self::ASSET_DIR.'/letterhead.png');

        if (! file_exists($letterheadPath)) {
            $this->error("Letterhead asset not found: {$letterheadPath}");

            return self::FAILURE;
        }

        $letterheadContent = (string) file_get_contents($letterheadPath);
        $letterheadRequest = new UploadEtdImage(
            imageType: 'LETTERHEAD',
            imageIndex: 'IMAGE_1',
            filename: 'letterhead.png',
            fileContent: $letterheadContent,
        );

        $letterheadResponse = $this->sendRequest($connector, $letterheadRequest, 'Step1_UploadLetterhead', $artifactDir);

        if ($letterheadResponse === null) {
            return self::FAILURE;
        }

        $letterheadDocId = data_get($letterheadResponse, 'output.documentReferenceId');

        if (! $letterheadDocId) {
            $this->error('  ✗ No documentReferenceId in letterhead upload response.');
            Log::channel('fedex-validation')->error('US09-A: Missing documentReferenceId in letterhead response', $letterheadResponse);

            return self::FAILURE;
        }

        $this->line("  ✓ Letterhead uploaded — documentReferenceId: {$letterheadDocId}");

        // Step 2 — Upload signature image
        $this->line('  Step 2: Uploading signature image...');
        $signaturePath = base_path(self::ASSET_DIR.'/signature.png');

        if (! file_exists($signaturePath)) {
            $this->error("Signature asset not found: {$signaturePath}");

            return self::FAILURE;
        }

        $signatureContent = (string) file_get_contents($signaturePath);
        $signatureRequest = new UploadEtdImage(
            imageType: 'SIGNATURE',
            imageIndex: 'IMAGE_2',
            filename: 'signature.png',
            fileContent: $signatureContent,
        );

        $signatureResponse = $this->sendRequest($connector, $signatureRequest, 'Step2_UploadSignature', $artifactDir);

        if ($signatureResponse === null) {
            return self::FAILURE;
        }

        $signatureDocId = data_get($signatureResponse, 'output.documentReferenceId');
        $this->line("  ✓ Signature uploaded — documentReferenceId: {$signatureDocId}");

        // Step 3 — Ship with ETD using uploaded images
        $this->line('  Step 3: Creating ETD shipment...');

        $shipPayload = $this->buildShipPayload(
            shipperAccountNumber: $shipperAccountNumber,
            originPersonName: 'PERSONAL_STATE',
            attachedDocId: (string) $letterheadDocId,
            attachedDocDescription: 'Letterhead',
            includeImageUsages: true,
        );

        $shipResponse = $this->sendRequest($connector, new CreateShipment, 'Step3_Ship', $artifactDir, $shipPayload);

        if ($shipResponse === null) {
            return self::FAILURE;
        }

        return $this->handleShipResponse($shipResponse, $shipPayload, $artifactDir, 'US09-A');
    }

    private function runVariantB(FedexConnector $connector, string $shipperAccountNumber, ?string $artifactDir): int
    {
        $this->info('Running IntegratorUS09 Variant B (commercial invoice PDF upload)...');

        // Step 1 — Upload commercial invoice PDF
        $this->line('  Step 1: Uploading commercial invoice PDF...');

        $pdfContent = $this->minimalPdf();
        $uploadRequest = new UploadEtdDocument(
            filename: 'commercial-invoice.pdf',
            contentType: 'application/pdf',
            originCountryCode: 'US',
            destCountryCode: 'GB',
            fileContent: $pdfContent,
        );

        $uploadResponse = $this->sendRequest($connector, $uploadRequest, 'Step1_UploadDocument', $artifactDir);

        if ($uploadResponse === null) {
            return self::FAILURE;
        }

        $docId = data_get($uploadResponse, 'output.meta.docId');

        if (! $docId) {
            $this->error('  ✗ No docId in document upload response.');
            Log::channel('fedex-validation')->error('US09-B: Missing docId in upload response', $uploadResponse);

            return self::FAILURE;
        }

        $this->line("  ✓ Document uploaded — docId: {$docId}");

        // Step 2 — Ship with ETD using uploaded document
        $this->line('  Step 2: Creating ETD shipment...');

        $shipPayload = $this->buildShipPayload(
            shipperAccountNumber: $shipperAccountNumber,
            originPersonName: 'IntegratorUS13',
            attachedDocId: (string) $docId,
            attachedDocDescription: 'CommercialInvoice',
            includeImageUsages: false,
        );

        $shipResponse = $this->sendRequest($connector, new CreateShipment, 'Step2_Ship', $artifactDir, $shipPayload);

        if ($shipResponse === null) {
            return self::FAILURE;
        }

        return $this->handleShipResponse($shipResponse, $shipPayload, $artifactDir, 'US09-B');
    }

    /**
     * Send a request, log it, save artifacts if requested, and return the decoded body.
     * Returns null and prints an error on failure.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null
     */
    private function sendRequest(
        FedexConnector $connector,
        Request $request,
        string $stepLabel,
        ?string $artifactDir,
        ?array $body = null,
    ): ?array {
        if ($body !== null) {
            $request->body()->set($body);
        }

        Log::channel('fedex-validation')->info("=== US09 {$stepLabel} ===");

        if ($body !== null) {
            Log::channel('fedex-validation')->info('REQUEST', ['payload' => $body]);
        }

        try {
            $response = $connector->send($request);
        } catch (\Throwable $e) {
            if (! method_exists($e, 'getResponse') || $e->getResponse() === null) {
                $this->error("  ✗ {$stepLabel} exception: {$e->getMessage()}");
                Log::channel('fedex-validation')->error("{$stepLabel} EXCEPTION: {$e->getMessage()}");

                return null;
            }

            $response = $e->getResponse();
        }

        $responseBody = $response->json() ?? [];
        Log::channel('fedex-validation')->info("{$stepLabel} RESPONSE", ['status' => $response->status(), 'body' => $responseBody]);

        if ($artifactDir) {
            if ($body !== null) {
                Storage::put("{$artifactDir}/{$stepLabel}/request.json", json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            Storage::put("{$artifactDir}/{$stepLabel}/response.json", json_encode($responseBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! $response->successful()) {
            $code = data_get($responseBody, 'errors.0.code', 'UNKNOWN');
            $message = data_get($responseBody, 'errors.0.message', 'No message');
            $this->error("  ✗ {$stepLabel} failed ({$response->status()}): [{$code}] {$message}");

            return null;
        }

        return $responseBody;
    }

    /**
     * @param  array<string, mixed>  $shipResponse
     * @param  array<string, mixed>  $shipPayload
     */
    private function handleShipResponse(array $shipResponse, array $shipPayload, ?string $artifactDir, string $label): int
    {
        $trackingNumber = data_get($shipResponse, 'output.transactionShipments.0.pieceResponses.0.trackingNumber')
            ?? data_get($shipResponse, 'output.transactionShipments.0.masterTrackingNumber');

        $encodedLabel = data_get($shipResponse, 'output.transactionShipments.0.pieceResponses.0.packageDocuments.0.encodedLabel');
        $imageType = strtolower((string) data_get($shipPayload, 'requestedShipment.labelSpecification.imageType', 'pdf'));
        $labelFormat = match ($imageType) {
            'zplii' => 'zpl',
            default => $imageType,
        };

        $this->line("  ✓ Shipment created — Tracking: {$trackingNumber}");

        if ($artifactDir && is_string($encodedLabel) && $encodedLabel !== '') {
            $labelPath = "{$artifactDir}/label.pdf";
            Storage::put($labelPath, base64_decode($encodedLabel));
            $this->line("  ✓ Label saved: storage/app/{$labelPath}");
        }

        if ($artifactDir) {
            $this->line("  Artifacts saved under: storage/app/{$artifactDir}");
        }

        $recordIds = $this->createStubRecords(
            shipPayload: $shipPayload,
            shipResponse: $shipResponse,
            trackingNumber: is_string($trackingNumber) ? $trackingNumber : null,
            encodedLabel: is_string($encodedLabel) ? $encodedLabel : null,
            labelFormat: $labelFormat,
        );

        if ($recordIds['package_id'] && $recordIds['shipment_id']) {
            $this->line("  ✓ Package record created: ID {$recordIds['package_id']} (Shipment ID {$recordIds['shipment_id']})");
        }

        $this->line('  Full request/response logged to: storage/logs/fedex-validation.log');
        Log::channel('fedex-validation')->info("{$label} completed — tracking: {$trackingNumber}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $shipPayload
     * @param  array<string, mixed>  $shipResponse
     * @return array<string, int|null>
     */
    private function createStubRecords(
        array $shipPayload,
        array $shipResponse,
        ?string $trackingNumber,
        ?string $encodedLabel,
        string $labelFormat,
    ): array {
        try {
            $recipient = data_get($shipPayload, 'requestedShipment.recipients.0', []);
            $shipment = Shipment::create([
                'city' => data_get($recipient, 'address.city', 'N/A'),
                'country' => data_get($recipient, 'address.countryCode', 'US'),
                'first_name' => data_get($recipient, 'contact.personName'),
                'last_name' => null,
                'address1' => data_get($recipient, 'address.streetLines.0'),
                'state_or_province' => data_get($recipient, 'address.stateOrProvinceCode'),
                'postal_code' => data_get($recipient, 'address.postalCode'),
                'status' => 'shipped',
                'metadata' => [
                    'fedex_test_case_id' => 'IntegratorUS09',
                    'fedex_test_case_description' => 'Electronic Trade Documents',
                ],
            ]);

            $package = Package::create([
                'shipment_id' => $shipment->id,
                'status' => PackageStatus::Shipped,
                'carrier' => 'FedEx',
                'service' => data_get($shipResponse, 'output.transactionShipments.0.serviceType', 'IntegratorUS09'),
                'tracking_number' => $trackingNumber,
                'label_data' => $encodedLabel,
                'label_format' => $labelFormat,
                'label_orientation' => 'report',
                'cost' => data_get($shipResponse, 'output.transactionShipments.0.completedShipmentDetail.shipmentRating.shipmentRateDetails.0.totalNetFedExCharge'),
                'weight' => data_get($shipPayload, 'requestedShipment.requestedPackageLineItems.0.weight.value', 1),
                'height' => 1,
                'width' => 1,
                'length' => 1,
                'shipped_at' => now(),
                'carrier_request_payload' => $shipPayload,
            ]);

            return [
                'package_id' => $package->id,
                'shipment_id' => $shipment->id,
            ];
        } catch (\Throwable $exception) {
            Log::channel('fedex-validation')->warning('Unable to create stub records for US09 ETD test case', [
                'error' => $exception->getMessage(),
            ]);

            return [
                'package_id' => null,
                'shipment_id' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShipPayload(
        string $shipperAccountNumber,
        string $originPersonName,
        string $attachedDocId,
        string $attachedDocDescription,
        bool $includeImageUsages,
    ): array {
        $commercialInvoiceDetail = [
            'documentFormat' => ['docType' => 'PDF', 'stockType' => 'PAPER_LETTER'],
        ];

        if ($includeImageUsages) {
            $commercialInvoiceDetail['customerImageUsages'] = [
                ['id' => 'IMAGE_1', 'type' => 'LETTER_HEAD', 'providedImageType' => 'LETTER_HEAD'],
                ['id' => 'IMAGE_2', 'type' => 'SIGNATURE', 'providedImageType' => 'SIGNATURE'],
            ];
        }

        $etdDetail = $includeImageUsages
            ? ['requestedDocumentTypes' => ['COMMERCIAL_INVOICE']]
            : [
                'attachedDocuments' => [[
                    'documentType' => 'COMMERCIAL_INVOICE',
                    'documentId' => $attachedDocId,
                    'description' => $attachedDocDescription,
                ]],
            ];

        return [
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => ['value' => $shipperAccountNumber],
            'customerTransactionId' => 'IntegratorUS09',
            'requestedShipment' => [
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'serviceType' => 'FEDEX_INTERNATIONAL_PRIORITY',
                'packagingType' => 'YOUR_PACKAGING',
                'totalWeight' => 10,
                'rateRequestType' => ['LIST'],
                'preferredCurrency' => 'USD',
                'totalPackageCount' => 1,
                'shipper' => [
                    'contact' => ['personName' => 'PERSONAL_STATE', 'companyName' => 'Integrator', 'phoneNumber' => '9015551234'],
                    'address' => ['streetLines' => ['1751 THOMPSON ST'], 'city' => 'AURORA', 'stateOrProvinceCode' => 'OH', 'postalCode' => '44202', 'countryCode' => 'US'],
                ],
                'recipients' => [[
                    'contact' => ['personName' => 'IntegratorUS10', 'companyName' => 'ABC Widget Co', 'phoneNumber' => '9561324887'],
                    'address' => ['streetLines' => ['80 Fedex Prkwy'], 'city' => 'London', 'stateOrProvinceCode' => 'GB', 'postalCode' => 'W1T1JY', 'countryCode' => 'GB'],
                ]],
                'origin' => [
                    'contact' => ['personName' => $originPersonName, 'companyName' => 'Integrator', 'phoneNumber' => '9015551234'],
                    'address' => ['streetLines' => ['1751 THOMPSON ST'], 'city' => 'AURORA', 'stateOrProvinceCode' => 'OH', 'postalCode' => '44202', 'countryCode' => 'US'],
                ],
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                    'payor' => ['responsibleParty' => ['accountNumber' => ['value' => $shipperAccountNumber]]],
                ],
                'shipmentSpecialServices' => [
                    'specialServiceTypes' => ['ELECTRONIC_TRADE_DOCUMENTS'],
                    'etdDetail' => $etdDetail,
                ],
                'customsClearanceDetail' => [
                    'dutiesPayment' => [
                        'paymentType' => 'SENDER',
                        'payor' => [
                            'responsibleParty' => [
                                'accountNumber' => ['value' => $shipperAccountNumber],
                                'address' => ['streetLines' => ['15 W 18TH ST FL 7'], 'city' => 'NEW YORK', 'stateOrProvinceCode' => 'NY', 'postalCode' => '10011-4624', 'countryCode' => 'US'],
                            ],
                        ],
                    ],
                    'isDocumentOnly' => false,
                    'commercialInvoice' => ['termsOfSale' => 'DDP', 'declarationStatement' => 'originatorName'],
                    'totalCustomsValue' => ['amount' => 25, 'currency' => 'USD'],
                    'commodities' => [[
                        'numberOfPieces' => 1,
                        'description' => 'Computer Keyboard',
                        'countryOfManufacture' => 'US',
                        'weight' => ['units' => 'LB', 'value' => 10],
                        'quantity' => 1,
                        'quantityUnits' => 'PCS',
                        'unitPrice' => ['currency' => 'USD', 'amount' => 25],
                        'customsValue' => ['currency' => 'USD', 'amount' => 25],
                    ]],
                ],
                'labelSpecification' => ['labelFormatType' => 'COMMON2D', 'imageType' => 'PDF', 'labelStockType' => 'PAPER_85X11_TOP_HALF_LABEL'],
                'shippingDocumentSpecification' => [
                    'shippingDocumentTypes' => ['COMMERCIAL_INVOICE'],
                    'commercialInvoiceDetail' => $commercialInvoiceDetail,
                ],
                'requestedPackageLineItems' => [[
                    'sequenceNumber' => 1,
                    'weight' => ['units' => 'LB', 'value' => 10],
                    'dimensions' => ['length' => 5, 'width' => 5, 'height' => 5, 'units' => 'IN'],
                    'customerReferences' => [['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => 'ref1234']],
                ]],
            ],
        ];
    }

    /**
     * Generate a minimal valid PDF for Variant B testing.
     * FedEx sandbox does not validate PDF content, only that the upload succeeds.
     */
    private function minimalPdf(): string
    {
        $body = "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n"
            ."xref\n0 4\n"
            ."0000000000 65535 f \n"
            ."0000000009 00000 n \n"
            ."0000000058 00000 n \n"
            ."0000000115 00000 n \n"
            ."trailer<</Size 4/Root 1 0 R>>\n"
            ."startxref\n213\n%%EOF";

        return $body;
    }
}
