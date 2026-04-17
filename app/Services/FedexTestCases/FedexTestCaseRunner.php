<?php

namespace App\Services\FedexTestCases;

use App\DataTransferObjects\FedexTestCases\FedexTestCaseData;
use App\Enums\PackageStatus;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\CreateFreightShipment;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FedexTestCaseRunner
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(
        FedexConnector $connector,
        FedexTestCaseData $testCase,
        array $payload,
        bool $saveLabels = false,
        ?string $artifactDirectory = null,
    ): array {
        $request = match ($testCase->requestType) {
            'create_freight_shipment' => new CreateFreightShipment,
            default => new CreateShipment,
        };

        $request->body()->set($payload);

        Log::channel('fedex-validation')->info("=== {$testCase->id}: {$testCase->description} ===");
        Log::channel('fedex-validation')->info('REQUEST', ['payload' => $payload]);

        try {
            $response = $connector->send($request);
        } catch (\Throwable $exception) {
            if (! method_exists($exception, 'getResponse') || $exception->getResponse() === null) {
                Log::channel('fedex-validation')->error("EXCEPTION: {$exception->getMessage()}");

                return [
                    'success' => false,
                    'message' => $exception->getMessage(),
                ];
            }

            $response = $exception->getResponse();
        }

        $body = $response->json();

        Log::channel('fedex-validation')->info('RESPONSE', [
            'status' => $response->status(),
            'body' => $body,
        ]);

        if ($artifactDirectory) {
            Storage::put("{$artifactDirectory}/{$testCase->id}/request.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Storage::put("{$artifactDirectory}/{$testCase->id}/response.json", json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'status' => $response->status(),
                'error_code' => data_get($body, 'errors.0.code', 'UNKNOWN'),
                'message' => data_get($body, 'errors.0.message', 'No message'),
                'response' => $body,
            ];
        }

        $trackingNumber = data_get($body, 'output.transactionShipments.0.pieceResponses.0.trackingNumber')
            ?? data_get($body, 'output.transactionShipments.0.masterTrackingNumber');

        $shipmentPath = isset($payload['freightRequestedShipment']) ? 'freightRequestedShipment' : 'requestedShipment';
        $encodedLabel = data_get($body, 'output.transactionShipments.0.pieceResponses.0.packageDocuments.0.encodedLabel');
        $imageType = strtolower((string) data_get($payload, "{$shipmentPath}.labelSpecification.imageType", 'pdf'));
        $labelFormat = match ($imageType) {
            'zplii' => 'zpl',
            default => $imageType,
        };
        $labelStockType = (string) data_get($payload, "{$shipmentPath}.labelSpecification.labelStockType", '');
        $labelOrientation = str_contains($labelStockType, '85X11') ? 'report' : 'portrait';
        $labelPath = null;

        if ($saveLabels && is_string($encodedLabel) && $encodedLabel !== '') {
            $labelBytes = base64_decode($encodedLabel);
            $labelPath = "{$artifactDirectory}/{$testCase->id}/label.{$labelFormat}";

            if ($artifactDirectory && $labelBytes !== false) {
                Storage::put($labelPath, $labelBytes);
            }
        }

        $recordIds = $this->createStubRecords(
            testCase: $testCase,
            payload: $payload,
            body: is_array($body) ? $body : [],
            trackingNumber: is_string($trackingNumber) ? $trackingNumber : null,
            encodedLabel: is_string($encodedLabel) ? $encodedLabel : null,
            labelFormat: $labelFormat,
            labelOrientation: $labelOrientation,
        );

        return [
            'success' => true,
            'tracking_number' => $trackingNumber,
            'label_path' => $labelPath,
            'package_id' => $recordIds['package_id'] ?? null,
            'shipment_id' => $recordIds['shipment_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $body
     * @return array<string, int|null>
     */
    private function createStubRecords(
        FedexTestCaseData $testCase,
        array $payload,
        array $body,
        ?string $trackingNumber,
        ?string $encodedLabel,
        string $labelFormat,
        string $labelOrientation,
    ): array {
        try {
            $shipmentPath = isset($payload['freightRequestedShipment']) ? 'freightRequestedShipment' : 'requestedShipment';
            $recipient = data_get($payload, "{$shipmentPath}.recipients.0")
                ?? data_get($payload, "{$shipmentPath}.recipient", []);
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
                    'fedex_test_case_id' => $testCase->id,
                    'fedex_test_case_description' => $testCase->description,
                ],
            ]);

            $package = Package::create([
                'shipment_id' => $shipment->id,
                'status' => PackageStatus::Shipped,
                'carrier' => 'FedEx',
                'service' => data_get($body, 'output.transactionShipments.0.serviceType', $testCase->description),
                'tracking_number' => $trackingNumber,
                'label_data' => $encodedLabel,
                'label_format' => $labelFormat,
                'label_orientation' => $labelOrientation,
                'cost' => data_get($body, 'output.transactionShipments.0.completedShipmentDetail.shipmentRating.shipmentRateDetails.0.totalNetFedExCharge'),
                'weight' => data_get($payload, "{$shipmentPath}.requestedPackageLineItems.0.weight.value", 1),
                'height' => 1,
                'width' => 1,
                'length' => 1,
                'shipped_at' => now(),
                'carrier_request_payload' => $payload,
            ]);

            return [
                'package_id' => $package->id,
                'shipment_id' => $shipment->id,
            ];
        } catch (\Throwable $exception) {
            Log::channel('fedex-validation')->warning('Unable to create stub records for FedEx test case', [
                'test_case_id' => $testCase->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'package_id' => null,
                'shipment_id' => null,
            ];
        }
    }
}
