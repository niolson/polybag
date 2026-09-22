<?php

namespace App\DataTransferObjects\PostageSources;

use App\Enums\OffAmazonShippingStatus;
use App\Enums\PostageSource;
use App\Models\CarrierAccount;
use App\Models\DataSource;

/**
 * One postage source instance that could sell this package a label.
 *
 * ADR-0002 decision 4 requires an offer to name the source instance it came
 * from, because "USPS" no longer identifies a seller: it can reach us through
 * our own account or as an offer resold by Amazon, while a storefront may not
 * say which carrier it picked until afterwards. This is that identity, resolved
 * before any of them is asked.
 *
 * `carrier` is a descriptive fact and never the identity — it is null for a
 * channel source precisely because a blind-purchase offer has no carrier until
 * the label comes back (ADR-0003 decisions 5 and 6).
 *
 * An off-Amazon candidate is an Amazon connection selling Amazon Shipping for
 * an order from another channel (`channelType: EXTERNAL`). It is bought through
 * a connection, so its kind is still `PostageDataSource`, but it is not channel
 * postage: it is bound to no order, and its price and service are quoted.
 */
readonly class PostageSourceCandidate
{
    public function __construct(
        public PostageSource $kind,
        public string $name,
        public ?string $carrier = null,
        public ?int $carrierAccountId = null,
        public ?int $postageDataSourceId = null,
        public bool $offAmazon = false,
        public ?OffAmazonShippingStatus $offAmazonShippingStatus = null,
    ) {}

    public static function fromCarrierAccount(CarrierAccount $account, string $carrier): self
    {
        return new self(
            kind: PostageSource::CarrierAccount,
            name: (string) $account->name,
            carrier: $carrier,
            carrierAccountId: $account->id,
        );
    }

    public static function fromDataSource(DataSource $source): self
    {
        return new self(
            kind: PostageSource::PostageDataSource,
            name: (string) $source->name,
            postageDataSourceId: $source->id,
        );
    }

    /**
     * An Amazon connection selling Amazon Shipping for an order that did not
     * come from Amazon, chosen by scope rather than by the order's origin.
     *
     * It carries the connection's check result so an account Amazon refused
     * stays visible instead of silently dropping out. No carrier is named yet;
     * `05` decides how its rates are named.
     */
    public static function forOffAmazonShipping(DataSource $source): self
    {
        return new self(
            kind: PostageSource::PostageDataSource,
            name: (string) $source->name,
            postageDataSourceId: $source->id,
            offAmazon: true,
            offAmazonShippingStatus: $source->off_amazon_shipping_status,
        );
    }

    /**
     * Whether this is channel postage — bought on somebody else's account, with
     * the carrier unknown until the purchase answers. Off-Amazon Amazon
     * Shipping is bought through a connection too, but is neither.
     */
    public function isChannel(): bool
    {
        return $this->kind === PostageSource::PostageDataSource && ! $this->offAmazon;
    }
}
