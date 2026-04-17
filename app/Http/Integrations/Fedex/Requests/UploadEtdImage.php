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
 * Upload a letterhead or signature image for ETD (Electronic Trade Documents).
 *
 * Endpoint: POST /documents/v1/lhsimages/upload
 * Response path for the document reference ID: output.documentReferenceId
 */
class UploadEtdImage extends Request implements HasBody
{
    use HasMultipartBody;

    protected Method $method = Method::POST;

    /**
     * @param  string  $imageType  LETTERHEAD or SIGNATURE
     * @param  string  $imageIndex  IMAGE_1 or IMAGE_2
     * @param  string  $filename  Original filename (e.g. letterhead.png)
     * @param  string  $fileContent  Raw binary content of the PNG file
     */
    public function __construct(
        private readonly string $imageType,
        private readonly string $imageIndex,
        private readonly string $filename,
        private readonly string $fileContent,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/documents/v1/lhsimages/upload';
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
            'document' => [
                'referenceId' => $this->filename,
                'name' => $this->filename,
                'contentType' => 'image/png',
                'meta' => [
                    'imageType' => $this->imageType,
                    'imageIndex' => $this->imageIndex,
                ],
            ],
            'rules' => [
                'workflowName' => 'LetterheadSignature',
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
