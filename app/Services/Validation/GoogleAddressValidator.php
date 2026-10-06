<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\Enums\Deliverability;
use App\Http\Integrations\Google\GoogleAddressValidationConnector;
use App\Http\Integrations\Google\GoogleAddressValidationProxyConnector;
use App\Http\Integrations\Google\Requests\ValidateAddress;
use App\Models\Shipment;
use App\Services\AddressReferenceService;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;

/**
 * Universal fallback validator — supports every country. Placed after
 * UspsAddressValidator in the dispatch chain so USPS gets first crack at
 * US shipments; this catches non-US shipments and any US shipment USPS
 * couldn't attempt (no account, no license, etc).
 */
class GoogleAddressValidator implements AddressValidationInterface
{
    private readonly AddressReferenceService $addressReference;

    public function __construct(?AddressReferenceService $addressReference = null)
    {
        $this->addressReference = $addressReference ?? app(AddressReferenceService::class);
    }

    public function supports(string $country): bool
    {
        return true;
    }

    public function validate(Shipment $shipment): void
    {
        $connector = $this->resolveConnector();

        if ($connector === null) {
            // Not configured (no broker, no local API key) — not attempted.
            return;
        }

        $response = $this->fetchValidation($connector, $shipment);

        if ($response === null) {
            return;
        }

        $this->processResponse($shipment, $response);
    }

    protected function resolveConnector(): ?Connector
    {
        if (config('services.oauth.broker_url')) {
            return new GoogleAddressValidationProxyConnector;
        }

        if (config('services.google_address_validation.api_key')) {
            return new GoogleAddressValidationConnector;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null Null when the request couldn't be attempted.
     */
    protected function fetchValidation(Connector $connector, Shipment $shipment): ?array
    {
        try {
            $country = $shipment->country ?? 'US';

            $address = [
                'regionCode' => $country,
                'addressLines' => array_values(array_filter([$shipment->address1, $shipment->address2])),
                'locality' => $shipment->city,
                'postalCode' => $shipment->postal_code,
            ];

            // Google reports an address incomplete when sent a state its country doesn't use.
            if ($this->addressReference->usesAdministrativeArea($country)) {
                $address['administrativeArea'] = $shipment->state_or_province;
            }

            $request = new ValidateAddress;
            $request->body()->set(['address' => $address]);

            Log::channel('google-validation')->debug('VALIDATION REQUEST', [
                'shipment_id' => $shipment->id,
            ]);

            $response = $connector->send($request);

            if (! $response->successful()) {
                Log::channel('google-validation')->warning('Google Address Validation request failed', [
                    'status' => $response->status(),
                    'shipment_id' => $shipment->id,
                ]);

                return null;
            }

            return $response->json();
        } catch (RequestException $e) {
            Log::channel('google-validation')->warning('Google Address Validation request failed', [
                'error' => $e->getMessage(),
                'shipment_id' => $shipment->id,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function processResponse(Shipment $shipment, array $response): void
    {
        $result = $response['result'] ?? null;

        if ($result === null) {
            return;
        }

        Log::channel('google-validation')->debug('Google Address Validation Response', ['response' => $response]);

        $shipment->checked = true;

        [$deliverability, $message] = $this->classifyResult($result);

        $shipment->deliverability = $deliverability;
        $shipment->validation_message = $message;

        $uspsData = $result['uspsData'] ?? null;
        $carrierRoute = is_array($uspsData) ? ($uspsData['carrierRoute'] ?? null) : null;
        $shipment->validated_carrier_route = $carrierRoute !== '' ? $carrierRoute : null;

        $postalAddress = $result['address']['postalAddress'] ?? [];
        $addressLines = $postalAddress['addressLines'] ?? [];
        $shipment->validated_address1 = $addressLines[0] ?? null;
        $shipment->validated_address2 = $addressLines[1] ?? null;
        $shipment->validated_city = $postalAddress['locality'] ?? null;
        $shipment->validated_state_or_province = $postalAddress['administrativeArea'] ?? null;
        $shipment->validated_postal_code = $postalAddress['postalCode'] ?? null;
        $shipment->validated_residential = $result['metadata']['residential'] ?? null;

        $shipment->save();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{0: Deliverability, 1: string}
     */
    protected function classifyResult(array $result): array
    {
        $uspsData = $result['uspsData'] ?? null;

        if (is_array($uspsData) && ! empty($uspsData['dpvConfirmation'])) {
            return $this->classifyFromUspsData($uspsData);
        }

        return $this->classifyFromVerdict($result);
    }

    /**
     * Google returns USPS's own licensed DPV data for most US addresses
     * (result.uspsData) — prefer it over the geocoding verdict below since
     * it's the same authoritative deliverability signal USPS's own API uses.
     *
     * @param  array<string, mixed>  $uspsData
     * @return array{0: Deliverability, 1: string}
     */
    protected function classifyFromUspsData(array $uspsData): array
    {
        $carrierRoute = $uspsData['carrierRoute'] ?? '';

        if (in_array($carrierRoute, ['R777', 'R778', 'R779'], true)) {
            return [Deliverability::No, 'Address exists but is not deliverable (phantom route)'];
        }

        return match ($uspsData['dpvConfirmation'] ?? '') {
            'Y' => [Deliverability::Yes, 'Address confirmed deliverable'],
            'D' => [Deliverability::Maybe, 'Primary address confirmed, secondary number missing'],
            'S' => [Deliverability::Maybe, 'Primary address confirmed, secondary number not confirmed'],
            'N' => [Deliverability::No, 'Address found but not confirmed as deliverable'],
            default => [Deliverability::No, 'DPV confirmation not available'],
        };
    }

    /**
     * Fallback for addresses Google has no USPS DPV data for (non-US
     * addresses, or US addresses it couldn't standardize to a specific
     * delivery point) — Google's own geocoding-based confidence signal.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: Deliverability, 1: string}
     */
    protected function classifyFromVerdict(array $result): array
    {
        $verdict = $result['verdict'] ?? [];
        $components = $result['address']['addressComponents'] ?? [];

        $hasSuspiciousComponent = collect($components)
            ->contains(fn (array $component): bool => ($component['confirmationLevel'] ?? null) === 'UNCONFIRMED_AND_SUSPICIOUS');

        return match (true) {
            $hasSuspiciousComponent || ! ($verdict['addressComplete'] ?? false) => [
                Deliverability::No, 'Address not confirmed deliverable',
            ],
            $verdict['hasUnconfirmedComponents'] ?? false => [
                Deliverability::Maybe, 'Address confirmed with unconfirmed components',
            ],
            default => [Deliverability::Yes, 'Address confirmed deliverable'],
        };
    }
}
