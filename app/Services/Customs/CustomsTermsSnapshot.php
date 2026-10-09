<?php

namespace App\Services\Customs;

use App\Contracts\SendsCustomsTerms;
use App\DataTransferObjects\Customs\DeclaredCustomsTerms;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\CustomsTermsOrigin;

/**
 * What a purchased Label declared, as `package_labels.customs_terms` records
 * it (ADR-0008 decision 6): the duties term and where it came from, the seller
 * registration and where it came from, the ITN, and the version of
 * `duties-support.json` that judged the rate.
 *
 * Built by the shipping workflow, not an adapter, so every carrier gets the
 * same record; but the facts come from the adapter
 * ({@see SendsCustomsTerms::declaredCustomsTerms()}), which says what it put
 * on the wire. It never holds the recipient's tax ID: only its type, and that
 * one was sent, because the number is personal data the PII purge would
 * otherwise have to clear from every Label as well.
 */
class CustomsTermsSnapshot
{
    public function __construct(private readonly DutiesSupportTable $dutiesSupport) {}

    /**
     * The snapshot for a request bought through $seller, or null when nothing
     * was declared and the label crosses no customs border.
     *
     * @return array{duties_terms: string|null, duties_terms_source: string|null, registration: array{regime: string, number: string, source: string}|null, recipient_tax_id: array{type: string}|null, export_itn: string|null, duties_support_version: string}|null
     */
    public function forRequest(ShipRequest $request, ?object $seller): ?array
    {
        $terms = $request->customsTerms;
        $sourceDecided = $terms?->dutiesTermsOrigin === CustomsTermsOrigin::SourceDecided;

        $declared = $seller instanceof SendsCustomsTerms && ! $sourceDecided
            ? $seller->declaredCustomsTerms($request)
            : DeclaredCustomsTerms::none();

        if (($terms === null || ! $terms->applies) && $declared->isEmpty()) {
            return null;
        }

        return [
            'duties_terms' => $declared->dutiesTerms?->value,
            'duties_terms_source' => $declared->dutiesTerms === null && ! $sourceDecided ? null : $terms?->dutiesTermsOrigin?->value,
            'registration' => $declared->registration === null ? null : [
                'regime' => $declared->registration->regime->value,
                'number' => $declared->registration->number,
                'source' => $declared->registration->origin->value,
            ],
            'recipient_tax_id' => $declared->recipientTaxIdType === null ? null : ['type' => $declared->recipientTaxIdType->value],
            'export_itn' => $declared->exportItn,
            'duties_support_version' => $this->dutiesSupport->version(),
        ];
    }
}
