<?php

namespace App\Console\Commands;

use App\Enums\PackageStatus;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\CreateFreightShipment;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FedexRunTestCases extends Command
{
    protected $signature = 'fedex:run-test-cases
        {cases?* : Test case IDs to run (e.g. IntegratorUS01 IntegratorUS02). Runs all by default.}
        {--fixture= : Path to the test case JSON file (defaults to fedex_us_test_cases.json in project root)}
        {--save-labels : Save label files to storage/app/fedex-test-labels/}';

    protected $description = 'Run FedEx ISV integrator test case shipments and log request/response for validation';

    /**
     * Resolve a single sentinel string value.
     */
    private function resolveValue(mixed $value, string $shipperAccountNumber): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($value) {
            'CURRENT_DATE' => Carbon::today()->format('Y-m-d'),
            'NEXT_FRIDAY' => Carbon::today()->next('Friday')->format('Y-m-d'),
            'USE_1_WEEK_POST' => Carbon::today()->addWeek()->format('Y-m-d'),
            'USE_SHIPPER_ACCOUNT_NUMBER' => $shipperAccountNumber,
            default => $value,
        };
    }

    /**
     * Recursively resolve sentinel strings in a nested array.
     *
     * Rules:
     * - Scalar "COMMENT_OMITTED" values are dropped.
     * - Object keys whose resolved value is an empty array/object are dropped.
     * - List entries that are COMMENT_OMITTED strings are dropped.
     * - List entries that were objects (even if they become empty after stripping)
     *   are kept so FedEx's required structural shapes remain present.
     *
     * Returns either a PHP array or a stdClass (for empty JSON objects in lists).
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
                    // In a list: keep resolved objects even if empty (as stdClass),
                    // but drop empty sub-lists (they were all COMMENT_OMITTED).
                    if ($isChildList && (is_array($resolved) && empty($resolved))) {
                        continue;
                    }
                    $result[] = $resolved;
                } else {
                    // In an object: drop keys whose value resolved to empty (any type).
                    if ((is_array($resolved) && empty($resolved)) || ($resolved instanceof \stdClass && empty((array) $resolved))) {
                        continue;
                    }
                    $result[$key] = $resolved;
                }
            } else {
                $resolved = $this->resolveValue($value, $shipperAccountNumber);
                if ($resolved === 'COMMENT_OMITTED') {
                    continue;
                }
                $result[$key] = $resolved;
            }
        }

        if ($isList) {
            return array_values($result);
        }

        // Return empty associative arrays as stdClass so they encode as {} not [].
        return empty($result) ? new \stdClass : $result;
    }

    /**
     * Apply fixes for known fixture inconsistencies:
     *
     * 1. `labelResponseOptions` belongs at the top level of the request body.
     *    Some fixtures incorrectly nest it inside `requestedShipment` — promote it.
     * 2. Package line items must have a weight. If COMMENT_OMITTED stripped the
     *    weight and left an empty item, inject a minimal 1 LB default so FedEx
     *    doesn't reject with SERVICE.WEIGHT.INVALID.
     */
    private function normalizePayload(array $payload): array
    {
        // Promote labelResponseOptions to the top level if missing.
        if (! isset($payload['labelResponseOptions'])) {
            $nested = data_get($payload, 'requestedShipment.labelResponseOptions');
            if ($nested) {
                $payload['labelResponseOptions'] = $nested;
                unset($payload['requestedShipment']['labelResponseOptions']);
            } else {
                $payload['labelResponseOptions'] = 'LABEL';
            }
        }

        // Ensure each package line item has a weight so FedEx accepts it.
        $items = data_get($payload, 'requestedShipment.requestedPackageLineItems', []);
        foreach ($items as $i => $item) {
            if (! isset($item['weight'])) {
                $payload['requestedShipment']['requestedPackageLineItems'][$i]['weight'] = [
                    'units' => 'LB',
                    'value' => 1,
                ];
            }
        }

        return $payload;
    }

    public function handle(SettingsService $settings): int
    {
        $fixturePath = $this->option('fixture')
            ?? base_path('fedex_us_test_cases.json');

        if (! file_exists($fixturePath)) {
            $this->error("Fixture file not found: {$fixturePath}");

            return self::FAILURE;
        }

        $fixture = json_decode(file_get_contents($fixturePath), associative: true);
        $testCases = collect($fixture['testCases']);

        $requested = $this->argument('cases');
        if (! empty($requested)) {
            $testCases = $testCases->filter(fn ($tc) => in_array($tc['id'], $requested, strict: true));

            if ($testCases->isEmpty()) {
                $this->error('No matching test cases found. Valid IDs: '.implode(', ', collect($fixture['testCases'])->pluck('id')->all()));

                return self::FAILURE;
            }
        }

        // Skip cases that require multi-step flows, Freight LTL, or Locations API lookup
        $unsupported = ['IntegratorUS08', 'IntegratorUS09', 'IntegratorUS10', 'IntegratorUS11'];
        $skipped = $testCases->filter(fn ($tc) => in_array($tc['id'], $unsupported));
        $testCases = $testCases->reject(fn ($tc) => in_array($tc['id'], $unsupported));

        foreach ($skipped as $tc) {
            $this->warn("Skipping {$tc['id']} ({$tc['description']}) — requires multi-step flow, Freight LTL API, or Locations API lookup (out of scope).");
        }

        if ($testCases->isEmpty()) {
            $this->warn('No supported test cases to run.');

            return self::SUCCESS;
        }

        $shipperAccountNumber = (string) $settings->get('fedex.account_number', '');

        if (empty($shipperAccountNumber)) {
            $this->error('FedEx account number not configured in settings (fedex.account_number).');

            return self::FAILURE;
        }

        $connector = FedexConnector::getAuthenticatedConnector();
        $saveLabels = (bool) $this->option('save-labels');

        $passed = 0;
        $failed = 0;

        foreach ($testCases as $tc) {
            $this->info("Running {$tc['id']}: {$tc['description']} ...");

            $payload = $this->resolveArray($tc['request'], $shipperAccountNumber);
            $payload = $this->normalizePayload($payload);

            $request = $tc['api'] === 'Freight LTL API'
                ? new CreateFreightShipment
                : new CreateShipment;

            $request->body()->set($payload);

            Log::channel('fedex-validation')->info("=== {$tc['id']}: {$tc['description']} ===");
            Log::channel('fedex-validation')->info('REQUEST', ['payload' => $payload]);

            try {
                $response = $connector->send($request);
            } catch (\Throwable $e) {
                // Saloon throws RequestException for 4xx/5xx when retries are exhausted.
                // Extract the underlying response if available so we can log it.
                if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                    $response = $e->getResponse();
                } else {
                    $this->error("  ✗ Exception: {$e->getMessage()}");
                    Log::channel('fedex-validation')->error("EXCEPTION: {$e->getMessage()}");
                    $failed++;

                    continue;
                }
            }

            $body = $response->json();

            Log::channel('fedex-validation')->info('RESPONSE', [
                'status' => $response->status(),
                'body' => $body,
            ]);

            if (! $response->successful()) {
                $errorCode = data_get($body, 'errors.0.code', 'UNKNOWN');
                $errorMsg = data_get($body, 'errors.0.message', 'No message');
                $this->error("  ✗ Failed ({$response->status()}): [{$errorCode}] {$errorMsg}");
                $failed++;

                continue;
            }

            $trackingNumber = data_get($body, 'output.transactionShipments.0.pieceResponses.0.trackingNumber')
                ?? data_get($body, 'output.transactionShipments.0.masterTrackingNumber');

            $encodedLabel = data_get($body, 'output.transactionShipments.0.pieceResponses.0.packageDocuments.0.encodedLabel');
            $labelFormat = strtolower($tc['labelFormat'] ?? 'pdf');

            $this->line("  ✓ Tracking: {$trackingNumber}");

            if ($saveLabels && $encodedLabel) {
                $labelBytes = base64_decode($encodedLabel);
                $filename = "fedex-test-labels/{$tc['id']}.{$labelFormat}";
                Storage::put($filename, $labelBytes);
                $this->line('  ✓ Label saved: storage/app/'.$filename);
            }

            // Create a stub Shipment + Package record so the label can be reprinted via the UI.
            try {
                $recipient = data_get($payload, 'requestedShipment.recipients.0', []);
                $shipment = Shipment::create([
                    'city' => data_get($recipient, 'address.city', 'N/A'),
                    'country' => data_get($recipient, 'address.countryCode', 'US'),
                    'first_name' => data_get($recipient, 'contact.personName'),
                    'last_name' => null,
                    'address1' => data_get($recipient, 'address.streetLines.0'),
                    'state' => data_get($recipient, 'address.stateOrProvinceCode'),
                    'postal_code' => data_get($recipient, 'address.postalCode'),
                    'status' => 'shipped',
                    'notes' => "FedEx integrator test case: {$tc['id']} — {$tc['description']}",
                ]);

                $package = Package::create([
                    'shipment_id' => $shipment->id,
                    'status' => PackageStatus::Shipped,
                    'carrier' => 'FedEx',
                    'service' => data_get($body, 'output.transactionShipments.0.serviceType', $tc['description']),
                    'tracking_number' => $trackingNumber,
                    'label_data' => $encodedLabel,
                    'label_orientation' => 'portrait',
                    'cost' => data_get($body, 'output.transactionShipments.0.completedShipmentDetail.shipmentRating.shipmentRateDetails.0.totalNetFedExCharge'),
                    'weight' => data_get($payload, 'requestedShipment.requestedPackageLineItems.0.weight.value', 1),
                    'height' => 1,
                    'width' => 1,
                    'length' => 1,
                    'shipped_at' => now(),
                    'carrier_request_payload' => $payload,
                ]);

                $this->line("  ✓ Package record created: ID {$package->id} (Shipment ID {$shipment->id})");
            } catch (\Throwable $e) {
                $this->warn("  ! Label retrieved but failed to create Package record: {$e->getMessage()}");
            }

            $passed++;
        }

        $this->newLine();
        $this->info("Done. Passed: {$passed}, Failed: {$failed}.");
        $this->line('Full request/response logged to: storage/logs/fedex-validation.log');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
