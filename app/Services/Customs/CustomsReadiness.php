<?php

namespace App\Services\Customs;

use App\DataTransferObjects\Customs\CustomsFinding;
use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\AmazonChannelType;
use App\Enums\CustomsFindingSeverity;
use App\Enums\DutiesTerms;
use App\Enums\TaxRegistrationRegime;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ShipmentResource;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Client;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShippingOffer;
use App\Services\SettingsService;
use App\Services\Shipping\DutiesTermsFilter;
use App\Support\FedexRecipientEmail;
use Illuminate\Database\Eloquent\Model;

/**
 * The one customs readiness check (ADR-0008 decision 7): everything that can
 * be known about a label's customs data before a rate is bought, as findings
 * that either block the purchase or warn.
 *
 * Authoritative at purchase, where `EloquentPackageShippingWorkflow` refuses on
 * any block for every path: the Ship page, batch ship and automation. The Ship
 * page runs it before rating as a preview. Rate-dependent rules stay in
 * {@see DutiesTermsFilter}.
 *
 * Source-decided purchases are held only to the rules that read our own
 * declaration. A blind purchase sends none of it, and an Amazon Buy Shipping
 * offer builds its own from Amazon's catalog: a Shipping v2 `Item` has no
 * HS-code, origin, ITN or tax ID field, so no product record or Shipment
 * field of ours could change that label, and blocking on them would refuse
 * what nothing can fix. Only the zero-value and product-identifier guards
 * keep their original gates, which the two static methods below carry over
 * unchanged from `ShipRequest`.
 */
class CustomsReadiness
{
    /** The customs value a classification may total before EEI is required (15 CFR 30.37(a)). */
    public const float ITN_THRESHOLD = 2500.00;

    /** The USPS customs form cuts a description here. */
    public const int USPS_DESCRIPTION_LIMIT = 30;

    public function __construct(
        private readonly CustomsTermsResolver $resolver,
        private readonly CustomsReferenceData $referenceData,
        private readonly DutiesTermsFilter $dutiesTermsFilter,
    ) {}

    /**
     * The customs items a label between these addresses would declare at no
     * value.
     *
     * Only asked where a declaration is actually sent: a label that stays
     * inside one customs zone carries no form — asked of the pair of
     * addresses, since a Canadian location shipping into Canada declares
     * nothing and one shipping into Pennsylvania declares everything — and a
     * blind purchase sends none of ours, the seller building its own from its
     * own catalog, so a zero here would be refused on an array nobody reads.
     * Everywhere else, a line at `$0.00` is either refused by the carrier after
     * the box is closed or printed as an understated declaration, so the answer
     * is checked before the purchase.
     *
     * @param  list<CustomsItem>  $items
     * @return list<CustomsItem>
     */
    public static function zeroValueLines(AddressData $from, AddressData $to, array $items, bool $blindPurchase = false): array
    {
        if ($blindPurchase || $from->sharesCustomsZoneWith($to)) {
            return [];
        }

        return array_values(array_filter(
            $items,
            fn (CustomsItem $item): bool => $item->unitValue <= 0,
        ));
    }

    /**
     * The customs items an EU consumer label would declare without the
     * merchant or manufacturer product identifier.
     *
     * EU customs holds a B2C parcel from 1 November 2026 when any line lacks
     * either, and the carriers' APIs accept the label regardless, so the
     * operator would hear of it from the customer. The standard identifier (a
     * GTIN) is never required: both carriers accept its absence.
     *
     * The same two gates as {@see zeroValueLines()} come first: a blind
     * purchase sends none of our declaration, and a label inside one customs
     * zone sends none at all. An Amazon Buy Shipping offer is exempt as well,
     * though it is quoted rather than blind and does send our item values:
     * a Shipping v2 `Item` has no field for either identifier, so Amazon
     * declares them, if at all, from its own catalog, and no product record
     * of ours could change that label. The offer is what says so, as the
     * channel its adapter quoted it on: Amazon Shipping sold to another
     * channel's order is quoted on the same API and bought on the same kind of
     * connection, but is a direct rate, and is not exempted here. The rule itself covers only goods entering the
     * EU, so an EU origin is exempt too: {@see AddressData::sharesCustomsZoneWith()}
     * compares countries outside the US and would call Germany to France a
     * border, refusing every intra-EU consumer label. A consignee with a
     * company name is a business, the only signal the app has
     * (`eu-product-identifiers` PRD), and is never refused here.
     *
     * @param  list<CustomsItem>  $items
     * @return list<CustomsItem>
     */
    public static function linesMissingProductIdentifiers(AddressData $from, AddressData $to, array $items, bool $blindPurchase = false, ?AmazonChannelType $offerChannel = null): array
    {
        if ($blindPurchase || $from->sharesCustomsZoneWith($to)) {
            return [];
        }

        if ($offerChannel === AmazonChannelType::Amazon) {
            return [];
        }

        if ($from->isInEuropeanUnion() || ! $to->isInEuropeanUnion() || filled($to->company)) {
            return [];
        }

        return array_values(array_filter(
            $items,
            fn (CustomsItem $item): bool => $item->merchantProductId === null || $item->manufacturerProductId === null,
        ));
    }

    /**
     * The findings for a Package's label.
     *
     * @param  ShippingOffer|null  $offer  The Offer being bought, when there is one
     * @param  RateResponse|null  $rate  The rate being bought; its carrier decides the carrier-specific warnings and whether the source sets the terms
     * @param  BlindPurchaseOffer|null  $blindOffer  The priceless offer being bought instead
     * @param  list<CustomsItem>|null  $lines  The customs lines the label declares, when the caller already holds them; in the order of the Package's items
     * @return list<CustomsFinding>
     */
    public function check(
        Package $package,
        ?ShippingOffer $offer = null,
        ?RateResponse $rate = null,
        ?BlindPurchaseOffer $blindOffer = null,
        ?AddressData $origin = null,
        ?AddressData $destination = null,
        ?array $lines = null,
    ): array {
        $package->loadMissing(['location', 'shipment.client.taxRegistrations', 'packageItems.product', 'packageItems.shipmentItem']);
        $shipment = $package->shipment;

        $origin ??= $package->location !== null
            ? AddressData::fromLocation($package->location)
            : AddressData::fromConfig();
        $destination ??= AddressData::fromShipment($shipment);
        $lines ??= $package->packageItems->map(CustomsItem::fromPackageItem(...))->values()->all();

        /** @var array<int, Product|null> $products */
        $products = $package->packageItems->values()->map(fn ($item): ?Product => $item->product)->all();

        $channel = $offer?->amazonChannelType();
        $terms = $this->resolver->resolve($shipment, $origin, $destination, $lines);

        $sourceDecided = $blindOffer !== null
            || ($rate !== null && $this->dutiesTermsFilter->isSourceDecided($rate, $offer))
            || $channel === AmazonChannelType::Amazon;

        if ($sourceDecided) {
            $terms = $terms->asSourceDecided();
        }

        return $this->evaluate(
            $shipment,
            $lines,
            $products,
            $terms,
            $origin,
            $destination,
            blindPurchase: $blindOffer !== null,
            offerChannel: $channel,
            carrier: $rate?->carrier,
            declaresNothingOfOurs: $blindOffer !== null || $channel === AmazonChannelType::Amazon,
        );
    }

    /**
     * Run every rule over data the caller already holds.
     *
     * @param  list<CustomsItem>  $lines
     * @param  array<int, Product|null>  $products  The product behind each line, by the line's position
     * @param  string|null  $carrier  The carrier of the rate being bought, or null before one is chosen, when carrier-specific warnings are shown for every carrier they might concern
     * @param  bool  $declaresNothingOfOurs  The source builds its own declaration (blind purchase, Amazon Buy Shipping), so only the guards with their own gates apply
     * @return list<CustomsFinding>
     */
    public function evaluate(
        Shipment $shipment,
        array $lines,
        array $products,
        ResolvedCustomsTerms $terms,
        AddressData $origin,
        AddressData $destination,
        bool $blindPurchase = false,
        ?AmazonChannelType $offerChannel = null,
        ?string $carrier = null,
        bool $declaresNothingOfOurs = false,
    ): array {
        $findings = [];

        // The two existing guards, first and with their original titles, so a
        // purchase refused by one of them says what it always said.
        if (($zeroValued = self::zeroValueLines($origin, $destination, $lines, $blindPurchase)) !== []) {
            $findings[] = $this->zeroValueFinding($zeroValued, $lines, $products);
        }

        if (($unidentified = self::linesMissingProductIdentifiers($origin, $destination, $lines, $blindPurchase, $offerChannel)) !== []) {
            $findings[] = $this->productIdentifierFinding($unidentified, $lines, $products);
        }

        if ($declaresNothingOfOurs || ! $terms->applies) {
            return $findings;
        }

        array_push(
            $findings,
            ...$this->termsFindings($terms, $destination),
            ...$this->lineFindings($lines, $products, $destination, $origin),
            ...$this->exportFilingFindings($shipment, $lines, $products, $origin, $destination),
            ...$this->recipientTaxIdFindings($shipment, $destination),
            ...$this->registrationFindings($terms, $destination),
            ...$this->carrierFindings($shipment, $lines, $terms, $destination, $carrier),
        );

        return $findings;
    }

    /**
     * @param  list<CustomsFinding>  $findings
     * @return list<CustomsFinding>
     */
    public static function blocks(array $findings): array
    {
        return array_values(array_filter($findings, fn (CustomsFinding $finding): bool => $finding->isBlock()));
    }

    /**
     * @param  list<CustomsItem>  $zeroValued
     * @param  list<CustomsItem>  $lines
     * @param  array<int, Product|null>  $products
     */
    private function zeroValueFinding(array $zeroValued, array $lines, array $products): CustomsFinding
    {
        $names = array_map(fn (CustomsItem $item): string => $item->description, $zeroValued);

        return new CustomsFinding(
            CustomsFindingSeverity::Block,
            'zero_value',
            'Customs Value Required',
            sprintf(
                'This shipment needs a customs declaration, but %s no value: %s. '
                .'A customs form cannot declare an item at $0.00. Set the value on the shipment item, then refresh rates.',
                count($names) === 1 ? 'one item has' : count($names).' items have',
                implode(', ', $names),
            ),
            $names,
        );
    }

    /**
     * @param  list<CustomsItem>  $unidentified
     * @param  list<CustomsItem>  $lines
     * @param  array<int, Product|null>  $products
     */
    private function productIdentifierFinding(array $unidentified, array $lines, array $products): CustomsFinding
    {
        $describe = function (CustomsItem $item): string {
            $missing = array_keys(array_filter([
                'SKU' => $item->merchantProductId === null,
                'Manufacturer Part Number' => $item->manufacturerProductId === null,
            ]));

            return sprintf('%s (no %s)', $item->merchantProductId ?? $item->description, implode(' or ', $missing));
        };

        return $this->withProductFix(new CustomsFinding(
            CustomsFindingSeverity::Block,
            'product_identifier_missing',
            'Product Identifier Required',
            sprintf(
                'EU customs will hold this parcel: %s missing the product identifiers an EU consumer shipment needs: %s. '
                .'Add them on the product form (SKU, and Manufacturer Part Number under Customs), then refresh rates.',
                count($unidentified) === 1 ? 'one item is' : count($unidentified).' items are',
                implode('; ', array_map($describe, $unidentified)),
            ),
            array_map(fn (CustomsItem $item): string => $item->merchantProductId ?? $item->description, $unidentified),
        ), $unidentified, $lines, $products);
    }

    /**
     * The EU duties terms rule: an EU destination nobody chose terms for.
     *
     * @return list<CustomsFinding>
     */
    private function termsFindings(ResolvedCustomsTerms $terms, AddressData $destination): array
    {
        if (! $terms->isUnresolved()) {
            return [];
        }

        $notice = $this->dutiesTermsFilter->unresolvedNotice($terms, withoutLead: true);

        return [new CustomsFinding(
            CustomsFindingSeverity::Block,
            'duties_terms_unresolved',
            'Duties Terms Required',
            $notice->reason,
            fixUrl: $notice->fixUrl,
            fixLabel: $notice->fixLabel,
        )];
    }

    /**
     * Country of origin and HS code, line by line.
     *
     * @param  list<CustomsItem>  $lines
     * @param  array<int, Product|null>  $products
     * @return list<CustomsFinding>
     */
    private function lineFindings(array $lines, array $products, AddressData $destination, AddressData $origin): array
    {
        $findings = [];
        $needsFullHsCode = $destination->isInEuropeanUnion() || strtoupper(trim($destination->country)) === 'GB';

        $noOrigin = array_filter($lines, fn (CustomsItem $line): bool => blank($line->countryOfOrigin));

        if ($noOrigin !== []) {
            $findings[] = $this->withProductFix(new CustomsFinding(
                CustomsFindingSeverity::Block,
                'origin_missing',
                'Country of Origin Required',
                sprintf(
                    'An international label must declare where the goods were made, and %s no country of origin: %s. '
                    .'Add it on the product form (Customs), then refresh rates.',
                    count($noOrigin) === 1 ? 'one item has' : count($noOrigin).' items have',
                    $this->describeLines($noOrigin),
                ),
                $this->lineNames($noOrigin),
            ), $noOrigin, $lines, $products);
        }

        if ($needsFullHsCode) {
            $shortHs = array_filter($lines, fn (CustomsItem $line): bool => strlen(self::hsDigits($line)) < 6);

            if ($shortHs !== []) {
                $findings[] = $this->withProductFix(new CustomsFinding(
                    CustomsFindingSeverity::Block,
                    'hs_code_missing',
                    'HS Code Required',
                    sprintf(
                        'Customs in %s needs an HS code of at least 6 digits on every line, and %s: %s. '
                        .'Add it on the product form (Customs), then refresh rates.',
                        strtoupper(trim($destination->country)) === 'GB' ? 'the UK' : 'the EU',
                        count($shortHs) === 1 ? 'one item has none' : count($shortHs).' items have none',
                        $this->describeLines($shortHs),
                    ),
                    $this->lineNames($shortHs),
                ), $shortHs, $lines, $products);
            }

            return $findings;
        }

        $noHs = array_filter($lines, fn (CustomsItem $line): bool => self::hsDigits($line) === '');

        if ($noHs !== []) {
            $findings[] = $this->withProductFix(new CustomsFinding(
                CustomsFindingSeverity::Warn,
                'hs_code_missing_warning',
                'HS Code Missing',
                sprintf(
                    'No HS code on %s: %s. The label goes through, but customs may hold or reclassify the parcel. Add it on the product form (Customs).',
                    count($noHs) === 1 ? 'one item' : count($noHs).' items',
                    $this->describeLines($noHs),
                ),
                $this->lineNames($noHs),
            ), $noHs, $lines, $products);
        }

        return $findings;
    }

    /**
     * The export ITN rules: EEI for any classification over $2,500 or any
     * destination that always needs it, and the client's EIN once there is
     * an ITN to file under.
     *
     * 15 CFR 30.37(a) exempts commodities classified under an individual
     * Schedule B number or HTSUSA code worth $2,500 or less, summed over every
     * line with that classification, "regardless of the total shipment value".
     * So lines are grouped by their full HS code as stored, digits only, and
     * the group is what is measured. A shorter code that prefixes a longer one
     * cannot be told apart from it and shares its group. A line with no code
     * could belong to any group, so when one exists and the parcel is over the
     * limit the exemption cannot be shown. A missed filing is a false
     * exemption claim, so every doubt resolves toward requiring the ITN.
     *
     * @param  list<CustomsItem>  $lines
     * @param  array<int, Product|null>  $products
     * @return list<CustomsFinding>
     */
    private function exportFilingFindings(Shipment $shipment, array $lines, array $products, AddressData $origin, AddressData $destination): array
    {
        $findings = [];
        $country = strtoupper(trim($destination->country));
        $hasItn = filled($shipment->export_itn);

        if (! $hasItn && strtoupper(trim($origin->country)) === 'US' && $country !== 'CA') {
            $findings = [...$findings, ...$this->itnRequiredFindings($shipment, $lines, $country)];
        }

        if ($hasItn) {
            $client = $shipment->client;

            if ($client === null || blank($client->exporter_ein)) {
                $findings[] = $this->withFix(new CustomsFinding(
                    CustomsFindingSeverity::Block,
                    'exporter_ein_missing',
                    'Exporter EIN Required',
                    'This shipment has an export ITN, and the carrier wants the exporter\'s EIN with it, but '
                    .($client === null ? 'it has no client' : "{$client->name} has no EIN")
                    .'. Enter the client\'s own EIN on the client form. A client without a US EIN needs its filer to supply the ITN; PolyBag has no field for another party ID.'
                    .($client === null ? '' : $this->managerNote(ClientResource::class, $client)),
                ), $client === null ? null : fn (): ?array => $this->clientFix($client));
            }
        }

        return $findings;
    }

    /**
     * @param  list<CustomsItem>  $lines
     * @return list<CustomsFinding>
     */
    private function itnRequiredFindings(Shipment $shipment, array $lines, string $country): array
    {
        $total = round(array_sum(array_map(fn (CustomsItem $line): float => $line->unitValue * $line->quantity, $lines)), 2);
        $always = in_array($country, $this->referenceData->alwaysFileDestinations(), true);
        $reason = null;
        $overLimit = [];

        if ($always) {
            $reason = "EEI must be filed for every shipment to {$country}, whatever its value.";
        } else {
            $overLimit = $this->classificationsOverLimit($lines);
            $unclassified = array_filter($lines, fn (CustomsItem $line): bool => self::hsDigits($line) === '');

            if ($overLimit !== []) {
                $reason = sprintf(
                    'Under 15 CFR 30.37(a) EEI is exempt only up to $2,500 per classification, and over it: %s.',
                    implode('; ', array_map(
                        fn (array $group): string => sprintf('%s totals $%s', implode('/', $group['codes']), number_format($group['value'], 2)),
                        $overLimit,
                    )),
                );
            } elseif ($unclassified !== [] && $total > self::ITN_THRESHOLD) {
                $reason = sprintf(
                    'The customs total is $%s and %s no HS code, so the 30.37(a) exemption (up to $2,500 per classification) cannot be shown: %s. Add HS codes to the products, or enter an ITN.',
                    number_format($total, 2),
                    count($unclassified) === 1 ? 'one line has' : count($unclassified).' lines have',
                    $this->describeLines($unclassified),
                );
            }
        }

        if ($reason === null) {
            return [];
        }

        $invalidImport = str_contains((string) $shipment->validation_message, 'Export ITN not imported')
            ? ' The order was imported with an invalid ITN, which was dropped.'
            : '';

        return [$this->withFix(new CustomsFinding(
            CustomsFindingSeverity::Block,
            'export_itn_required',
            'Export ITN Required',
            "{$reason}{$invalidImport} File EEI in AESDirect and enter the ITN on the shipment.".$this->managerNote(ShipmentResource::class, $shipment),
        ), fn (): ?array => $this->shipmentFix($shipment, 'Enter the export ITN'))];
    }

    /**
     * The groups of lines that share a classification, over the limit.
     *
     * @param  list<CustomsItem>  $lines
     * @return list<array{codes: list<string>, value: float}>
     */
    private function classificationsOverLimit(array $lines): array
    {
        $codes = [];

        foreach ($lines as $line) {
            if (($digits = self::hsDigits($line)) !== '') {
                $codes[$digits] = ($codes[$digits] ?? 0.0) + $line->unitValue * $line->quantity;
            }
        }

        $groups = [];

        // Shortest first, so a code is merged into the group of any shorter
        // code that prefixes it, and into the earlier of two groups it joins.
        uksort($codes, fn (string $a, string $b): int => [strlen($a), $a] <=> [strlen($b), $b]);

        foreach ($codes as $code => $value) {
            $code = (string) $code;
            $joined = array_values(array_filter(
                array_keys($groups),
                fn (int $key): bool => array_any($groups[$key]['codes'], fn (string $member): bool => str_starts_with($code, $member) || str_starts_with($member, $code)),
            ));

            if ($joined === []) {
                $groups[] = ['codes' => [$code], 'value' => $value];

                continue;
            }

            $target = array_shift($joined);
            $groups[$target]['codes'][] = $code;
            $groups[$target]['value'] += $value;

            foreach ($joined as $key) {
                array_push($groups[$target]['codes'], ...$groups[$key]['codes']);
                $groups[$target]['value'] += $groups[$key]['value'];
                unset($groups[$key]);
            }
        }

        return array_values(array_filter(
            array_map(fn (array $group): array => ['codes' => $group['codes'], 'value' => round($group['value'], 2)], $groups),
            fn (array $group): bool => $group['value'] > self::ITN_THRESHOLD,
        ));
    }

    /**
     * @return list<CustomsFinding>
     */
    private function recipientTaxIdFindings(Shipment $shipment, AddressData $destination): array
    {
        $rule = $this->referenceData->recipientTaxIdRule($destination->country);

        if ($rule === null || ($rule['consumersOnly'] && filled($destination->company))) {
            return [];
        }

        $type = $shipment->recipient_tax_id_type;

        if (filled($shipment->recipient_tax_id) && $type !== null && in_array($type, $rule['types'], true)) {
            return [];
        }

        $wanted = implode(' or ', array_map(fn ($type): string => strtoupper($type->value), $rule['types']));
        $invalidImport = str_contains((string) $shipment->validation_message, 'Recipient tax ID not imported')
            ? ' The order was imported with an invalid recipient tax ID, which was dropped.'
            : '';
        $isBlock = $rule['severity'] === CustomsFindingSeverity::Block;

        return [$this->withFix(new CustomsFinding(
            $rule['severity'],
            'recipient_tax_id_missing',
            'Recipient Tax ID Required',
            ($isBlock
                ? "{$destination->country} customs holds a parcel without the recipient's {$wanted}."
                : "{$destination->country} customs may ask for the recipient's {$wanted}; the label goes through without it.")
            ."{$invalidImport} Enter it on the shipment.".$this->managerNote(ShipmentResource::class, $shipment),
        ), fn (): ?array => $this->shipmentFix($shipment, 'Enter the recipient tax ID'))];
    }

    /**
     * Seller registrations: the per-item blocks, DDP without a registration,
     * a registration withheld over its threshold.
     *
     * @return list<CustomsFinding>
     */
    private function registrationFindings(ResolvedCustomsTerms $terms, AddressData $destination): array
    {
        $findings = [];
        $registration = $terms->registration;

        if ($registration !== null && $terms->linesOverLimit !== []) {
            $regime = $registration->regime;
            $names = array_map(
                fn ($line): string => sprintf('%s (%s %s)', $line->description, number_format($line->convertedUnitValue, 2), $line->currency),
                $terms->linesOverLimit,
            );

            if ($regime === TaxRegistrationRegime::Voec) {
                $findings[] = new CustomsFinding(
                    CustomsFindingSeverity::Block,
                    'voec_item_over_limit',
                    'Ship This Item Separately',
                    'A parcel declared under VOEC cannot hold an item of NOK 3,000 or more (Skatteetaten). Ship this item separately, in its own Shipment: '.implode(', ', $names).'.',
                    array_map(fn ($line): string => $line->description, $terms->linesOverLimit),
                );
            } elseif ($regime === TaxRegistrationRegime::Arn) {
                $findings[] = new CustomsFinding(
                    CustomsFindingSeverity::Block,
                    'arn_item_over_limit',
                    'Item Over AUD 1,000',
                    'This parcel has an ARN to declare and an item over AUD 1,000, and no carrier can yet mark items GST-paid one by one. Ship this item separately, in its own Shipment: '.implode(', ', $names).'.',
                    array_map(fn ($line): string => $line->description, $terms->linesOverLimit),
                );
            }
        }

        if ($terms->dutiesTerms === DutiesTerms::Ddp && $terms->applicableRegistration === null) {
            $regime = collect(TaxRegistrationRegime::cases())->first(fn (TaxRegistrationRegime $case): bool => $case->covers($destination));

            if ($regime !== null) {
                $findings[] = new CustomsFinding(
                    CustomsFindingSeverity::Warn,
                    'ddp_without_registration',
                    'No Seller Registration',
                    "These duties are prepaid (DDP) but there is no {$regime->getLabel()} registration for {$destination->country}, so VAT may be charged twice. Add the registration to the client or the order.",
                );
            }
        }

        if ($terms->registrationWithheld()) {
            $applicable = $terms->applicableRegistration;
            $label = $applicable?->regime->getLabel() ?? 'seller';
            $message = $terms->exchangeRateMissing
                ? "No exchange rate is stored, so the order's value could not be tested against the {$label} threshold and the registration is not sent."
                : sprintf(
                    'The order is over the %s threshold (%s %s, at the ECB rate of %s), so the registration is not sent: customs would ignore it and charge VAT on the whole consignment.',
                    $label,
                    number_format((float) $terms->threshold),
                    $terms->thresholdCurrency,
                    $terms->convertedValue?->rateDate->toDateString() ?? 'the day',
                );

            $findings[] = new CustomsFinding(CustomsFindingSeverity::Warn, 'registration_not_sent', 'Registration Not Sent', $message);
        }

        if ($registration?->regime === TaxRegistrationRegime::Ioss && filled($destination->company)) {
            $findings[] = new CustomsFinding(
                CustomsFindingSeverity::Warn,
                'ioss_with_company',
                'IOSS With a Company Name',
                'The recipient address has a company name, so customs is likely to treat the parcel as business-to-business and ignore the IOSS number.',
            );
        }

        return $findings;
    }

    /**
     * Warnings that concern one carrier. Before a rate is chosen they show for
     * every carrier they might concern.
     *
     * @param  list<CustomsItem>  $lines
     * @return list<CustomsFinding>
     */
    private function carrierFindings(Shipment $shipment, array $lines, ResolvedCustomsTerms $terms, AddressData $destination, ?string $carrier): array
    {
        $findings = [];
        $key = $carrier === null ? null : CarrierAlias::lookupKey($carrier);

        if ($key === null || $key === CarrierAlias::lookupKey(Carrier::USPS)) {
            $long = array_filter($lines, fn (CustomsItem $line): bool => mb_strlen($line->description) > self::USPS_DESCRIPTION_LIMIT);

            if ($long !== []) {
                $findings[] = new CustomsFinding(
                    CustomsFindingSeverity::Warn,
                    'usps_description_cut',
                    'USPS Cuts Descriptions',
                    sprintf('USPS cuts a customs description at %d characters: %s.', self::USPS_DESCRIPTION_LIMIT, $this->describeLines($long)),
                    $this->lineNames($long),
                );
            }
        }

        if (($key === null || $key === CarrierAlias::lookupKey(Carrier::FEDEX))
            && $terms->dutiesTerms === DutiesTerms::Ddu
            && FedexRecipientEmail::usable($destination->email) === null) {
            $tooLong = FedexRecipientEmail::isTooLong($destination->email);
            $findings[] = new CustomsFinding(
                CustomsFindingSeverity::Warn,
                'fedex_ddu_without_email',
                $tooLong ? 'Recipient Email Too Long' : 'No Recipient Email',
                $tooLong
                    ? sprintf('FedEx takes an email address of at most %d characters and this one is longer, so it is not sent. FedEx cannot collect duties from the recipient without it, and the charges fall back to the shipper. Shorten or replace the email on the shipment before buying this DDU label from FedEx.', FedexRecipientEmail::MAX_LENGTH)
                    : 'FedEx cannot collect duties from the recipient without an email address, and the charges fall back to the shipper. Add an email to the shipment before buying this DDU label from FedEx.',
            );
        }

        return $findings;
    }

    /**
     * The HS code as digits only.
     */
    private static function hsDigits(CustomsItem $line): string
    {
        return preg_replace('/\D/', '', (string) $line->hsTariffNumber) ?? '';
    }

    /**
     * @param  iterable<CustomsItem>  $lines
     */
    private function describeLines(iterable $lines): string
    {
        return implode(', ', $this->lineNames($lines));
    }

    /**
     * @param  iterable<CustomsItem>  $lines
     * @return list<string>
     */
    private function lineNames(iterable $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            $names[] = $line->merchantProductId ?? $line->description;
        }

        return $names;
    }

    /**
     * Link the finding to the product form of the first offending line the
     * user may edit.
     *
     * @param  iterable<CustomsItem>  $offending
     * @param  list<CustomsItem>  $lines
     * @param  array<int, Product|null>  $products
     */
    private function withProductFix(CustomsFinding $finding, iterable $offending, array $lines, array $products): CustomsFinding
    {
        foreach ($offending as $line) {
            $index = array_search($line, $lines, true);
            $product = $index === false ? null : ($products[$index] ?? null);

            if ($product === null || ! $this->mayEdit(ProductResource::class, $product)) {
                continue;
            }

            return $this->withFix($finding, fn (): array => [
                ProductResource::getUrl('edit', ['record' => $product]),
                'Edit '.($product->sku ?: $product->name),
            ]);
        }

        return $finding;
    }

    /**
     * @param  (\Closure(): (array{0: string, 1: string}|null))|null  $fix
     */
    private function withFix(CustomsFinding $finding, ?\Closure $fix): CustomsFinding
    {
        $resolved = $fix?->__invoke();

        if ($resolved === null) {
            return $finding;
        }

        return new CustomsFinding(
            $finding->severity,
            $finding->code,
            $finding->title,
            $finding->message,
            $finding->lines,
            $resolved[0],
            $resolved[1],
        );
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function shipmentFix(Shipment $shipment, string $label): ?array
    {
        return $this->mayEdit(ShipmentResource::class, $shipment)
            ? [ShipmentResource::getUrl('edit', ['record' => $shipment]), $label]
            : null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function clientFix(Client $client): ?array
    {
        if (! $this->mayEdit(ClientResource::class, $client)) {
            return null;
        }

        // The Clients page is not in a single-client install's navigation; the
        // default client's customs settings live in Settings.
        return (bool) app(SettingsService::class)->get('multi_client_enabled', false)
            ? [ClientResource::getUrl('edit', ['record' => $client]), "Enter the EIN for {$client->name}"]
            : [Settings::getUrl(), 'Enter the EIN in Settings'];
    }

    /**
     * A sentence sending a user who cannot edit the record to someone who can,
     * so a floor user on Manual Ship, who cannot edit the Shipment it created,
     * is told who can rather than shown a link they cannot follow.
     *
     * @param  class-string  $resource
     */
    private function managerNote(string $resource, Model $record): string
    {
        return $this->mayEdit($resource, $record) ? '' : ' A manager must add it.';
    }

    /**
     * Whether the signed-in user may edit the record. With nobody signed in, as
     * in a queued batch, the answer is yes: the finding is recorded, and the
     * link is for whoever reads it.
     *
     * @param  class-string  $resource
     */
    private function mayEdit(string $resource, Model $record): bool
    {
        return auth()->guest() || $resource::canEdit($record);
    }
}
