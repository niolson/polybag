<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\ShippingOffer;
use App\Services\RateSelector;

/**
 * Postage that can be bought but not quoted: no price, no service, no carrier
 * until the label comes back.
 *
 * Deliberately not a {@see RateResponse}, and not convertible into one.
 * ADR-0003 decision 6: `ShopifyAdapter::getRates()` used to answer with rates
 * carrying an invented price, an invented service name and `Shopify` as the
 * carrier, none of which are facts. A separate type is what stops those
 * inventions being needed — nothing here has a price field to fill in, so
 * nothing can sort it against a real quote, rank it, or hand it to
 * {@see RateSelector::selectBest()}.
 *
 * What it does carry is enough to buy: the source that sells it and the
 * selection to ask that source for. `serviceCode` is a *preference*, not a
 * service — Shopify may honour `usps:usps_ground_advantage` or ignore it, and
 * the response is the only record of what actually happened.
 */
readonly class BlindPurchaseOffer
{
    /**
     * @param  string  $source  The name the selling source is registered under in `CarrierRegistry`
     * @param  string  $sourceLabel  What a packer is shown as the seller
     * @param  string  $serviceCode  The preference to ask the source for, never a confirmed service
     * @param  string  $selectionLabel  What that preference is called on screen
     * @param  int|null  $postageDataSourceId  The data source whose account the label is bought on
     */
    public function __construct(
        public string $source,
        public string $sourceLabel,
        public string $serviceCode,
        public string $selectionLabel,
        public ?int $postageDataSourceId = null,
    ) {}

    /**
     * The identifier the browser holds and hands back.
     *
     * A blind offer has nothing to spend and no expiry — it is an advertisement
     * that this source will sell a label, not a quote — so unlike a
     * {@see ShippingOffer} this needs no store behind it, and nothing is lost
     * by letting it live in page state. Everything else about the offer is
     * derived again before the purchase: the workflow asks the source what it
     * is currently offering for the package and picks by this identifier, so a
     * tampered offer selects either nothing or its honest counterpart.
     */
    public function id(): string
    {
        return self::identifier($this->source, $this->serviceCode);
    }

    public static function identifier(string $source, string $serviceCode): string
    {
        return $source.':'.$serviceCode;
    }

    /**
     * @return array{source: string, sourceLabel: string, serviceCode: string, selectionLabel: string, postageDataSourceId: ?int, id: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'sourceLabel' => $this->sourceLabel,
            'serviceCode' => $this->serviceCode,
            'selectionLabel' => $this->selectionLabel,
            'postageDataSourceId' => $this->postageDataSourceId,
            'id' => $this->id(),
        ];
    }

    /**
     * @param  array{source: string, sourceLabel: string, serviceCode: string, selectionLabel: string, postageDataSourceId?: ?int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            sourceLabel: $data['sourceLabel'],
            serviceCode: $data['serviceCode'],
            selectionLabel: $data['selectionLabel'],
            postageDataSourceId: $data['postageDataSourceId'] ?? null,
        );
    }
}
