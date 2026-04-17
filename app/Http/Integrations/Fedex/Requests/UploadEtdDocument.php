<?php

namespace App\Http\Integrations\Fedex\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Data\MultipartValue;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasMultipartBody;

/**
 * Upload a pre-shipment trade document (e.g. commercial invoice PDF) for ETD.
 *
 * Endpoint: POST /documents/v1/etds/upload
 * Response path for the document ID: output.meta.docId
 */
class UploadEtdDocument extends Request implements HasBody
{
    use HasMultipartBody;

    protected Method $method = Method::POST;

    /**
     * @param  string  $filename  Original filename (e.g. commercial-invoice.pdf)
     * @param  string  $contentType  MIME type (e.g. application/pdf)
     * @param  string  $originCountryCode  ISO country code of the shipment origin
     * @param  string  $destCountryCode  ISO country code of the shipment destination
     * @param  string  $fileContent  Raw binary content of the document
     */
    public function __construct(
        private readonly string $filename,
        private readonly string $contentType,
        private readonly string $originCountryCode,
        private readonly string $destCountryCode,
        private readonly string $fileContent,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/documents/v1/etds/upload';
    }

    /**
     * @return array<MultipartValue>
     */
    protected function defaultBody(): array
    {
        $documentJson = json_encode([
            'workflowName' => 'ETDPreshipment',
            'name' => $this->filename,
            'contentType' => $this->contentType,
            'meta' => [
                'shipDocumentType' => 'COMMERCIAL_INVOICE',
                'originCountryCode' => $this->originCountryCode,
                'destinationCountryCode' => $this->destCountryCode,
            ],
        ]);

        return [
            new MultipartValue(name: 'document', value: (string) $documentJson),
            new MultipartValue(name: 'attachment', value: $this->fileContent, filename: $this->filename),
        ];
    }
}
