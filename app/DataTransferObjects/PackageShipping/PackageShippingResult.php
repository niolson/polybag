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
    /**
     * The refusal while an earlier purchase on the package is unaccounted for
     * (`postage-source-split/16`).
     */
    public const UNFINISHED_PURCHASE = 'Unfinished Label Purchase';

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
        public bool $requiresAttendedSelection = false,
    ) {}

    /**
     * @param  RateResponse|null  $rate  Null for a blind purchase, which had no rate behind it
     */
    public static function shipped(ShipResponse $response, ?RateResponse $rate, ?Package $package = null): self
    {
        return new self(
            success: true,
            title: 'Label Bought',
            message: "Tracking {$response->trackingNumber}",
            response: $response,
            selectedRate: $rate,
            printRequest: $response->labelData ? PrintRequest::fromShipResponse($response, $package) : null,
        );
    }

    public static function failed(string $title, string $message): self
    {
        return new self(success: false, title: $title, message: $message);
    }

    public static function attendedSelectionRequired(string $title, string $message): self
    {
        return new self(
            success: false,
            title: $title,
            message: $message,
            leavePackageIntact: true,
            requiresAttendedSelection: true,
        );
    }

    /**
     * The shipment has no shipping method, so nothing may be bought for it
     * (`carrier-catalog-reset/16`).
     *
     * Leaves the package intact: it is packed, and the remedy is a method,
     * chosen on the Ship page. Unattended callers send the packer there.
     */
    public static function shippingMethodRequired(): self
    {
        return new self(
            success: false,
            title: 'Shipping Method Required',
            message: 'This shipment has no shipping method. Choose one before buying a label.',
            leavePackageIntact: true,
            requiresAttendedSelection: true,
        );
    }

    /**
     * The package is not ready to buy for: unmeasured, not fully packed, or
     * its shipment not yet picked. Leaves the package intact, since the
     * remedy is finishing it on the Pack page.
     */
    public static function notReady(string $message, string $title = 'Not Ready'): self
    {
        return new self(success: false, title: $title, message: $message, leavePackageIntact: true);
    }

    /**
     * The order was cancelled, so nothing may be bought for it
     * (`order-reconciliation/02`). Leaves the package intact, to be unpacked.
     */
    public static function shipmentCancelled(): self
    {
        return new self(
            success: false,
            title: 'Shipment Cancelled',
            message: 'This shipment is cancelled, so no label can be bought for it. Unpack the package, or ask a manager to reopen the shipment.',
            leavePackageIntact: true,
        );
    }

    /**
     * The customs items weigh more than the box was weighed at. The usual
     * cause is a wrong scale reading, so the message names both weights; the
     * override, which scales the declared item weights down, is the workaround
     * for wrong product weights.
     */
    public static function customsWeightOverrideRequired(float $customsWeight, float $packageWeight): self
    {
        return new self(
            success: false,
            title: 'Customs Weight Mismatch',
            message: sprintf(
                'The customs items add up to %s lb, but this box weighed %s lb. '
                .'If the scale reading is wrong, go back to Pack and reweigh the box. '
                .'Override & Ship scales the customs item weights down to %s lb.',
                number_format($customsWeight, 2),
                number_format($packageWeight, 2),
                number_format($packageWeight, 2),
            ),
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
     * remedy — a product weight in the seller's catalog — is somewhere else
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

    /**
     * The selected rate is not valid in the packaging this Package is in.
     *
     * Nothing was bought, and the remedy is a fresh quote: the shared filter
     * at rate shopping will not offer this rate again for this packaging, so
     * the Ship page re-quotes on the spot rather than asking for it.
     */
    public static function packagingMismatch(string $message): self
    {
        return new self(
            success: false,
            title: 'Packaging Mismatch',
            message: $message,
            leavePackageIntact: true,
            requiresRequote: true,
        );
    }

    /**
     * The purchase would return a customs document beside the label and this
     * workstation has nowhere to print it.
     *
     * Nothing was bought. The package stays as it is and the rate list stands:
     * the remedy is Workstation Settings, not a fresh quote.
     */
    public static function reportPrinterRequired(string $message): self
    {
        return new self(
            success: false,
            title: 'Document Printer Required',
            message: $message,
            leavePackageIntact: true,
        );
    }

    /**
     * The package already has a label, found by a page that was opened before
     * it was bought. One message wherever it is found, since the remedy is
     * the same.
     */
    public static function alreadyShipped(?string $trackingNumber = null): self
    {
        return new self(
            success: false,
            title: 'Already Shipped',
            message: self::alreadyShippedMessage($trackingNumber),
            leavePackageIntact: true,
        );
    }

    public static function alreadyShippedMessage(?string $trackingNumber = null): string
    {
        return 'This package already has a label'
            .(filled($trackingNumber) ? " ({$trackingNumber})" : '')
            .'. Reprint it, or void it from the Packages page.';
    }

    public static function stateConflict(string $message): self
    {
        return new self(success: false, title: 'Package State Changed', message: $message, leavePackageIntact: true);
    }

    /**
     * An error nobody anticipated, before anything was bought. Its detail is
     * in the log; the packer keeps the package and may try again.
     */
    public static function unexpectedError(): self
    {
        return new self(
            success: false,
            title: 'Shipping Error',
            message: 'An unexpected error stopped the purchase. Try again, and tell a manager if it keeps happening.',
            leavePackageIntact: true,
        );
    }

    /**
     * The source sold a label that PolyBag could not record. The package stays
     * as it is. "Try again" is offered only when the next attempt can ask the
     * source for this label; where it cannot, a retry could buy a second one.
     */
    public static function labelNotRecorded(string $seller, ?string $trackingNumber, bool $recoverable): self
    {
        $label = $trackingNumber !== null ? "label {$trackingNumber}" : 'a label';

        return new self(
            success: false,
            title: 'Label Bought but Not Recorded',
            message: $recoverable
                ? "{$seller} sold {$label}, but PolyBag could not save it. Try again: PolyBag asks {$seller} for this label before buying another. "
                    .'If it keeps failing, tell a manager and give them the tracking number.'
                : "{$seller} sold {$label}, but PolyBag could not save it, and cannot ask {$seller} for it again. "
                    ."Do not buy another label for this package: tell a manager, who can void this one with {$seller}.",
            leavePackageIntact: true,
        );
    }

    public function summaryMessage(): string
    {
        if (! $this->success || ! $this->response) {
            return $this->message ?? 'Unknown error';
        }

        if (! $this->selectedRate) {
            return "Tracking {$this->response->trackingNumber}";
        }

        return "Tracking {$this->response->trackingNumber} via {$this->response->carrier}"
            ." {$this->selectedRate->serviceName} — \$".number_format($this->response->cost, 2);
    }
}
