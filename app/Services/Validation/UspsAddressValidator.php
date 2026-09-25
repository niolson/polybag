<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\Enums\Deliverability;
use App\Http\Integrations\USPS\Requests\Address;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Saloon\Exceptions\OAuthConfigValidationException;
use Saloon\Exceptions\Request\ClientException;
use Saloon\Exceptions\Request\RequestException;

class UspsAddressValidator implements AddressValidationInterface
{
    public function supports(string $country): bool
    {
        return $country === 'US';
    }

    public function validate(Shipment $shipment): void
    {
        $response = $this->fetchValidation($shipment);

        if ($response === null) {
            return;
        }

        $this->processResponse($shipment, $response);
    }

    /**
     * Fetch address validation from USPS API.
     *
     * @return array<string, mixed>|null Null when the API returns a server error.
     */
    protected function fetchValidation(Shipment $shipment): ?array
    {
        try {
            $connector = USPSConnector::getAuthenticatedConnector($this->resolveAccount($shipment));

            $request = new Address;
            $query = [
                'streetAddress' => $shipment->address1,
                'secondaryAddress' => $shipment->address2,
                'city' => $shipment->city,
                'state' => $shipment->state_or_province,
                'ZIPCode' => substr($shipment->postal_code, 0, 5),
            ];
            $request->query()->set($query);

            Log::channel('usps-validation')->debug('VALIDATION REQUEST', [
                'shipment_id' => $shipment->id,
                'query' => $query,
            ]);

            $response = $connector->send($request);

            if ($response->serverError()) {
                Log::channel('usps-validation')->warning('USPS Address Validation server error', [
                    'status' => $response->status(),
                    'shipment_id' => $shipment->id,
                ]);

                return null;
            }

            return json_decode($response->body(), true) ?? [];
        } catch (ClientException $e) {
            $status = $e->getResponse()->status();
            $body = json_decode($e->getResponse()->body(), true);
            $message = $body['error']['message'] ?? $e->getMessage();

            if (in_array($status, [401, 403])) {
                // Access/authorization failure (e.g. missing Addresses API license) —
                // USPS was never actually asked about this address, so leave it
                // unattempted rather than recording a false "not deliverable".
                Log::channel('usps-validation')->warning('USPS Address Validation access denied', [
                    'status' => $status,
                    'message' => $message,
                    'shipment_id' => $shipment->id,
                ]);

                return null;
            }

            Log::channel('usps-validation')->debug('USPS Address Validation client error', [
                'status' => $status,
                'message' => $message,
                'shipment_id' => $shipment->id,
            ]);

            return ['error' => ['message' => $message]];
        } catch (RequestException $e) {
            Log::channel('usps-validation')->warning('USPS Address Validation request failed', [
                'error' => $e->getMessage(),
                'shipment_id' => $shipment->id,
            ]);

            return null;
        } catch (OAuthConfigValidationException $e) {
            // No USPS credentials resolved for this shipment — skip validation
            // gracefully rather than crashing the page with a 500.
            logger()->warning('USPS Address Validation not configured', [
                'error' => $e->getMessage(),
                'shipment_id' => $shipment->id,
            ]);

            return null;
        } catch (RuntimeException $e) {
            // Connector-level configuration error (e.g. sandbox mode incompatible
            // with OAuth) — not attempted, allow fallback to another validator.
            Log::channel('usps-validation')->warning('USPS Address Validation configuration error', [
                'error' => $e->getMessage(),
                'shipment_id' => $shipment->id,
            ]);

            return null;
        }
    }

    /**
     * Resolve the USPS carrier account whose credentials should authenticate
     * this shipment's validation request, falling back to the global default.
     */
    protected function resolveAccount(Shipment $shipment): ?CarrierAccount
    {
        $carrierId = Carrier::where('name', Carrier::USPS)->value('id');

        return $carrierId
            ? CarrierAccount::resolveForShipment($carrierId, null, $shipment->client_id)->first()
            : null;
    }

    /**
     * Process the USPS API response and update the shipment.
     *
     * @param  array<string, mixed>  $response
     */
    protected function processResponse(Shipment $shipment, array $response): void
    {
        Log::channel('usps-validation')->debug('USPS Address Validation Response', ['response' => $response]);

        if (isset($response['error'])) {
            $this->handleError($shipment, $response['error']['message'] ?? 'Unknown error');

            return;
        }

        if ($this->hasCorrections($response)) {
            $this->handleCorrection($shipment, $response);

            return;
        }

        if ($this->isExactMatch($response)) {
            $shipment->checked = true;
            $this->handleExactMatch($shipment, $response);

            return;
        }

        // Unexpected response format — USPS never reached a delivery-point
        // determination, so leave the shipment unchecked to let the fallback
        // chain (e.g. Google) attempt it.
        $shipment->deliverability = Deliverability::No;
        $shipment->validation_message = 'Unexpected USPS response format';
        $shipment->save();
    }

    protected function handleError(Shipment $shipment, string $message): void
    {
        // USPS rejected the request outright — not a real deliverability
        // determination, so leave the shipment unchecked to let the fallback
        // chain (e.g. Google) attempt it.
        $shipment->deliverability = Deliverability::No;
        $shipment->validation_message = $message;
        $shipment->save();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function hasCorrections(array $response): bool
    {
        return isset($response['corrections'][0]['code'])
            && $response['corrections'][0]['code'] !== '';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function handleCorrection(Shipment $shipment, array $response): void
    {
        $code = $response['corrections'][0]['code'];
        $text = $response['corrections'][0]['text'] ?? '';

        switch ($code) {
            case '32':
                // Default address: found but needs more info (apartment, suite, box number).
                // USPS matched a specific base address, so this is a confident partial result.
                $shipment->checked = true;
                $this->applyValidatedAddress($shipment, $response);
                $shipment->deliverability = Deliverability::Maybe;
                $shipment->validation_message = $text;
                break;

            case '22':
                // Multiple addresses found, no default exists — USPS couldn't match a
                // specific address, so leave unchecked to let the fallback chain (e.g.
                // Google) attempt it.
                $shipment->deliverability = Deliverability::No;
                $shipment->validation_message = $text;
                break;

            default:
                // Unknown correction code (e.g. "Address Not Found") — USPS couldn't match
                // a specific address, so leave unchecked to let the fallback chain (e.g.
                // Google) attempt it.
                $shipment->deliverability = Deliverability::No;
                $shipment->validation_message = "Unknown correction code: {$code}";
                break;
        }

        $shipment->save();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function isExactMatch(array $response): bool
    {
        return isset($response['matches'][0]['code'])
            && $response['matches'][0]['code'] === '31';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function handleExactMatch(Shipment $shipment, array $response): void
    {
        $this->applyValidatedAddress($shipment, $response);
        $shipment->save();
    }

    /**
     * Apply the validated address fields and DPV-derived deliverability to the shipment.
     *
     * @param  array<string, mixed>  $response
     */
    protected function applyValidatedAddress(Shipment $shipment, array $response): void
    {
        $dpv = $response['additionalInfo']['DPVConfirmation'] ?? '';
        $carrierRoute = $response['additionalInfo']['carrierRoute'] ?? '';

        // Phantom routes (R777–R779) are physical addresses that USPS cannot deliver to
        if (in_array($carrierRoute, ['R777', 'R778', 'R779'])) {
            $deliverability = Deliverability::No;
            $message = 'Address exists but is not deliverable (phantom route)';
        } else {
            [$deliverability, $message] = match ($dpv) {
                'Y' => [Deliverability::Yes, 'Address confirmed deliverable'],
                'D' => [Deliverability::Maybe, 'Primary address confirmed, secondary number missing'],
                'S' => [Deliverability::Maybe, 'Primary address confirmed, secondary number not confirmed'],
                'N' => [Deliverability::No, 'Address found but not confirmed as deliverable'],
                default => [Deliverability::No, 'DPV confirmation not available'],
            };
        }

        $shipment->deliverability = $deliverability;
        $shipment->validation_message = $message;
        $shipment->validated_carrier_route = $carrierRoute !== '' ? $carrierRoute : null;

        $address = $response['address'] ?? [];
        $shipment->validated_address1 = $address['streetAddress'] ?? null;
        $shipment->validated_address2 = $address['secondaryAddress'] ?? null;
        $shipment->validated_city = $address['city'] ?? null;
        $shipment->validated_state_or_province = $address['state'] ?? null;
        $shipment->validated_postal_code = $address['ZIPCode'] ?? null;
        $businessFlag = $response['additionalInfo']['business'] ?? null;
        $shipment->validated_residential = $businessFlag !== null ? $businessFlag !== 'Y' : null;
    }
}
