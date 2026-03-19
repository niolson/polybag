<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\Enums\Deliverability;
use App\Events\AddressValidationFailed;
use App\Http\Integrations\USPS\Requests\Address;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Shipment;
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
            $connector = USPSConnector::getAuthenticatedConnector();

            $request = new Address;
            $request->query()->set([
                'streetAddress' => $shipment->address1,
                'secondaryAddress' => $shipment->address2,
                'city' => $shipment->city,
                'state' => $shipment->state_or_province,
                'ZIPCode' => substr($shipment->postal_code, 0, 5),
            ]);

            $response = $connector->send($request);

            if ($response->serverError()) {
                logger()->warning('USPS Address Validation server error', [
                    'status' => $response->status(),
                    'shipment_id' => $shipment->id,
                ]);

                return null;
            }

            return json_decode($response->body(), true) ?? [];
        } catch (ClientException $e) {
            $body = json_decode($e->getResponse()->body(), true);
            $message = $body['error']['message'] ?? $e->getMessage();

            logger()->debug('USPS Address Validation client error', [
                'status' => $e->getResponse()->status(),
                'message' => $message,
                'shipment_id' => $shipment->id,
            ]);

            return ['error' => ['message' => $message]];
        } catch (RequestException $e) {
            logger()->warning('USPS Address Validation request failed', [
                'error' => $e->getMessage(),
                'shipment_id' => $shipment->id,
            ]);

            return null;
        }
    }

    /**
     * Process the USPS API response and update the shipment.
     *
     * @param  array<string, mixed>  $response
     */
    protected function processResponse(Shipment $shipment, array $response): void
    {
        $shipment->checked = true;
        logger()->debug('USPS Address Validation Response', ['response' => $response]);

        if (isset($response['error'])) {
            $this->handleError($shipment, $response['error']['message'] ?? 'Unknown error');

            return;
        }

        if ($this->hasCorrections($response)) {
            $this->handleCorrection($shipment, $response);

            return;
        }

        if ($this->isExactMatch($response)) {
            $this->handleExactMatch($shipment, $response);

            return;
        }

        // Unexpected response format
        $shipment->deliverability = Deliverability::No;
        $shipment->validation_message = 'Unexpected USPS response format';
        $shipment->save();

        AddressValidationFailed::dispatch($shipment, 'Unexpected USPS response format');
    }

    protected function handleError(Shipment $shipment, string $message): void
    {
        $shipment->deliverability = Deliverability::No;
        $shipment->validation_message = $message;
        $shipment->save();

        AddressValidationFailed::dispatch($shipment, $message);
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
                // Default address: found but needs more info (apartment, suite, box number)
                $this->applyValidatedAddress($shipment, $response);
                $shipment->deliverability = Deliverability::Maybe;
                $shipment->validation_message = $text;
                break;

            case '22':
                // Multiple addresses found, no default exists
                $shipment->deliverability = Deliverability::No;
                $shipment->validation_message = $text;
                $validationFailed = $text;
                break;

            default:
                // Unknown correction code
                $shipment->deliverability = Deliverability::No;
                $shipment->validation_message = "Unknown correction code: {$code}";
                $validationFailed = "Unknown correction code: {$code}";
                break;
        }

        $shipment->save();

        if (isset($validationFailed)) {
            AddressValidationFailed::dispatch($shipment, $validationFailed);
        }
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

        [$deliverability, $message] = match ($dpv) {
            'Y' => [Deliverability::Yes, 'Address confirmed deliverable'],
            'D' => [Deliverability::Maybe, 'Primary address confirmed, secondary number missing'],
            'S' => [Deliverability::Maybe, 'Primary address confirmed, secondary number not confirmed'],
            'N' => [Deliverability::No, 'Address found but not confirmed as deliverable'],
            default => [Deliverability::No, 'DPV confirmation not available'],
        };

        $shipment->deliverability = $deliverability;
        $shipment->validation_message = $message;

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
