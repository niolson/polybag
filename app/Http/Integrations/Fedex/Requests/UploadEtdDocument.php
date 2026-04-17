<?php

namespace App\Http\Integrations\Fedex\Requests;

use App\Services\SettingsService;
use Saloon\Contracts\Body\HasBody;
use Saloon\Data\MultipartValue;
use Saloon\Enums\Method;
use Saloon\Http\PendingRequest;
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

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->setUrl($this->resolveDocumentApiBaseUrl().$this->resolveEndpoint());
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

    private function resolveDocumentApiBaseUrl(): string
    {
        $settings = app(SettingsService::class);
        $isSandbox = filled($settings->get('fedex.child_key'))
            ? $settings->get('fedex.child_env') === 'sandbox'
            : (bool) $settings->get('sandbox_mode', false);

        $baseUrl = $isSandbox
            ? config('services.fedex.document_sandbox_url', 'https://documentapitest.prod.fedex.com/sandbox')
            : config('services.fedex.document_base_url', 'https://documentapi.prod.fedex.com');

        return rtrim((string) $baseUrl, '/');
    }
}
