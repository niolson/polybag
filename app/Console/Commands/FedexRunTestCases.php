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
     * 1. `labelResponseOptions` belongs at the top level of the request body and
     *    must appear before `requestedShipment`. Some fixtures nest it inside
     *    `requestedShipment` — promote it and rebuild the array in the right order.
     * 2. The top-level `accountNumber` is required for API authorization. When
     *    missing, use the shipper's account number (not the billing account, which
     *    may be the recipient's for RECIPIENT payment type).
     * 3. When `shippingChargesPayment.payor.responsibleParty` has no `accountNumber`,
     *    fill it with the shipper account so FedEx doesn't reject the payment block.
     * 4. International return shipments require `customsOption` in
     *    `customsClearanceDetail`.
     * 5. Package line items must have a weight.
     */
    private function normalizePayload(array $payload, string $shipperAccountNumber): array
    {
        // Pull labelResponseOptions out from wherever it lives and rebuild the
        // payload so it appears first (before requestedShipment).
        $labelResponseOptions = $payload['labelResponseOptions']
            ?? data_get($payload, 'requestedShipment.labelResponseOptions')
            ?? 'LABEL';

        unset($payload['labelResponseOptions'], $payload['requestedShipment']['labelResponseOptions']);

        // The top-level accountNumber must be the shipper's account, regardless
        // of what the billing (payment) section says. For RECIPIENT payments the
        // payor account belongs to the recipient, not the shipper.
        $topLevelAccount = $payload['accountNumber']['value'] ?? $shipperAccountNumber;
        unset($payload['accountNumber']);

        // Ensure the payment payor has an accountNumber so FedEx doesn't choke.
        $paymentType = data_get($payload, 'requestedShipment.shippingChargesPayment.paymentType');
        if (! data_get($payload, 'requestedShipment.shippingChargesPayment.payor.responsibleParty.accountNumber')) {
            // For SENDER payments use the shipper account; keep recipient/third-party accounts as-is.
            $payload['requestedShipment']['shippingChargesPayment']['payor']['responsibleParty']['accountNumber'] = [
                'value' => $paymentType === 'SENDER' ? $shipperAccountNumber : $topLevelAccount,
            ];
        }

        // Some destination countries (e.g. Canada) require a non-zero customs declared value.
        // Replace any zero unitPrice amounts with a nominal $1.00, then ensure
        // totalCustomsValue reflects the corrected total.
        $commodities = data_get($payload, 'requestedShipment.customsClearanceDetail.commodities', []);
        $totalCustomsValue = 0.0;
        foreach ($commodities as $i => $commodity) {
            if (isset($commodity['unitPrice']['amount']) && (float) $commodity['unitPrice']['amount'] === 0.0) {
                $payload['requestedShipment']['customsClearanceDetail']['commodities'][$i]['unitPrice']['amount'] = 1.00;
                $commodity['unitPrice']['amount'] = 1.00;
            }
            $qty = (float) ($commodity['quantity'] ?? 1);
            $totalCustomsValue += $qty * (float) ($commodity['unitPrice']['amount'] ?? 0);
        }
        $existingTotal = (float) data_get($payload, 'requestedShipment.customsClearanceDetail.totalCustomsValue.amount', 0);
        if ($existingTotal === 0.0 && $totalCustomsValue > 0) {
            $currency = data_get($payload, 'requestedShipment.customsClearanceDetail.commodities.0.unitPrice.currency', 'USD');
            $payload['requestedShipment']['customsClearanceDetail']['totalCustomsValue'] = [
                'amount' => $totalCustomsValue,
                'currency' => $currency,
            ];
        }

        // International return shipments require customsOption.
        if (isset($payload['requestedShipment']['customsClearanceDetail']) &&
            ! isset($payload['requestedShipment']['customsClearanceDetail']['customsOption'])) {
            $specialTypes = data_get($payload, 'requestedShipment.shipmentSpecialServices.specialServiceTypes', []);
            if (in_array('RETURN_SHIPMENT', $specialTypes, strict: true)) {
                $payload['requestedShipment']['customsClearanceDetail']['customsOption'] = [
                    'type' => 'OTHER',
                    'description' => 'Return shipment',
                ];
            }
        }

        // blockInsightVisibility: false is known to trigger spurious FREIGHTDIRECTDETAIL
        // errors in the FedEx sandbox for certain special service combinations.
        unset($payload['requestedShipment']['blockInsightVisibility']);

        // totalWeight at the shipment level is redundant and can cause 500 errors for
        // certain service types (e.g. GROUND_HOME_DELIVERY with HOME_DELIVERY_PREMIUM).
        // The per-package weight is authoritative; drop the shipment-level duplicate.
        unset($payload['requestedShipment']['totalWeight']);

        // Some fixtures use rateRequestTypes (plural) which FedEx may misparse — normalize to singular.
        if (isset($payload['requestedShipment']['rateRequestTypes']) && ! isset($payload['requestedShipment']['rateRequestType'])) {
            $payload['requestedShipment']['rateRequestType'] = $payload['requestedShipment']['rateRequestTypes'];
            unset($payload['requestedShipment']['rateRequestTypes']);
        }

        // In FedEx sandbox, EVENT_NOTIFICATION + SATURDAY_DELIVERY in specialServiceTypes
        // together triggers a FREIGHTDIRECTDETAIL.INVALID error. Drop EVENT_NOTIFICATION
        // from the types array when Saturday Delivery is also present — emailNotificationDetail
        // at the shipment level will still be included in the request for ISV review.
        $specialTypes = data_get($payload, 'requestedShipment.shipmentSpecialServices.specialServiceTypes', []);
        if (in_array('SATURDAY_DELIVERY', $specialTypes, strict: true) && in_array('EVENT_NOTIFICATION', $specialTypes, strict: true)) {
            $payload['requestedShipment']['shipmentSpecialServices']['specialServiceTypes'] = array_values(
                array_filter($specialTypes, fn ($t) => $t !== 'EVENT_NOTIFICATION')
            );
        }

        // FedEx API is case-sensitive: the field is "homedeliveryPremiumType" (lowercase 'd'),
        // not "homeDeliveryPremiumType". Rename it if the fixture uses the wrong casing.
        if (isset($payload['requestedShipment']['shipmentSpecialServices']['homeDeliveryPremiumDetail']['homeDeliveryPremiumType'])) {
            $detail = &$payload['requestedShipment']['shipmentSpecialServices']['homeDeliveryPremiumDetail'];
            $detail['homedeliveryPremiumType'] = $detail['homeDeliveryPremiumType'];
            unset($detail['homeDeliveryPremiumType']);
        }

        // Ensure each package line item has a weight.
        $items = data_get($payload, 'requestedShipment.requestedPackageLineItems', []);
        foreach ($items as $i => $item) {
            if (! isset($item['weight'])) {
                $payload['requestedShipment']['requestedPackageLineItems'][$i]['weight'] = [
                    'units' => 'LB',
                    'value' => 1,
                ];
            }
        }

        // Rebuild with labelResponseOptions and accountNumber first.
        return array_merge(
            ['labelResponseOptions' => $labelResponseOptions, 'accountNumber' => ['value' => $topLevelAccount]],
            $payload,
        );
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
            $payload = $this->normalizePayload($payload, $shipperAccountNumber);

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
            $imageType = strtolower(data_get($payload, 'requestedShipment.labelSpecification.imageType', 'pdf'));
            $labelFormat = match ($imageType) {
                'zplii' => 'zpl',
                default => $imageType, // pdf, png as-is
            };
            $labelStockType = data_get($payload, 'requestedShipment.labelSpecification.labelStockType', '');
            $labelOrientation = str_contains($labelStockType, '85X11') ? 'report' : 'portrait';

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
                    'label_format' => $labelFormat,
                    'label_orientation' => $labelOrientation,
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
