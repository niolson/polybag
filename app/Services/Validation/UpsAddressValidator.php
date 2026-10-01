<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\Enums\Deliverability;
use App\Http\Integrations\Ups\Requests\ValidateAddress;
use App\Http\Integrations\Ups\UpsConnector;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Shipment;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Saloon\Exceptions\OAuthConfigValidationException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

/**
 * UPS Street Level Address Validation (XAV), US and Puerto Rico only.
 *
 * Free with a UPS account, but the UPS API Access Agreement limits it to
 * packages intended for UPS and requires a liability notice wherever its
 * results are shown — which validator runs for a shipment is the caller's
 * decision, not this class's.
 */
class UpsAddressValidator implements AddressValidationInterface
{
    public function supports(string $country): bool
    {
        return in_array($country, ['US', 'PR'], true);
    }

    public function validate(Shipment $shipment): void
    {
        $account = $this->resolveAccount($shipment);

        if ($account === null) {
            // No UPS account for this shipment's client — not attempted.
            return;
        }

        $response = $this->fetchValidation($account, $shipment);

        if ($response === null) {
            return;
        }

        $this->processResponse($shipment, $response);
    }

    /**
     * Resolve the UPS carrier account whose credentials should authenticate
     * this shipment's validation request.
     */
    protected function resolveAccount(Shipment $shipment): ?CarrierAccount
    {
        $carrierId = Carrier::where('name', Carrier::UPS)->value('id');

        return $carrierId
            ? CarrierAccount::resolveForShipment($carrierId, null, $shipment->client_id)->first()
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRequestBody(Shipment $shipment): array
    {
        $digits = preg_replace('/\D/', '', (string) $shipment->postal_code);

        // UPS rejects empty strings (every field is minLength 1), so omit
        // anything the shipment doesn't have.
        $addressKeyFormat = array_filter([
            'AddressLine' => array_values(array_filter([$shipment->address1, $shipment->address2])),
            'PoliticalDivision2' => $shipment->city,
            'PoliticalDivision1' => $shipment->state_or_province,
            'PostcodePrimaryLow' => substr($digits, 0, 5),
            'PostcodeExtendedLow' => substr($digits, 5, 4),
            'CountryCode' => $shipment->country ?? 'US',
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return ['XAVRequest' => ['AddressKeyFormat' => $addressKeyFormat]];
    }

    /**
     * @return array<string, mixed>|null Null when the request couldn't be attempted.
     */
    protected function fetchValidation(CarrierAccount $account, Shipment $shipment): ?array
    {
        try {
            $connector = UpsConnector::getAuthenticatedConnector($account);

            $request = new ValidateAddress;
            $request->body()->set($this->buildRequestBody($shipment));

            Log::channel('ups-validation')->debug('VALIDATION REQUEST', [
                'shipment_id' => $shipment->id,
                'body' => $request->body()->all(),
            ]);

            return $connector->send($request)->json();
        } catch (RequestException $e) {
            $status = $e->getResponse()->status();
            $message = $e->getResponse()->json('response.errors.0.message')
                ?? $e->getResponse()->json('errors.0.message')
                ?? $e->getMessage();

            if ($status >= 500 || in_array($status, [401, 403, 429], true)) {
                // UPS never judged this address (outage, rejected credentials,
                // rate limit) — not attempted.
                Log::channel('ups-validation')->warning('UPS Address Validation unavailable', [
                    'status' => $status,
                    'message' => $message,
                    'shipment_id' => $shipment->id,
                ]);

                return null;
            }

            Log::channel('ups-validation')->debug('UPS Address Validation client error', [
                'status' => $status,
                'message' => $message,
                'shipment_id' => $shipment->id,
            ]);

            return ['errors' => [['message' => $message]]];
        } catch (FatalRequestException|HttpClientException|LockTimeoutException|OAuthConfigValidationException|RuntimeException $e) {
            // Connection failure, token acquisition failure, an account
            // missing its client ID or secret, or a UPS OAuth connection that
            // needs reconnecting — not attempted.
            Log::channel('ups-validation')->warning('UPS Address Validation request failed', [
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
        Log::channel('ups-validation')->debug('UPS Address Validation Response', ['response' => $response]);

        if (isset($response['errors'])) {
            $this->markInconclusive($shipment, $response['errors'][0]['message'] ?? 'Unknown error');

            return;
        }

        $xav = $response['XAVResponse'] ?? null;

        if (! is_array($xav)) {
            $this->markInconclusive($shipment, 'Unexpected UPS response format');

            return;
        }

        $candidates = $this->candidates($xav);

        if (array_key_exists('ValidAddressIndicator', $xav) && $candidates !== []) {
            $shipment->checked = true;
            $shipment->deliverability = Deliverability::Yes;
            $shipment->validation_message = 'Address confirmed valid';
            $this->applyValidatedAddress($shipment, $candidates[0], $xav);
            $shipment->save();

            return;
        }

        // Ambiguous results are candidate corrections, not a match — UPS
        // hasn't said which one is right, so leave it to the fallback chain.
        $this->markInconclusive($shipment, match (true) {
            array_key_exists('AmbiguousAddressIndicator', $xav) => 'Multiple addresses were found for the information you entered.',
            array_key_exists('NoCandidatesIndicator', $xav) => 'Address not found',
            default => 'Unexpected UPS response format',
        });
    }

    /**
     * UPS returns a single Candidate as an object rather than a one-item list.
     *
     * @param  array<string, mixed>  $xav
     * @return list<array<string, mixed>>
     */
    protected function candidates(array $xav): array
    {
        $candidates = $xav['Candidate'] ?? [];

        if (! is_array($candidates) || $candidates === []) {
            return [];
        }

        return array_is_list($candidates) ? $candidates : [$candidates];
    }

    /**
     * UPS has no deliverability determination for this address — leave the
     * shipment unchecked so the fallback chain can attempt it.
     */
    protected function markInconclusive(Shipment $shipment, string $message): void
    {
        $shipment->deliverability = Deliverability::No;
        $shipment->validation_message = $message;
        $shipment->save();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $xav
     */
    protected function applyValidatedAddress(Shipment $shipment, array $candidate, array $xav): void
    {
        $address = $candidate['AddressKeyFormat'] ?? [];
        $lines = (array) ($address['AddressLine'] ?? []);

        $shipment->validated_address1 = $lines[0] ?? null;
        $shipment->validated_address2 = $lines[1] ?? null;
        $shipment->validated_city = $address['PoliticalDivision2'] ?? null;
        $shipment->validated_state_or_province = $address['PoliticalDivision1'] ?? null;
        $shipment->validated_postal_code = $this->postalCode($address);
        $shipment->validated_carrier_route = null;

        $classification = $candidate['AddressClassification']['Code']
            ?? $xav['AddressClassification']['Code']
            ?? null;

        $shipment->validated_residential = match ($classification) {
            '2' => true,
            '1' => false,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $address
     */
    protected function postalCode(array $address): ?string
    {
        $primary = $address['PostcodePrimaryLow'] ?? null;

        if (! filled($primary)) {
            return null;
        }

        $extended = $address['PostcodeExtendedLow'] ?? null;

        return filled($extended) ? "{$primary}-{$extended}" : $primary;
    }
}
