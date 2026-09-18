<?php

namespace App\DataTransferObjects\PostageSources;

use App\Enums\PostageSource;
use Carbon\CarbonInterface;

/**
 * What a postage source is offering, on its way into the offer store.
 *
 * Split in two on purpose. The descriptive half — carrier, service, price — is
 * what a person reads on the Ship page and what reporting groups by. The
 * {@see $purchaseContext} is what actually buys the label, and it is opaque:
 * Amazon's `rateId` is a 76-character string that means nothing outside the
 * request that produced it. Conflating the two is how a carrier name ends up
 * being treated as purchase identity.
 */
readonly class OfferDraft
{
    /**
     * @param  array<string, mixed>  $rateMetadata  Carrier detail from the quote that the purchase needs — FedEx's `serviceType`, USPS's `mailClass`. Descriptive, but authoritative: the adapter reads it, so it comes from here rather than from round-tripped browser state.
     * @param  array<string, mixed>  $purchaseContext  Opaque source tokens — Amazon's `requestToken` and `rateId`. Never rendered, never serialized to the browser.
     * @param  CarbonInterface|null  $expiresAt  When the source's window closes. Null when it publishes none; Amazon returns no expiry field, so its 10-minute window is tracked from request time here. A direct-carrier rate has no window of its own and is given the end of its quoted ship day.
     * @param  int|null  $rateQuoteId  The `rate_quotes` row logged for this rate, when rate shopping logged one, so that marking the selected quote is an update by primary key rather than a match on carrier and service code.
     * @param  CarbonInterface|null  $packageUpdatedAt  The package's `updated_at` when quoted. The offer store refuses the offer once it has moved: an edit to the package makes this a price for a different parcel.
     * @param  CarbonInterface|null  $shipmentUpdatedAt  The shipment's `updated_at` when quoted, for the same reason — the address and the items are the shipment's.
     */
    public function __construct(
        public string $carrier,
        public PostageSource $postageSource,
        public ?int $carrierAccountId = null,
        public ?int $postageDataSourceId = null,
        public ?string $serviceCode = null,
        public ?string $serviceName = null,
        public ?float $price = null,
        public ?string $currency = null,
        public array $rateMetadata = [],
        public array $purchaseContext = [],
        public ?CarbonInterface $expiresAt = null,
        public ?string $marketplace = null,
        public ?int $rateQuoteId = null,
        public ?CarbonInterface $packageUpdatedAt = null,
        public ?CarbonInterface $shipmentUpdatedAt = null,
    ) {}
}
