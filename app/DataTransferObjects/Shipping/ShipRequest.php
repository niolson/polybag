<?php

namespace App\DataTransferObjects\Shipping;

use App\DataTransferObjects\Customs\RecipientTaxId;
use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\Enums\ServiceCapability;
use App\Models\Carrier;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\Customs\CustomsReadiness;
use App\Services\Customs\CustomsTermsResolver;
use App\Services\LabelReferenceResolver;
use App\Services\ShipDateService;
use App\Services\Shipping\DutiesTermsFilter;
use App\Services\SpecialServiceResolver;
use Carbon\CarbonImmutable;

readonly class ShipRequest
{
    /**
     * The smallest per-unit customs weight a carrier will accept. A commodity
     * declared at zero weight is rejected outright, so scaling never takes an
     * item below this even when the package weight cannot afford it.
     */
    private const MIN_CUSTOMS_UNIT_WEIGHT = 0.01;

    /**
     * @param  RateResponse|null  $selectedRate  What was quoted and chosen — null for a blind purchase, which had no price or service to quote
     * @param  BlindPurchaseOffer|null  $blindOffer  The priceless offer being bought instead, when there is one. Exactly one of the two is set.
     * @param  array<int, CustomsItem>  $customsItems
     * @param  array<int, string>  $specialServiceCodes
     * @param  array<string, array<string, mixed>>  $specialServiceConfig  Per-code config values (e.g. declared_value amount)
     * @param  array<int, string>  $references  Identifiers to print on the label, longest-lived first; carriers truncate to their own limits
     * @param  ShippingOffer|null  $offer  The purchase authority behind $selectedRate, when the source issued one. Server-side only and never serialized: it holds the opaque tokens that actually buy the label, which is why an adapter reads them from here rather than from the rate. ADR-0002 decision 4.
     * @param  string|null  $exportItn  The Shipment's export ITN, when EEI was filed; the label declares it instead of an exemption
     * @param  ResolvedCustomsTerms|null  $customsTerms  The duties term and seller registration the label declares, resolved from the Shipment when the request is built, or recorded as source-decided when the seller takes no terms. Null on a hand-built request, which declares nothing.
     * @param  RecipientTaxId|null  $recipientTaxId  The recipient's own tax ID, when the Shipment has one. Personal data: an adapter sends it and never logs it.
     * @param  string|null  $exporterEin  The client's own EIN, which a carrier wants beside an export ITN
     * @param  bool  $overrideDeclaredWeight  The operator has been shown that the seller declares more weight for the goods than the box was weighed at, and has asked for the purchase to be attempted anyway — at the scale weight, unchanged. Nothing is over-declared by it; it exists so a catalog corrected between the refusal and the retry, or a reading of ours that was wrong, is not a dead end.
     */
    public function __construct(
        public AddressData $fromAddress,
        public AddressData $toAddress,
        public PackageData $packageData,
        public ?RateResponse $selectedRate = null,
        public array $customsItems = [],
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public array $specialServiceCodes = [],
        public ?int $locationId = null,
        public ?int $clientId = null,
        public ?CarbonImmutable $shipDate = null,
        public array $specialServiceConfig = [],
        public array $references = [],
        public ?int $packageId = null,
        public ?BlindPurchaseOffer $blindOffer = null,
        public ?ShippingOffer $offer = null,
        public bool $overrideDeclaredWeight = false,
        public ?string $exportItn = null,
        public ?ResolvedCustomsTerms $customsTerms = null,
        public ?RecipientTaxId $recipientTaxId = null,
        public ?string $exporterEin = null,
    ) {}

    public function hasSpecialService(string $code): bool
    {
        return in_array($code, $this->specialServiceCodes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function specialServiceConfig(string $code): array
    {
        return $this->specialServiceConfig[$code] ?? [];
    }

    public function withoutSpecialService(string $code): self
    {
        return $this->withSpecialServiceCodes(
            array_values(array_diff($this->specialServiceCodes, [$code])),
        );
    }

    /**
     * @param  array<int, string>  $codes
     */
    public function withSpecialServiceCodes(array $codes): self
    {
        return new self(
            fromAddress: $this->fromAddress,
            toAddress: $this->toAddress,
            packageData: $this->packageData,
            selectedRate: $this->selectedRate,
            customsItems: $this->customsItems,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            specialServiceCodes: $codes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            references: $this->references,
            packageId: $this->packageId,
            blindOffer: $this->blindOffer,
            offer: $this->offer,
            overrideDeclaredWeight: $this->overrideDeclaredWeight,
            exportItn: $this->exportItn,
            customsTerms: $this->customsTerms,
            recipientTaxId: $this->recipientTaxId,
            exporterEin: $this->exporterEin,
        );
    }

    /**
     * The customs items this request would declare at no value. The rule
     * lives in {@see CustomsReadiness::zeroValueLines()}.
     *
     * @return list<CustomsItem>
     */
    public function zeroValueCustomsItems(): array
    {
        return CustomsReadiness::zeroValueLines($this->fromAddress, $this->toAddress, $this->customsItems, $this->blindOffer !== null);
    }

    /**
     * The customs items an EU consumer label would declare without the
     * merchant or manufacturer product identifier. The rule lives in
     * {@see CustomsReadiness::linesMissingProductIdentifiers()}.
     *
     * @return list<CustomsItem>
     */
    public function customsItemsMissingProductIdentifiers(): array
    {
        return CustomsReadiness::linesMissingProductIdentifiers(
            $this->fromAddress,
            $this->toAddress,
            $this->customsItems,
            $this->blindOffer !== null,
            $this->offer?->amazonChannelType(),
        );
    }

    /**
     * Scale customs item weights proportionally so their total fits inside the
     * package weight.
     *
     * Every carrier declares a commodity as a per-unit weight and multiplies it
     * by the quantity itself — `round($item->weight * $item->quantity, 2)` in
     * both the FedEx and UPS adapters. That is why the scaled unit weight is
     * floored to the hundredth rather than rounded: rounding a unit
     * weight up spends a hundredth of a pound the package weight does not have,
     * and the quantity then multiplies the overspend. A 0.15 lb package holding
     * two items of two units each was rejected by FedEx with "Total commodities
     * weight is greater than the package weight" over exactly that — 0.06 and
     * 0.02 a unit, declared as 0.12 + 0.04 = 0.16.
     *
     * Flooring makes the declaration fit by construction: each unit weight is at
     * or below its proportional share, and a two-decimal weight times an integer
     * quantity is exact, so nothing rounds up again downstream. The cost is that
     * the total lands slightly under the package weight, which no carrier
     * objects to.
     *
     * One case cannot be made to fit: a package weighing less than
     * {@see self::MIN_CUSTOMS_UNIT_WEIGHT} times the total number of units, where
     * even the smallest declarable weight per unit overshoots. The floor still
     * applies, and the carrier rejects the declaration — correctly, because at
     * that point the recorded package weight, not the declaration, is what is
     * wrong.
     */
    public function withScaledCustomsWeights(): self
    {
        if (empty($this->customsItems)) {
            return $this;
        }

        $totalCustomsWeight = collect($this->customsItems)->sum(fn ($item): float => $item->weight * $item->quantity);
        $packageWeight = $this->packageData->weight;

        if ($totalCustomsWeight <= $packageWeight || $totalCustomsWeight == 0) {
            return $this;
        }

        $scale = $packageWeight / $totalCustomsWeight;

        $scaledItems = array_map(
            fn (CustomsItem $item): CustomsItem => $item->withWeight($this->floorToHundredth($item->weight * $scale)),
            $this->customsItems,
        );

        return new self(
            fromAddress: $this->fromAddress,
            toAddress: $this->toAddress,
            packageData: $this->packageData,
            selectedRate: $this->selectedRate,
            customsItems: $scaledItems,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            specialServiceCodes: $this->specialServiceCodes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            references: $this->references,
            packageId: $this->packageId,
            blindOffer: $this->blindOffer,
            offer: $this->offer,
            overrideDeclaredWeight: $this->overrideDeclaredWeight,
            exportItn: $this->exportItn,
            customsTerms: $this->customsTerms,
            recipientTaxId: $this->recipientTaxId,
            exporterEin: $this->exporterEin,
        );
    }

    /**
     * Carry the operator's decision to attempt a purchase the seller's own
     * declared weight would have withheld.
     *
     * A flag rather than a changed weight: the scale reading is what gets sent
     * either way. Only the refusal is waived.
     */
    public function withDeclaredWeightOverride(): self
    {
        return new self(
            fromAddress: $this->fromAddress,
            toAddress: $this->toAddress,
            packageData: $this->packageData,
            selectedRate: $this->selectedRate,
            customsItems: $this->customsItems,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            specialServiceCodes: $this->specialServiceCodes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            references: $this->references,
            packageId: $this->packageId,
            blindOffer: $this->blindOffer,
            offer: $this->offer,
            overrideDeclaredWeight: true,
            exportItn: $this->exportItn,
            customsTerms: $this->customsTerms,
            recipientTaxId: $this->recipientTaxId,
            exporterEin: $this->exporterEin,
        );
    }

    /**
     * Floor a weight to the hundredth of a pound carriers declare in.
     *
     * The inner round() absorbs binary representation noise — 0.06 arriving as
     * 0.059999999 would otherwise floor to 0.05 — without ever letting a value
     * that is genuinely below a hundredth boundary climb over it.
     */
    private function floorToHundredth(float $weight): float
    {
        return max(
            self::MIN_CUSTOMS_UNIT_WEIGHT,
            floor(round($weight * 100, 6)) / 100,
        );
    }

    public static function fromPackageAndRate(
        Package $package,
        RateResponse $rate,
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
        ?ShippingOffer $offer = null,
    ): self {
        $customsItems = [];

        // Load package items with relationships if not already loaded
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem']);

        foreach ($package->packageItems as $packageItem) {
            $customsItems[] = CustomsItem::fromPackageItem($packageItem);
        }

        $package->loadMissing('location');
        $fromAddress = $package->location
            ? AddressData::fromLocation($package->location)
            : AddressData::fromConfig();

        // Dated by the carrier resolved when the offer was issued, never by a
        // name string: a rename between quote and purchase changes nothing. An
        // offer is the server's copy, so it wins over the rate; a rate built
        // server-side with no offer carries the same identity itself.
        $carrierId = $offer !== null ? $offer->carrier_id : $rate->carrierId;
        $shipDate = app(ShipDateService::class)->getShipDate(
            $carrierId !== null ? Carrier::find($carrierId) : null,
            $package->location_id,
        );

        $resolver = app(SpecialServiceResolver::class);
        $specialServiceCodes = $resolver->resolveForPackageAndRate($package, $rate, $offer);

        $toAddress = AddressData::fromShipment($package->shipment);

        return new self(
            fromAddress: $fromAddress,
            toAddress: $toAddress,
            packageData: PackageData::fromPackage($package),
            selectedRate: $rate,
            customsItems: $customsItems,
            labelFormat: $labelFormat,
            labelDpi: $labelDpi,
            specialServiceCodes: $specialServiceCodes,
            locationId: $package->location_id,
            clientId: $package->shipment->client_id,
            shipDate: $shipDate,
            specialServiceConfig: $resolver->configForPackage($package, $specialServiceCodes),
            references: app(LabelReferenceResolver::class)->forPackage($package),
            packageId: $package->id,
            offer: $offer,
            exportItn: $package->shipment->export_itn,
            customsTerms: self::customsTermsFor($package, $fromAddress, $toAddress, app(DutiesTermsFilter::class)->isSourceDecided($rate, $offer)),
            recipientTaxId: RecipientTaxId::fromShipment($package->shipment),
            exporterEin: $package->shipment->client?->exporter_ein,
        );
    }

    /**
     * The same request for a purchase with no rate behind it.
     *
     * Everything a label needs that does not come from a quote is assembled
     * exactly as above — addresses, customs items, references, ship date. What
     * is missing is missing because the source never stated it: no carrier, no
     * service, no price (ADR-0003 decision 6).
     *
     * No special services are sent. A hard-required one excluded this source at
     * offer time, and a default one is dropped rather than requested, because
     * an unconstrained selection cannot promise to apply it — see
     * {@see ServiceCapability::Unguaranteed}.
     *
     * The ship date follows the carrier the connection's *Date Shopify's
     * choice as* names, USPS unless changed — the carrier Shopify is expected
     * to put the parcel on, since it names none before purchase.
     */
    public static function fromPackageAndBlindOffer(
        Package $package,
        BlindPurchaseOffer $offer,
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
    ): self {
        $customsItems = [];

        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem']);

        foreach ($package->packageItems as $packageItem) {
            $customsItems[] = CustomsItem::fromPackageItem($packageItem);
        }

        $package->loadMissing('location');
        $fromAddress = $package->location
            ? AddressData::fromLocation($package->location)
            : AddressData::fromConfig();

        $toAddress = AddressData::fromShipment($package->shipment);

        return new self(
            fromAddress: $fromAddress,
            toAddress: $toAddress,
            packageData: PackageData::fromPackage($package),
            customsItems: $customsItems,
            labelFormat: $labelFormat,
            labelDpi: $labelDpi,
            locationId: $package->location_id,
            clientId: $package->shipment->client_id,
            shipDate: app(ShipDateService::class)->getShipDate(
                self::shipDateCarrierForBlindOffer($offer),
                $package->location_id,
            ),
            references: app(LabelReferenceResolver::class)->forPackage($package),
            packageId: $package->id,
            blindOffer: $offer,
            exportItn: $package->shipment->export_itn,
            customsTerms: self::customsTermsFor($package, $fromAddress, $toAddress, sourceDecided: true),
            recipientTaxId: RecipientTaxId::fromShipment($package->shipment),
            exporterEin: $package->shipment->client?->exporter_ein,
        );
    }

    /**
     * The customs terms a label bought now declares: the Shipment's own, or
     * none of PolyBag's when the seller decides them (ADR-0008 decision 5).
     */
    private static function customsTermsFor(Package $package, AddressData $from, AddressData $to, bool $sourceDecided): ResolvedCustomsTerms
    {
        $terms = app(CustomsTermsResolver::class)->forPackage($package, $from, $to);

        return $sourceDecided ? $terms->asSourceDecided() : $terms;
    }

    /**
     * The carrier a blind purchase is dated by (ADR-0006, guideline 12): the
     * carrier of the service it requests, as a rate is. Only Shopify's own
     * choice has no carrier before it is bought, and is dated by its
     * connection's *Date Shopify's choice as*, or USPS's policy when the offer
     * names no connection.
     */
    private static function shipDateCarrierForBlindOffer(BlindPurchaseOffer $offer): ?Carrier
    {
        if ($offer->carrierId !== null) {
            return Carrier::find($offer->carrierId);
        }

        $dataSource = $offer->postageDataSourceId !== null
            ? DataSource::find($offer->postageDataSourceId)
            : null;

        return $dataSource !== null
            ? $dataSource->shipDateCarrier()
            : Carrier::query()->where('name', Carrier::USPS)->first();
    }
}
