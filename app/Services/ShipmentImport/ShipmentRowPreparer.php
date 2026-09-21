<?php

namespace App\Services\ShipmentImport;

use App\Enums\ShipmentStatus;
use App\Models\Client;
use App\Models\DataSource;
use App\Services\AddressReferenceService;
use App\Services\PhoneParserService;

class ShipmentRowPreparer
{
    public function __construct(
        private readonly AddressReferenceService $addressReference,
        private readonly ImportReferenceResolver $references,
    ) {}

    public function prepare(array $data, DataSource $importSource, ?Client $clientOverride = null): PreparedShipmentRow
    {
        $data = $this->addressReference->normalizeAddressFields($data);

        ['errors' => $validationErrors, 'warnings' => $validationWarnings] = $this->validateShipmentData($data);

        if ($validationErrors !== []) {
            return new PreparedShipmentRow(errors: $validationErrors, warnings: $validationWarnings);
        }

        $phoneExtension = $data['phone_extension'] ?? null;
        $phoneE164 = null;

        if (! empty($data['phone'])) {
            $phoneResult = PhoneParserService::parse($data['phone'], $data['country'] ?? 'US');

            if ($phoneResult->isValid()) {
                $phoneE164 = $phoneResult->e164;
                if ($phoneExtension === null && $phoneResult->extension !== null) {
                    $phoneExtension = $phoneResult->extension;
                }
            } else {
                $validationWarnings[] = "Invalid phone number could not be normalized: {$data['phone']}";
            }
        }

        foreach ($validationWarnings as $warning) {
            if (str_contains($warning, 'email')) {
                $data['email'] = null;
            }
        }

        $client = $clientOverride ?? $importSource->client;
        $status = ShipmentStatus::tryFrom((string) ($data['_import_status'] ?? '')) ?? ShipmentStatus::Open;
        $shippingMethodId = $this->references->shippingMethodIdFor($data, $client);
        $preserveExistingFields = $data['_preserve_existing_fields'] ?? [];

        if (! array_key_exists('residential', $data) || $data['residential'] === null) {
            $preserveExistingFields[] = 'residential';
        }

        // Sources that read a per-order shipping reference (e.g. Amazon's
        // fulfillmentServiceLevel) can supply the source's configured default as
        // a fallback for references that have no alias yet. The unmapped
        // reference is still recorded so it surfaces for mapping.
        if ($shippingMethodId === null && filled($data['_shipping_method_fallback'] ?? null)) {
            $shippingMethodId = $this->references->shippingMethodIdFor(
                ['shipping_method_id' => $data['_shipping_method_fallback']],
                $client,
            );
        }

        return new PreparedShipmentRow(
            attributes: [
                'client_id' => $client?->id ?? Client::where('is_default', true)->value('id'),
                'location_id' => $data['location_id'] ?? null,
                'data_source_id' => $importSource->id,
                'data_source_location_id' => $data['data_source_location_id'] ?? null,
                'source_record_id' => $data['source_record_id'] ?? $data['shipment_reference'],
                'shipment_reference' => $data['shipment_reference'],
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'company' => $data['company'] ?? null,
                'address1' => $data['address1'] ?? null,
                'address2' => $data['address2'] ?? null,
                'city' => $data['city'] ?? null,
                'state_or_province' => $data['state_or_province'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'country' => $data['country'] ?? 'US',
                'phone' => $data['phone'] ?? null,
                'phone_e164' => $phoneE164,
                'phone_extension' => $phoneExtension,
                'email' => $data['email'] ?? null,
                'value' => $data['value'] ?? null,
                'residential' => $data['residential'] ?? null,
                'validation_message' => $validationWarnings !== [] ? implode('; ', $validationWarnings) : null,
                'shipping_method_reference' => $data['shipping_method_id'] ?? null,
                'shipping_method_id' => $shippingMethodId,
                'channel_reference' => $data['channel_id'] ?? null,
                'channel_id' => $this->references->channelIdFor($data, $client),
                'deliver_by' => $data['deliver_by'] ?? null,
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
                'status' => $status->value,
                '_preserve_existing_fields' => array_values(array_unique($preserveExistingFields)),
            ],
            warnings: $validationWarnings,
        );
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    private function validateShipmentData(array $data): array
    {
        $errors = [];
        $warnings = [];

        if (empty($data['shipment_reference'])) {
            $errors[] = 'Missing shipment reference';
        }

        if (empty($data['address1'])) {
            $errors[] = 'Missing address line 1';
        }

        if (empty($data['city'])) {
            $errors[] = 'Missing city';
        }

        $country = $this->addressReference->normalizeCountry($data['country'] ?? 'US') ?? ($data['country'] ?? 'US');

        if (empty($data['postal_code'])) {
            if ($country === 'US') {
                $warnings[] = 'Missing postal code';
            }
        } elseif ($country === 'US') {
            $zip = preg_replace('/[^0-9]/', '', $data['postal_code']);
            if (strlen($zip) !== 5 && strlen($zip) !== 9) {
                $warnings[] = 'Invalid US postal code format';
            }
        }

        if (empty($data['state_or_province'])) {
            if ($this->addressReference->isAdministrativeAreaRequired($country)) {
                $warnings[] = 'Missing state/province';
            }
        } elseif ($country === 'US') {
            $validStates = [
                'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
                'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
                'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
                'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
                'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
                'DC', 'PR', 'VI', 'GU', 'AS', 'MP', 'AA', 'AE', 'AP',
            ];
            $state = strtoupper(trim($data['state_or_province']));
            if (strlen($state) === 2 && ! in_array($state, $validStates, true)) {
                $warnings[] = "Invalid US state code: {$state}";
            }
        } elseif ($this->addressReference->usesAdministrativeArea($country)) {
            $normalizedSubdivision = $this->addressReference->normalizeSubdivision($country, $data['state_or_province']);

            if ($normalizedSubdivision !== null && $normalizedSubdivision !== trim($data['state_or_province'])) {
                $data['state_or_province'] = $normalizedSubdivision;
            }
        }

        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $warnings[] = "Invalid email removed: {$data['email']}";
        }

        if (isset($data['value']) && $data['value'] !== null) {
            if (! is_numeric($data['value']) || $data['value'] < 0) {
                $errors[] = 'Invalid shipment value (must be a positive number)';
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
