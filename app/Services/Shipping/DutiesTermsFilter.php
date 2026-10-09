<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\Shipping\DroppedRate;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\AmazonChannelType;
use App\Enums\DutiesSupport;
use App\Enums\TaxRegistrationRegime;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\ShippingOffer;
use App\Services\AddressReferenceService;
use App\Services\Customs\DutiesSupportTable;
use App\Services\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Drops a rate whose carrier cannot ship on the Shipment's resolved duties
 * term, and says why (ADR-0008 decisions 1 and 4).
 *
 * Runs beside {@see PackagingFilter} and {@see ContentsFilter}, before the
 * quote log in `ShippingRateService::getShippingRates()`, so a dropped rate is
 * never logged or issued an Offer, on the Ship page, batch ship and
 * automation alike. A rate is never forced to the other term: forcing DDP
 * bills duties against the client's choice, and sending DDU into a country
 * that requires DDP walks the parcel into a refusal.
 *
 * Two cases drop:
 *
 * - **The carrier's support does not fit the term**, from
 *   `duties-support.json`: `ddp_required` with DDU, `ddu_only` with DDP.
 *   `international-customs-terms/08` adds a second reason here: a USPS
 *   account that has not accepted the DDP terms.
 * - **The term is unresolved**: an EU destination with no term from the order
 *   or the client. Every rate PolyBag would set terms on is dropped, and
 *   {@see self::unresolvedNotice()} names the fix — Settings in single-client
 *   mode, the client form otherwise.
 *
 * A rate from a source that takes no terms — Amazon Buy Shipping, or Amazon
 * Shipping sold on a connection — is source-decided and passes untouched,
 * through the unresolved-EU refusal too (ADR-0008 decision 5). A carrier
 * `duties-support.json` does not list is not judged.
 */
class DutiesTermsFilter
{
    public function __construct(
        private readonly DutiesSupportTable $dutiesSupport,
        private readonly AddressReferenceService $addresses,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  Collection<int, RateResponse>  $rates
     * @param  CarbonInterface|null  $on  The day an entry's `effective_from` is judged against; today when null
     * @return array{kept: Collection<int, RateResponse>, dropped: list<DroppedRate>, droppedRates: list<RateResponse>}
     */
    public function apply(Collection $rates, ?ResolvedCustomsTerms $terms, ?CarbonInterface $on = null): array
    {
        if ($terms === null || ! $terms->applies) {
            return ['kept' => $rates->values(), 'dropped' => [], 'droppedRates' => []];
        }

        $on ??= now();
        $kept = collect();
        $dropped = [];
        $droppedRates = [];

        foreach ($rates as $rate) {
            if ($this->isSourceDecided($rate)) {
                $kept->push($rate);

                continue;
            }

            // Unresolved: the caller adds {@see self::unresolvedNotice()} once.
            if ($terms->dutiesTerms === null) {
                $droppedRates[] = $rate;

                continue;
            }

            $entry = $this->dutiesSupport->supportFor($rate->carrier, $terms->destinationCountry, $on, $terms->recipientIsBusiness ? null : $terms->registration?->regime);

            if ($entry === null || $entry->support->allows($terms->dutiesTerms)) {
                $kept->push($rate);

                continue;
            }

            $carrier = Carrier::labelForName($rate->carrier);
            $reason = sprintf(
                '%s dropped: %s %s (%s)',
                $carrier,
                $this->countryName($terms->destinationCountry),
                ($entry->support === DutiesSupport::DdpRequired ? 'requires prepaid duties' : 'cannot take prepaid duties')
                    .($entry->registration !== null ? ' with '.$this->registrationName($entry->registration) : ''),
                $entry->authority,
            );

            $droppedRates[] = $rate;
            $dropped[$carrier.'|'.$reason] = new DroppedRate($carrier, $reason);
        }

        return ['kept' => $kept, 'dropped' => array_values($dropped), 'droppedRates' => $droppedRates];
    }

    /**
     * Whether PolyBag cannot set the terms of this rate, so the source
     * decides them (ADR-0008 decision 5): Amazon Buy Shipping, resold through
     * a channel, and Amazon Shipping sold on a connection, whose purchase
     * takes no duties terms either. Such a rate skips the support check and
     * the unresolved-EU refusal alike.
     *
     * At purchase the rate is rebuilt from the stored Offer, which does not
     * keep the observed service that marks a rate as Amazon's; the Offer says
     * so itself, by the channel Amazon quoted it on, so pass it when there is
     * one.
     */
    public function isSourceDecided(RateResponse $rate, ?ShippingOffer $offer = null): bool
    {
        return $rate->isSourceDecided()
            || $offer?->amazonChannelType() === AmazonChannelType::Amazon;
    }

    /**
     * The refusal for an EU destination nobody has chosen terms for, naming
     * the fix and linking to where it is made. Single-client installs keep
     * the default client's policy in Settings, since the Clients page is not
     * in their navigation.
     *
     * @param  bool  $otherOffersRemain  Whether source-decided rates or blind offers are still offered, so the notice says only the direct rates are gone
     * @param  bool  $withoutLead  Leave out the "No rates:" lead, for the readiness finding that states the same fact on its own
     */
    public function unresolvedNotice(ResolvedCustomsTerms $terms, bool $otherOffersRemain = false, bool $withoutLead = false): DroppedRate
    {
        $country = $this->countryName($terms->destinationCountry);
        $lead = $otherOffersRemain ? 'No direct rates' : 'No rates';
        $prefix = $withoutLead ? '' : "{$lead}: ";
        $client = $terms->clientId !== null ? Client::query()->find($terms->clientId) : null;

        if (! (bool) $this->settings->get('multi_client_enabled', false) || $client === null) {
            return new DroppedRate(
                carrier: null,
                reason: $prefix.($withoutLead ? 'No' : 'no')." duties terms are set for {$country} or the EU. Choose EU duties terms under Customs in Settings, or give the order its own terms.",
                fixUrl: Settings::getUrl(),
                fixLabel: 'Set duties terms in Settings',
            );
        }

        return new DroppedRate(
            carrier: null,
            reason: "{$prefix}{$client->name} has no duties terms for {$country} or the EU. Choose EU duties terms on the client, or give the order its own terms.",
            fixUrl: ClientResource::getUrl('edit', ['record' => $client]),
            fixLabel: "Set duties terms for {$client->name}",
        );
    }

    /**
     * How a reason names the registration, such as "an IOSS number".
     */
    private function registrationName(TaxRegistrationRegime $regime): string
    {
        return match ($regime) {
            TaxRegistrationRegime::Ioss => 'an IOSS number',
            TaxRegistrationRegime::UkVat => 'a UK VAT number',
            TaxRegistrationRegime::Voec => 'a VOEC number',
            TaxRegistrationRegime::Arn => 'an ARN',
        };
    }

    private function countryName(string $country): string
    {
        return $this->addresses->getCountryOptions()[$country] ?? $country;
    }
}
