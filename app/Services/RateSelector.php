<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\OfferRequirements;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\UnattendedRateSelection;
use App\Enums\PostageSourceKind;
use App\Models\DataSource;
use App\Models\ShippingMethod;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RateSelector
{
    /**
     * Classify and sort rates into on-time then late, each group sorted cheapest first.
     * "On-time" requires a known delivery date on or before the deadline.
     * Unknown delivery date with a deadline counts as late.
     * No deadline = all rates classified as on-time.
     *
     * A rate whose price is only known at purchase time sorts after every
     * priced rate in its group, so it is never mistaken for the cheapest one.
     * It stays in the list: this is the attended view, where a packer sees what
     * is unpriced and takes responsibility for choosing it. What it may not do
     * is win unattended — see {@see self::selectBest()}.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return Collection<int, ClassifiedRate>
     */
    public function classify(Collection $rates, ?Carbon $deadline): Collection
    {
        $classified = $rates->map(
            fn (RateResponse $rate): ClassifiedRate => new ClassifiedRate(
                rate: $rate,
                isOnTime: $this->isOnTime($rate, $deadline),
            )
        );

        $onTime = $classified
            ->filter(fn (ClassifiedRate $cr): bool => $cr->isOnTime)
            ->sortBy(fn (ClassifiedRate $cr): array => $this->sortKey($cr->rate));

        $late = $classified
            ->filter(fn (ClassifiedRate $cr): bool => ! $cr->isOnTime)
            ->sortBy(fn (ClassifiedRate $cr): array => $this->sortKey($cr->rate));

        return $onTime->merge($late)->values();
    }

    /**
     * Select the best rate: cheapest on-time when a deadline exists, otherwise cheapest overall.
     *
     * Unpriced rates are dropped rather than ranked last, and null is the
     * honest answer when nothing priced is left. This is the unattended path —
     * auto-ship, batch ship — and "it only wins when nothing else is offered"
     * is precisely the case ADR-0003 decision 5 refuses: spending money at a
     * price nobody has seen, on nobody's authority, because the alternatives
     * happened to be unavailable.
     *
     * A rate outside the shipping method's allowance is refused for the same
     * reason and by the same rule — see {@see selectForAutomation()}, which is
     * this method with the refusals kept rather than dropped.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @param  ShippingMethod  $method  The shipment's shipping method, whose postage-source rows are the allowance
     */
    public function selectBest(Collection $rates, ?Carbon $deadline, ShippingMethod $method): ?RateResponse
    {
        return $this->selectForAutomation($rates, $deadline, $method)->rate;
    }

    /**
     * The same selection, with the rates it refused to consider.
     *
     * Who is choosing decides what may be bought. Every quoted offer stays on
     * the Ship page for a packer who sees the price and takes responsibility.
     * Auto-ship, batch ship and shipping rules buy only within the shipping
     * method's allowance (ADR-0006 decisions 5 and 8,
     * `carrier-catalog-reset/13`). This is the one place that is enforced.
     *
     * The refusals come back because a batch that reports "no rates available"
     * for a package that was quoted three sends an operator to the carrier,
     * when the actual answer is that the shipping method does not allow what
     * was quoted.
     *
     * The order's shipping method can also require that the rate arrive by
     * the due-by date, or, for an Amazon order, be OTDR-protected, or both
     * (`amazon-buy-shipping/17`). A rate that fails either is refused the same
     * way one outside the allowance is: kept for the Ship page, named in the
     * result. With neither required, a late rate is still bought when nothing
     * is on time, as before. An order with no due-by date cannot show that any
     * rate is late, so it refuses none, except an Amazon order, which refuses
     * every rate rather than passing them all the way {@see classify()} does.
     *
     * A content-restricted rate is refused before the allowance is asked
     * about. It is valid only for contents nothing in PolyBag vouches for, so
     * no allowance, not even *any service*, can make automation the party that
     * vouches (ADR-0006 decision 10). It is kept for the Ship page and named in
     * the result as its own refusal.
     *
     * So is a rate naming a catalog service or carrier somebody deactivated,
     * which no allowance releases either.
     *
     * So is Amazon Buy Shipping for an Amazon order whose connection sells
     * postage to a packer only (ADR-0006 decision 6). The setting only
     * narrows: the method cannot release what the connection refuses to
     * automation.
     *
     * So is a rate whose source decides the duties terms (Amazon, Shopify),
     * into a destination whose source-decided terms nobody has verified: EU,
     * GB, NO and AU (ADR-0008 decision 5). It is kept for the Ship page, where
     * a person chooses it, and named in the result as its own refusal.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @param  ShippingMethod  $method  The shipment's shipping method, whose postage-source rows are the allowance. A shipment with none is never rated (`carrier-catalog-reset/16`)
     * @param  DataSource|null  $channelSource  The package's channel connection, whose postage setting governs the Amazon Buy Shipping it sells for its own orders
     * @param  string|null  $sourceTermsDestination  The destination country when source-decided duties terms are held from automation there, else null
     */
    public function selectForAutomation(
        Collection $rates,
        ?Carbon $deadline,
        ShippingMethod $method,
        ?OfferRequirements $requirements = null,
        ?DataSource $channelSource = null,
        ?string $sourceTermsDestination = null,
    ): UnattendedRateSelection {
        $requirements ??= OfferRequirements::none();

        [$contentRestricted, $unrestricted] = $rates->partition(fn (RateResponse $rate): bool => $rate->contentRestricted);

        [$heldBySetting, $unrestricted] = $this->partitionByPostageSetting($unrestricted->values(), $channelSource);

        $inactive = InactiveCatalog::among($unrestricted);
        [$deactivated, $unrestricted] = $unrestricted->partition(fn (RateResponse $rate): bool => $inactive->includes($rate));

        // After the inactivity check: the Ship page grays an inactive service
        // out, so it is no attended alternative and must be reported as
        // inactive, not as held for the destination.
        [$heldForSourceTerms, $unrestricted] = $this->partitionBySourceTerms($unrestricted->values(), $sourceTermsDestination);

        [$eligible, $notAllowed] = $this->partitionByAllowance($unrestricted->values(), $method);

        $classified = $this->classify(
            $eligible->reject(fn (RateResponse $rate): bool => $rate->priceUnknown),
            $deadline,
        );

        $refusesAsLate = fn (ClassifiedRate $cr): bool => $requirements->refusesAsLate($cr->isOnTime, $deadline !== null);

        $late = $classified
            ->filter($refusesAsLate)
            ->map(fn (ClassifiedRate $cr): RateResponse => $cr->rate)
            ->values();
        $unprotected = $classified
            ->filter(fn (ClassifiedRate $cr): bool => $requirements->refusesAsUnprotected($cr->rate))
            ->map(fn (ClassifiedRate $cr): RateResponse => $cr->rate)
            ->values();

        $acceptable = $classified->first(fn (ClassifiedRate $cr): bool => ! $refusesAsLate($cr)
            && ! $requirements->refusesAsUnprotected($cr->rate));

        return new UnattendedRateSelection(
            rate: $acceptable?->rate,
            notAllowed: $notAllowed,
            shippingMethodName: $method->name,
            // Not a deactivated rate: the Ship page will not sell it either.
            attendedAlternativeAvailable: $notAllowed->isNotEmpty()
                || $contentRestricted->isNotEmpty()
                || $heldBySetting->isNotEmpty()
                || $heldForSourceTerms->isNotEmpty()
                || $late->isNotEmpty()
                || $unprotected->isNotEmpty()
                || $eligible->contains(fn (RateResponse $rate): bool => $rate->priceUnknown),
            late: $late,
            unprotected: $unprotected,
            requirements: $requirements,
            deadlineMissing: $requirements->deadlineRequired && $deadline === null,
            contentRestricted: $contentRestricted->values(),
            deactivated: $deactivated->values(),
            heldByPostageSetting: $heldBySetting,
            postageSettingConnection: $heldBySetting->isNotEmpty() ? $channelSource?->name : null,
            heldForSourceTerms: $heldForSourceTerms,
            sourceTermsDestination: $heldForSourceTerms->isNotEmpty() ? $sourceTermsDestination : null,
        );
    }

    /**
     * Split off the rates whose source decides the duties terms, when the
     * destination is one whose source-decided terms nobody has verified
     * (ADR-0008 decision 5). With no such destination, nothing is held.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return array{0: Collection<int, RateResponse>, 1: Collection<int, RateResponse>} held, then the rest
     */
    private function partitionBySourceTerms(Collection $rates, ?string $sourceTermsDestination): array
    {
        if ($sourceTermsDestination === null) {
            return [collect(), $rates];
        }

        [$held, $rest] = $rates->partition(fn (RateResponse $rate): bool => $rate->isSourceDecided());

        return [$held->values(), $rest->values()];
    }

    /**
     * Split off the Amazon Buy Shipping rates for the connection's own orders
     * that its postage setting keeps from automation.
     *
     * Amazon Shipping sold to an order from another channel is a direct rate
     * (`carrier-catalog-reset/15`), which no postage setting covers, so only
     * the Buy Shipping kind is held.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return array{0: Collection<int, RateResponse>, 1: Collection<int, RateResponse>} held, then the rest
     */
    private function partitionByPostageSetting(Collection $rates, ?DataSource $channelSource): array
    {
        if ($channelSource === null || ! $channelSource->isAmazon() || $channelSource->postageSetting()->allowsAutomation()) {
            return [collect(), $rates];
        }

        [$held, $rest] = $rates->partition(fn (RateResponse $rate): bool => $rate->sourceKind() === PostageSourceKind::Amazon);

        return [$held->values(), $rest->values()];
    }

    /**
     * Split rates into the ones the shipping method allows automation to buy
     * and the ones it does not.
     *
     * One check for every source kind. A rate passes when the method has a
     * postage-source row for its kind, and its service is one the method lists
     * or that row allows unlisted services. The connection's postage setting,
     * the other half of the allowance, was applied first. A Shopify blind
     * purchase is not a rate and never arrives here: the explicit-choice rule
     * governs it (`carrier-catalog-reset/09`).
     *
     * An inactive listed service does not count as listed. A rate naming one
     * never gets here: {@see InactiveCatalog} refused it first.
     *
     * The method's rows and service ids are read once, not per rate: this runs
     * on the batch-ship path for every package.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return array{0: Collection<int, RateResponse>, 1: Collection<int, RateResponse>} allowed, then not
     */
    private function partitionByAllowance(Collection $rates, ShippingMethod $method): array
    {
        $method->loadMissing('postageSources');
        $listed = $method->carrierServices()
            ->active()
            ->withActiveCarrier()
            ->pluck('carrier_services.id')
            ->all();

        [$allowed, $notAllowed] = $rates->partition(function (RateResponse $rate) use ($method, $listed): bool {
            $row = $method->postageSourceFor($rate->sourceKind());

            return $row !== null
                && ($row->allowsUnlistedServices() || in_array($rate->carrierServiceId, $listed, true));
        });

        return [$allowed->values(), $notAllowed->values()];
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function sortKey(RateResponse $rate): array
    {
        return [$rate->priceUnknown ? 1 : 0, $rate->price];
    }

    private function isOnTime(RateResponse $rate, ?Carbon $deadline): bool
    {
        if (! $deadline) {
            return true;
        }

        $deliveryDate = $rate->parsedDeliveryDate();

        // Deadlines are calendar dates (midnight); ignore the carrier's time-of-day
        // commitment so a same-day delivery at, e.g., 5pm isn't flagged late.
        return $deliveryDate !== null && $deliveryDate->startOfDay()->lte($deadline->copy()->startOfDay());
    }
}
