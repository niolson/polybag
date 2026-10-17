<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Manifest;

/**
 * A carrier adapter's answer to a manifest request, or `ManifestService`'s
 * answer to End of Day. Only the service's carries `$manifests`: every form it
 * saved, since one run can make several (one USPS SCAN form per account).
 */
readonly class ManifestResponse
{
    /**
     * @param  list<Manifest>  $manifests
     */
    public function __construct(
        public bool $success,
        public ?string $manifestNumber = null,
        public ?string $image = null,
        public ?string $carrier = null,
        public ?string $errorMessage = null,
        public array $manifests = [],
    ) {}

    /**
     * @param  list<Manifest>  $manifests
     */
    public static function success(
        string $manifestNumber,
        string $carrier,
        ?string $image = null,
        array $manifests = [],
    ): self {
        return new self(
            success: true,
            manifestNumber: $manifestNumber,
            carrier: $carrier,
            image: $image,
            manifests: $manifests,
        );
    }

    public static function failure(string $errorMessage): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
        );
    }
}
