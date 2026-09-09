<?php

namespace App\DataTransferObjects\PackageShipping;

use App\DataTransferObjects\PrintRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\OfferRejection;
use App\Exceptions\ShopifyDeclaredWeightException;
use App\Models\Package;

readonly class PackageShippingResult
{
    public function __construct(
        public bool $success,
        public ?string $title = null,
        public ?string $message = null,
        public ?ShipResponse $response = null,
        public ?RateResponse $selectedRate = null,
        public ?PrintRequest $printRequest = null,
        public bool $requiresCustomsWeightOverride = false,
        public bool $requiresDeclaredWeightOverride = false,
        public bool $leavePackageIntact = false,
        public bool $requiresRequote = false,
    ) {}

    /**
     * @param  RateResponse|null  $rate  Null for a blind purchase, which had no rate behind it
     */
    public static function shipped(ShipResponse $response, ?RateResponse $rate, ?Package $package = null): self
    {
        return new self(
            success: true,
            title: 'Package Shipped',
            message: "Tracking: {$response->trackingNumber}",
            response: $response,
            selectedRate: $rate,
            printRequest: $response->labelData ? PrintRequest::fromShipResponse($response, $package) : null,
        );
    }

    public static function failed(string $title, string $message): self
    {
        return new self(success: false, title: $title, message: $message);
    }

    public static function customsWeightOverrideRequired(): self
    {
        return new self(
            success: false,
            title: 'Customs Weight Mismatch',
            message: 'Customs item weights exceed the package weight. Please review and confirm before shipping.',
            requiresCustomsWeightOverride: true,
        );
    }

    /**
     * The seller declares more weight for the goods than the box was weighed
     * at, so the purchase was withheld rather than attempted.
     *
     * Carries the message from {@see ShopifyDeclaredWeightException},
     * which is the only place both numbers are known. Leaves the package intact
     * for the same reason the offer failures do: nothing was bought, and the
     * remedy — a product weight in the seller's catalogue — is somewhere else
     * entirely. Dissolving the packed box while somebody goes to fix it would
     * be the worst possible response.
     */
    public static function declaredWeightOverrideRequired(string $message): self
    {
        return new self(
            success: false,
            title: 'Declared Weight Exceeds Package Weight',
            message: $message,
            requiresDeclaredWeightOverride: true,
            leavePackageIntact: true,
        );
    }

    /**
     * The offer behind the selected rate could not be spent.
     *
     * Fails closed and leaves the package alone: expiry and double-spend are
     * both recoverable by quoting again, and a package deleted out from under
     * an operator who only needs fresh rates is a worse outcome than the stale
     * rate they clicked.
     *
     * @param  bool  $requiresRequote  Whether a fresh quote is the whole remedy, so the caller can produce one instead of asking for it. Never set where a label may already exist — see {@see OfferRejection::requiresRequote()}.
     */
    public static function offerUnavailable(string $title, string $message, bool $requiresRequote = false): self
    {
        return new self(
            success: false,
            title: $title,
            message: $message,
            leavePackageIntact: true,
            requiresRequote: $requiresRequote,
        );
    }

    public static function stateConflict(string $message): self
    {
        return new self(success: false, title: 'Package State Changed', message: $message, leavePackageIntact: true);
    }

    public function summaryMessage(): string
    {
        if (! $this->success || ! $this->response) {
            return $this->message ?? 'Unknown error';
        }

        if (! $this->selectedRate) {
            return "Tracking: {$this->response->trackingNumber}";
        }

        return "Tracking: {$this->response->trackingNumber} via {$this->response->carrier}"
            ." ({$this->selectedRate->serviceName}) - \$".number_format($this->response->cost, 2);
    }
}
