<?php

namespace App\Services;

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\PostageSources\ServiceApprovalRules;
use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\OfferRequirements;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\UnattendedRateSelection;
use App\Enums\AmazonChannelType;
use App\Enums\PostageSourceKind;
use App\Models\DataSource;
use App\Services\PostageSources\ServiceApprovalGate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RateSelector
{
    public function __construct(
        private readonly ServiceApprovalGate $approvals,
    ) {}

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
     * A discovered service nobody has approved is refused for the same reason
     * and by the same rule — see {@see selectForAutomation()}, which is this
     * method with the refusals kept rather than dropped.
     *
     * The client is a required argument with no default. It is what an approval
     * is granted *by*, so a parameter that filled itself in would be a way to
     * spend one client's authorization on another client's parcel; null is
     * accepted and denies every discovered service, because a package with no
     * client is a caller that has lost track of whose money this is.
     *
     * @param  Collection<int, RateResponse>  $rates
     */
    public function selectBest(Collection $rates, ?Carbon $deadline, ?int $clientId): ?RateResponse
    {
        return $this->selectForAutomation($rates, $deadline, $clientId)->rate;
    }

    /**
     * The same selection, with the rates it refused to consider.
     *
     * ADR-0003 decision 4 splits on who is choosing: an unapproved service stays
     * on the Ship page for a packer who sees the price and takes responsibility,
     * and is unreachable from auto-ship, batch ship, shipping rules and
     * {@see selectBest()}. This is the one place that split is enforced, so that
     * approving a service makes it eligible in all four without a code change.
     *
     * The refusals come back because a batch that reports "no rates available"
     * for a package that was quoted three sends an operator to the carrier, when
     * the actual answer is that an administrator has not approved the service
     * yet.
     *
     * The order's shipping method can also require that the rate arrive by
     * the due-by date, or, for an Amazon order, be OTDR-protected, or both
     * (`amazon-buy-shipping/17`). A rate that fails either is refused the same
     * way an unapproved one is: kept for the Ship page, named in the result.
     * With neither required, a late rate is still bought when nothing is on
     * time, as before. An order with no due-by date cannot show that any rate
     * is late, so it refuses none, except an Amazon order, which refuses every
     * rate rather than passing them all the way {@see classify()} does.
     *
     * A content-restricted rate is refused before approval is asked about. It
     * is valid only for contents nothing in PolyBag vouches for, so no
     * approval, not even one of everything, can make automation the party
     * that vouches (ADR-0006 decision 10). It is kept for the Ship page and
     * named in the result as its own refusal, never as a missing approval.
     *
     * So is Amazon Buy Shipping for an Amazon order whose connection sells
     * postage to a packer only (ADR-0006 decision 6). It is refused before
     * approval is asked about, because the setting only narrows: an approval
     * cannot release what the connection refuses to automation.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @param  DataSource|null  $channelSource  The package's channel connection, whose postage setting governs the Amazon Buy Shipping it sells for its own orders
     */
    public function selectForAutomation(
        Collection $rates,
        ?Carbon $deadline,
        ?int $clientId,
        ?OfferRequirements $requirements = null,
        ?DataSource $channelSource = null,
    ): UnattendedRateSelection {
        $requirements ??= OfferRequirements::none();

        [$contentRestricted, $unrestricted] = $rates->partition(fn (RateResponse $rate): bool => $rate->contentRestricted);

        [$heldBySetting, $unrestricted] = $this->partitionByPostageSetting($unrestricted->values(), $channelSource);

        [$eligible, $withheld] = $this->partitionByApproval($unrestricted->values(), $clientId);

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
            withheld: $withheld,
            attendedAlternativeAvailable: $withheld->isNotEmpty()
                || $contentRestricted->isNotEmpty()
                || $heldBySetting->isNotEmpty()
                || $late->isNotEmpty()
                || $unprotected->isNotEmpty()
                || $eligible->contains(fn (RateResponse $rate): bool => $rate->priceUnknown),
            late: $late,
            unprotected: $unprotected,
            requirements: $requirements,
            deadlineMissing: $requirements->deadlineRequired && $deadline === null,
            contentRestricted: $contentRestricted->values(),
            heldByPostageSetting: $heldBySetting,
            postageSettingConnection: $heldBySetting->isNotEmpty() ? $channelSource?->name : null,
        );
    }

    /**
     * Split off the Amazon Buy Shipping rates for the connection's own orders
     * that its postage setting keeps from automation.
     *
     * Only `AMAZON`-channel rates: Amazon Shipping sold to an order from
     * another channel is a direct sale no postage setting covers.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return array{0: Collection<int, RateResponse>, 1: Collection<int, RateResponse>} held, then the rest
     */
    private function partitionByPostageSetting(Collection $rates, ?DataSource $channelSource): array
    {
        if ($channelSource === null || ! $channelSource->isAmazon() || $channelSource->postageSetting()->allowsAutomation()) {
            return [collect(), $rates];
        }

        [$held, $rest] = $rates->partition(fn (RateResponse $rate): bool => $rate->sourceKind() === PostageSourceKind::Amazon
            && $rate->observedService?->channelType === AmazonChannelType::Amazon);

        return [$held->values(), $rest->values()];
    }

    /**
     * Split rates into the ones automation may buy and the ones it may not.
     *
     * A rate naming no observed service is authored configuration — a seeded
     * `CarrierService` quoted on an account we hold — and passes untouched.
     * Approval governs *discovered* services, and gating the seeded catalog on
     * it would stop an install that has approved nothing from buying anything,
     * which is the opposite of deny-by-default meaning "behaves as it did
     * before discovery existed".
     *
     * One query per (source, environment, channel type) rather than one per rate — in
     * practice one per quote: an Amazon `getRates` can return several eligible
     * offers at once, and this runs on the batch-ship path for every package.
     * Wildcards and exceptions are matched in memory by
     * {@see ServiceApprovalRules}. A rate list with no discovered services —
     * every install that has never quoted through a channel — asks the
     * database nothing at all.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return array{0: Collection<int, RateResponse>, 1: Collection<int, RateResponse>}
     */
    private function partitionByApproval(Collection $rates, ?int $clientId): array
    {
        $discovered = $rates->filter(fn (RateResponse $rate): bool => $rate->observedService !== null);

        if ($discovered->isEmpty()) {
            return [$rates, collect()];
        }

        $rules = $this->rulesFor($discovered, $clientId);

        [$eligible, $withheld] = $rates->partition(function (RateResponse $rate) use ($rules): bool {
            $identity = $rate->observedService;

            return $identity === null
                || $rules[self::worldKey($identity)]->permits($identity->externalCarrierId, $identity->externalServiceId);
        });

        return [$eligible->values(), $withheld->values()];
    }

    /**
     * This client's approvals for every world and channel type these rates
     * were quoted in.
     *
     * @param  Collection<int, RateResponse>  $discovered
     * @return Collection<string, ServiceApprovalRules>
     */
    private function rulesFor(Collection $discovered, ?int $clientId): Collection
    {
        return $discovered
            ->map(fn (RateResponse $rate): ObservedServiceIdentity => $rate->observedService)
            ->keyBy(fn (ObservedServiceIdentity $identity): string => self::worldKey($identity))
            ->map(fn (ObservedServiceIdentity $identity): ServiceApprovalRules => $this->approvals
                ->rulesFor($identity->source, $identity->environment, $identity->channelType, $clientId));
    }

    private static function worldKey(ObservedServiceIdentity $identity): string
    {
        return implode('|', [$identity->source, $identity->environment->value, $identity->channelType->value]);
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
