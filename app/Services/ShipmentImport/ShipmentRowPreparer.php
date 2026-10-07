<?php

namespace App\Services\ShipmentImport;

use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\ShipmentStatus;
use App\Enums\TaxRegistrationRegime;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\AddressReferenceService;
use App\Services\PhoneParserService;
use App\Support\ExportItn;
use BackedEnum;

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
        $customs = $this->prepareCustomsFields($data);
        $validationErrors = [...$validationErrors, ...$customs['errors']];
        $validationWarnings = [...$validationWarnings, ...$customs['warnings']];

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

        $preserveExistingFields = [...$preserveExistingFields, ...$customs['preserve']];

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
                ...$customs['attributes'],
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
                'status' => $status->value,
                '_preserve_existing_fields' => array_values(array_unique($preserveExistingFields)),
                '_customs_warnings' => $customs['warnings'],
            ],
            warnings: $validationWarnings,
        );
    }

    /**
     * Check and normalize the order's customs terms (ADR-0008).
     *
     * A field the source does not supply at all (its key is absent, as with
     * Shopify and Amazon today, or a Database query that does not select the
     * column) leaves the stored value alone. A field it supplies as null
     * clears it: an ERP that withdraws DDP must stop shipping DDP, and a
     * marketplace registration that is gone must stop being declared. The ITN
     * is the exception: a manager records it after filing, so a null from the
     * source keeps it.
     *
     * Duties terms and the seller registration decide what is billed and
     * declared, so a bad value rejects the row. A bad recipient tax ID or ITN
     * imports the order without it and records a warning, which never repeats
     * the ID; the label check stops the label later.
     *
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, ?string>, preserve: list<string>, errors: list<string>, warnings: list<string>}
     */
    private function prepareCustomsFields(array $data): array
    {
        $supplied = fn (string ...$fields): bool => array_any($fields, fn (string $field): bool => array_key_exists($field, $data));
        $input = fn (string $field): ?string => filled($data[$field] ?? null) ? trim((string) $data[$field]) : null;
        $attributes = array_fill_keys(Shipment::CUSTOMS_FIELDS, null);
        $preserve = [];
        $errors = [];
        $warnings = [];

        if (! $supplied('duties_terms')) {
            $preserve[] = 'duties_terms';
        } elseif (($terms = $input('duties_terms')) !== null) {
            $attributes['duties_terms'] = DutiesTerms::fromInput($terms)?->value;

            if ($attributes['duties_terms'] === null) {
                $errors[] = "Invalid duties terms '{$terms}' (expected ddp, ddu or dap)";
            }
        }

        if (! $supplied('seller_tax_regime', 'seller_tax_number')) {
            array_push($preserve, 'seller_tax_regime', 'seller_tax_number');
        } else {
            $regimeInput = $input('seller_tax_regime');
            $number = $input('seller_tax_number');

            if (($regimeInput === null) !== ($number === null)) {
                $errors[] = 'Seller tax regime and seller tax number must be given together';
            } elseif ($regimeInput !== null && $number !== null) {
                $regime = TaxRegistrationRegime::fromInput($regimeInput);
                $error = $regime === null
                    ? "Invalid seller tax regime '{$regimeInput}' (expected one of ".self::caseList(TaxRegistrationRegime::cases()).')'
                    : $regime->numberError($number);

                if ($regime !== null && $error === null) {
                    $attributes['seller_tax_regime'] = $regime->value;
                    $attributes['seller_tax_number'] = $regime->normalizeNumber($number);
                } else {
                    $errors[] = $error;
                }
            }
        }

        if (! $supplied('recipient_tax_id_type', 'recipient_tax_id')) {
            array_push($preserve, 'recipient_tax_id_type', 'recipient_tax_id');
        } else {
            $typeInput = $input('recipient_tax_id_type');
            $taxId = $input('recipient_tax_id');
            $type = RecipientTaxIdType::fromInput($typeInput);

            $problem = match (true) {
                $typeInput === null && $taxId === null => null,
                $typeInput === null || $taxId === null => 'recipient tax ID type and recipient tax ID must be given together',
                $type === null => "'{$typeInput}' is not a recipient tax ID type (expected one of ".self::caseList(RecipientTaxIdType::cases()).')',
                default => $type->error($taxId),
            };

            if ($problem !== null) {
                $warnings[] = 'Recipient tax ID not imported: '.$problem;
            } elseif ($type !== null && $taxId !== null) {
                $attributes['recipient_tax_id_type'] = $type->value;
                $attributes['recipient_tax_id'] = $type->normalize($taxId);
            }
        }

        if (($itn = $input('export_itn')) === null) {
            $preserve[] = 'export_itn';
        } elseif (($error = ExportItn::error($itn)) !== null) {
            $warnings[] = 'Export ITN not imported: '.$error;
        } else {
            $attributes['export_itn'] = ExportItn::normalize($itn);
        }

        return ['attributes' => $attributes, 'preserve' => $preserve, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     */
    private static function caseList(array $cases): string
    {
        return implode(', ', array_map(fn (BackedEnum $case): string => (string) $case->value, $cases));
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
