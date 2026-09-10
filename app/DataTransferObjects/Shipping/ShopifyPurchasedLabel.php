<?php

namespace App\DataTransferObjects\Shipping;

/**
 * A label that Shopify Shipping has finished purchasing.
 *
 * Shopify chooses the file format from the shop's own admin setting — the API
 * offers no way to request one — so `labelFormat` reports what actually came
 * back rather than what was asked for.
 */
readonly class ShopifyPurchasedLabel
{
    public function __construct(
        public string $shippingLabelId,
        public ?string $trackingNumber,
        public ?string $trackingCompany,
        public ?string $labelData,
        public string $labelFormat,
        // An international purchase returns the customs form as a second
        // document — observed as a three-page Letter PDF commercial invoice,
        // which is why it prints to the report printer and not to the 4x6
        // thermal path the label takes.
        public ?string $customsFormData = null,
        // Kept beside the downloaded document, not instead of it: the operator
        // can still print from the Shopify admin when the download failed, which
        // is deliberately not treated as a failed purchase.
        public ?string $customsFormUrl = null,
        // Kept so a label that was bought but could not be downloaded is still
        // reachable — by a retry, or by hand from the Shopify admin.
        public ?string $labelDocumentUrl = null,
    ) {}
}
