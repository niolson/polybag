<?php

namespace App\DataTransferObjects\PostageSources;

use App\DataTransferObjects\Shipping\RateRequest;
use App\Enums\PostageSource;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierService;
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
     * @param  string|null  $quoteFingerprint  {@see RateRequest::fingerprint()} of the request this price answers. The offer store recomputes it from the package at redemption and refuses on a mismatch: an edit to what the carrier was asked to price makes this a price for a different parcel.
     * @param  string|null  $carrierAccountFingerprint  {@see CarrierAccount::fingerprint()} of the account that quoted a direct rate — its billing identity, not its secrets — so the purchase can refuse when the same account row would now bill someone else. Null for a rate resold through a channel.
     * @param  int|null  $carrierId  The {@see Carrier} expected to carry the parcel, resolved when the offer is issued, so a purchase restores it from here rather than from a carrier-name string or the browser.
     * @param  int|null  $carrierServiceId  The {@see CarrierService} this offer is for, when the source quoted one. Restored at purchase and recorded on the Label.
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
        public ?string $quoteFingerprint = null,
        public ?string $carrierAccountFingerprint = null,
        public ?int $carrierId = null,
        public ?int $carrierServiceId = null,
    ) {}
}
