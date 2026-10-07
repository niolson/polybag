<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\DataTransferObjects\AddressValidationResult;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\ValidationReason;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\ValidateAddress;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\Carriers\FedexSubdivision;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

/**
 * FedEx Address Validation Service (POST /address/v1/addresses/resolve).
 *
 * Free with a FedEx account, but the FedEx EULA limits it to shipments
 * tendered to FedEx — which validator runs for a shipment is the caller's
 * decision, not this class's.
 */
class FedexAddressValidator implements AddressValidationInterface
{
    public function validator(): AddressValidator
    {
        return AddressValidator::Fedex;
    }

    public function supports(string $country): bool
    {
        return AddressValidationCountries::fedexSupports($country);
    }

    public function validate(Shipment $shipment): AddressValidationResult
    {
        $account = $this->resolveAccount($shipment);

        if ($account === null) {
            // No FedEx account for this shipment's client — not attempted.
            return AddressValidationResult::unavailable();
        }

        $response = $this->fetchValidation($account, $shipment);

        if ($response === null) {
            return AddressValidationResult::unavailable();
        }

        return $this->processResponse($shipment, $response);
    }

    /**
     * Resolve the FedEx carrier account whose credentials should authenticate
     * this shipment's validation request — the one the validation plan found.
     */
    protected function resolveAccount(Shipment $shipment): ?CarrierAccount
    {
        $carrierId = Carrier::where('name', Carrier::FEDEX)->value('id');

        return $carrierId
            ? CarrierAccount::resolveForAddressValidation($carrierId, $shipment)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRequestBody(Shipment $shipment): array
    {
        return [
            'addressesToValidate' => [
                [
                    'address' => array_filter([
                        'streetLines' => array_values(array_filter([$shipment->address1, $shipment->address2])),
                        'city' => $shipment->city,
                        'stateOrProvinceCode' => FedexSubdivision::code($shipment->country ?? 'US', $shipment->state_or_province),
                        'postalCode' => $shipment->postal_code,
                        'countryCode' => $shipment->country ?? 'US',
                    ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null Null when the request couldn't be attempted.
     */
    protected function fetchValidation(CarrierAccount $account, Shipment $shipment): ?array
    {
        try {
            $connector = FedexConnector::getAuthenticatedConnector($account);

            $request = new ValidateAddress;
            $request->body()->set($this->buildRequestBody($shipment));

            Log::channel('fedex-validation')->debug('VALIDATION REQUEST', [
                'shipment_id' => $shipment->id,
                'body' => $request->body()->all(),
            ]);

            return $connector->send($request)->json();
        } catch (RequestException $e) {
            $status = $e->getResponse()->status();
            $message = $e->getResponse()->json('errors.0.message') ?? $e->getMessage();

            if ($status >= 500 || in_array($status, [401, 403, 429], true)) {
                // FedEx never judged this address (outage, missing Address
                // Validation API on the project, rate limit) — not attempted.
                Log::channel('fedex-validation')->warning('FedEx Address Validation unavailable', [
                    'status' => $status,
                    'message' => $message,
                    'shipment_id' => $shipment->id,
                ]);

                return null;
            }

            Log::channel('fedex-validation')->debug('FedEx Address Validation client error', [
                'status' => $status,
                'message' => $message,
                'shipment_id' => $shipment->id,
            ]);

            return ['errors' => [['message' => $message]]];
        } catch (FatalRequestException|HttpClientException|LockTimeoutException|RuntimeException $e) {
            // Connection failure or token acquisition failure — not attempted.
            Log::channel('fedex-validation')->warning('FedEx Address Validation request failed', [
                'error' => $e->getMessage(),
                'shipment_id' => $shipment->id,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function processResponse(Shipment $shipment, array $response): AddressValidationResult
    {
        Log::channel('fedex-validation')->debug('FedEx Address Validation Response', ['response' => $response]);

        if (isset($response['errors'])) {
            return $this->markInconclusive($shipment, $response['errors'][0]['message'] ?? 'Unknown error', ValidationReason::RequestRejected);
        }

        $resolved = $response['output']['resolvedAddresses'][0] ?? null;

        if (! is_array($resolved)) {
            return $this->markInconclusive($shipment, 'Unexpected FedEx response format', ValidationReason::UnexpectedResponse);
        }

        if (! AddressValidationCountries::fedexReadsDeliveryPoint($shipment->country ?? 'US')) {
            return $this->processInternational($shipment, $resolved);
        }

        $attributes = $resolved['attributes'] ?? [];

        if (! $this->flag($attributes, 'Resolved')) {
            return $this->markInconclusive($shipment, 'Address could not be resolved', ValidationReason::NoMatch);
        }

        if (! $this->flag($attributes, 'DPV')) {
            // Resolved without a postal delivery point (e.g. matched against
            // map data only) — not a deliverability determination, so leave
            // it open to the fallback chain.
            return $this->markInconclusive($shipment, 'Address found but not confirmed as a delivery point', ValidationReason::NotDeliveryPoint);
        }

        $shipment->checked = true;

        [$shipment->deliverability, $shipment->validation_message] = match (true) {
            // FedEx confirms a delivery point for a ZIP assigned to one
            // organization even when it can't place the address within it,
            // which USPS reads as a default address needing more information.
            ($attributes['AddressPrecision'] ?? null) === 'UNIQUE_ZIP' => [
                Deliverability::Partial, 'ZIP code belongs to a single organization, address within it not confirmed',
            ],
            $this->flag($attributes, 'InvalidSuiteNumber') => [
                Deliverability::Partial, 'Primary address confirmed, secondary number not confirmed',
            ],
            $this->flag($attributes, 'SuiteRequiredButMissing') => [
                Deliverability::Partial, 'Primary address confirmed, secondary number missing',
            ],
            default => [Deliverability::Yes, 'Address confirmed deliverable'],
        };

        $this->applyValidatedAddress($shipment, $resolved);
        $shipment->save();

        return AddressValidationResult::settled();
    }

    /**
     * Outside the US FedEx matches reference data, with no delivery point.
     * `Matched` alone is not enough: FedEx matches the street and echoes a
     * house number that doesn't exist, or swaps in a different one that
     * does. A match settles the address only when `StreetAddress` says the
     * house matched and the house number came back unchanged — and then as
     * Verified, never Yes.
     *
     * @param  array<string, mixed>  $resolved
     */
    protected function processInternational(Shipment $shipment, array $resolved): AddressValidationResult
    {
        $attributes = $resolved['attributes'] ?? [];
        $returnedStreet = $resolved['streetLinesToken'][0] ?? '';

        if (! $this->flag($attributes, 'Matched')) {
            return $this->markInconclusive($shipment, 'Address could not be matched', ValidationReason::NoMatch);
        }

        if (! $this->flag($attributes, 'StreetAddress')) {
            return $this->markInconclusive($shipment, 'Street found, house number not confirmed', ValidationReason::StreetOnly);
        }

        if (! $this->sameHouseNumber((string) $shipment->address1, (string) $returnedStreet, $shipment->country ?? '')) {
            return $this->markInconclusive($shipment, "Matched with a different house number: {$returnedStreet}", ValidationReason::HouseNumberChanged);
        }

        $shipment->checked = true;
        $shipment->deliverability = Deliverability::Verified;
        $shipment->validation_message = 'Address matched reference data';

        // FedEx's later street lines are a locality or postcode, and it drops
        // the unit line sent with the address, so address2 and the state —
        // which FedEx gives in its own codes — fall back to what was sent.
        $shipment->validated_address1 = $returnedStreet;
        $shipment->validated_address2 = null;
        $shipment->validated_city = $resolved['city'] ?? null;
        $shipment->validated_state_or_province = null;
        $shipment->validated_postal_code = $this->postalCode($resolved);
        $shipment->validated_carrier_route = null;
        $shipment->validated_residential = null;
        $shipment->save();

        return AddressValidationResult::settled();
    }

    /**
     * Whether the returned street line carries the numbers sent, in order.
     * Every number counts, since a street name can hold one (`12 de Octubre`).
     * A two-part Czech or Slovak number such as `482/22` is the same when
     * FedEx returns its second part, as it did for all 19 Slovak captures;
     * no capture showed it keeping the first, so that reads as a change.
     * Elsewhere the second part may be the flat (Polish `12/3`), so a slash
     * number must come back whole.
     */
    protected function sameHouseNumber(string $sent, string $returned, string $country): bool
    {
        $sentNumbers = $this->houseNumbers($sent);
        $returnedNumbers = $this->houseNumbers($returned);

        if ($sentNumbers === [] || count($sentNumbers) !== count($returnedNumbers)) {
            return false;
        }

        foreach ($sentNumbers as $i => $number) {
            $accepted = [$number];

            if (str_contains($number, '/') && AddressValidationCountries::twoPartHouseNumbers($country)) {
                $accepted[] = explode('/', $number, 2)[1];
            }

            if (! in_array($returnedNumbers[$i], $accepted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The numbers in a street line, uppercased, with any suffix (`48A`,
     * `12 BIS`) or second part (`482/22`) attached and leading zeros dropped
     * (Chile's `0605` comes back `605`). A suffix is one letter or one of the
     * French and Italian repetition words, so `12 BIS` coming back as `12`
     * or `12B` reads as a change.
     *
     * @return list<string>
     */
    protected function houseNumbers(string $street): array
    {
        $part = '\d+(?:\s*(?:BIS|TER|QUATER|QUINQUIES|\p{L})(?!\p{L}))?';

        preg_match_all("~{$part}(?:\s*/\s*{$part})?~u", mb_strtoupper($street), $matches);

        return array_map(
            fn (string $number): string => (string) preg_replace(['~\s+~u', '~(?<!\d)0+(?=\d)~'], '', $number),
            $matches[0],
        );
    }

    /**
     * FedEx has no delivery-point determination for this address — leave the
     * shipment unchecked so the fallback chain can attempt it.
     */
    protected function markInconclusive(Shipment $shipment, string $message, ValidationReason $reason): AddressValidationResult
    {
        $shipment->deliverability = Deliverability::No;
        $shipment->validation_message = $message;
        $shipment->save();

        return AddressValidationResult::inconclusive($reason);
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    protected function applyValidatedAddress(Shipment $shipment, array $resolved): void
    {
        $streetLines = $resolved['streetLinesToken'] ?? [];
        $shipment->validated_address1 = $streetLines[0] ?? null;
        $shipment->validated_address2 = $streetLines[1] ?? null;
        $shipment->validated_city = $resolved['city'] ?? null;
        $shipment->validated_state_or_province = $resolved['stateOrProvinceCode'] ?? null;
        $shipment->validated_postal_code = $this->postalCode($resolved);
        $shipment->validated_carrier_route = null;
        $shipment->validated_residential = match ($resolved['classification'] ?? null) {
            'RESIDENTIAL' => true,
            'BUSINESS' => false,
            // MIXED is a multi-tenant building with both kinds of unit;
            // UNKNOWN is FedEx declining to say.
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    protected function postalCode(array $resolved): ?string
    {
        $base = $resolved['parsedPostalCode']['base'] ?? null;

        if (! filled($base)) {
            return $resolved['postalCode'] ?? null;
        }

        $addOn = $resolved['parsedPostalCode']['addOn'] ?? null;

        return filled($addOn) ? "{$base}-{$addOn}" : $base;
    }

    /**
     * FedEx documents these attributes as booleans but has returned them as
     * "true"/"false" strings, so accept either.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function flag(array $attributes, string $key): bool
    {
        return filter_var($attributes[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
