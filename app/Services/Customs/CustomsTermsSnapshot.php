<?php

namespace App\Services\Customs;

use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\CustomsTermsOrigin;

/**
 * What a purchased Label declared, as `package_labels.customs_terms` records
 * it (ADR-0008 decision 6): the duties term and where it came from, the seller
 * registration and where it came from, the ITN, and the version of
 * `duties-support.json` that judged the rate.
 *
 * Built by the shipping workflow, not an adapter, so every carrier gets the
 * same record. It never holds the recipient's tax ID: only its type, and that
 * one was sent, because the number is personal data the PII purge would
 * otherwise have to clear from every Label as well.
 */
class CustomsTermsSnapshot
{
    public function __construct(private readonly DutiesSupportTable $dutiesSupport) {}

    /**
     * The snapshot for a request, or null when the label crosses no customs
     * border and so declares nothing.
     *
     * @return array{duties_terms: string|null, duties_terms_source: string|null, registration: array{regime: string, number: string, source: string}|null, recipient_tax_id: array{type: string}|null, export_itn: string|null, duties_support_version: string}|null
     */
    public function forRequest(ShipRequest $request): ?array
    {
        $terms = $request->customsTerms;

        if ($terms === null || ! $terms->applies) {
            return null;
        }

        $sourceDecided = $terms->dutiesTermsOrigin === CustomsTermsOrigin::SourceDecided;

        return [
            'duties_terms' => $terms->dutiesTerms?->value,
            'duties_terms_source' => $terms->dutiesTermsOrigin?->value,
            'registration' => $terms->registration === null ? null : [
                'regime' => $terms->registration->regime->value,
                'number' => $terms->registration->number,
                'source' => $terms->registration->origin->value,
            ],
            'recipient_tax_id' => $sourceDecided || $request->recipientTaxId === null
                ? null
                : ['type' => $request->recipientTaxId->type->value],
            'export_itn' => $sourceDecided || blank($request->exportItn) ? null : $request->exportItn,
            'duties_support_version' => $this->dutiesSupport->version(),
        ];
    }
}
