<?php

namespace App\Services\PackageShipping;

use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\OfferRequirements;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\UnattendedRateSelection;
use App\Models\Package;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\RateSelector;
use App\Services\RuleEvaluator;
use App\Services\ShippingRateService;
use Illuminate\Support\Collection;

/**
 * What automation may buy for a package, and why it bought nothing.
 *
 * Kept apart from {@see EloquentPackageShippingWorkflow}, which buys: every
 * rule the purchase enforces applies alike to what this selects and to what
 * the Ship page sends, because both reach it through the same path
 * (`project-review/05`).
 */
class UnattendedRateSelector
{
    public function __construct(
        private readonly ShippingRateService $shippingRateService,
        private readonly RuleEvaluator $ruleEvaluator,
        private readonly RateSelector $rateSelector,
        private readonly PostageSourceResolver $postageSourceResolver,
    ) {}

    /**
     * What automation may buy for this package, and what it refused to.
     *
     * Every unattended path arrives here — auto-ship from Pack and Manual Ship,
     * and batch ship through `GenerateLabelJob` — and every one of them leaves
     * through {@see RateSelector::selectForAutomation()}, which is the single
     * place the shipping method's allowance is enforced for quoted services
     * (`carrier-catalog-reset/13`). A blind
     * purchase follows a separate explicit-choice policy: a matching rule may
     * name it, or it may be inferred only when it is the ShippingMethod's sole
     * configured, package-eligible choice.
     *
     * Either way, channel postage is bought only when its connection's postage
     * setting allows automation (ADR-0006 decision 6). An offer the setting
     * holds back is kept in the result, so the refusal can name the setting.
     *
     * Deliberately not routed through {@see EloquentPackageShippingWorkflow::prepareRates()}. That builds the
     * attended view — where a service outside the allowance is *supposed* to appear, with
     * its price, for a packer to take responsibility for — and its
     * `selectedRateIndex` is a default highlight, not a decision. Reading a
     * choice off the attended list is how the two would come to mean the same
     * thing again.
     */
    public function select(Package $package): UnattendedRateSelection
    {
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod']);

        $ruleResult = $this->ruleEvaluator->evaluate($package->shipment, $package);
        $method = $package->shipment->shippingMethod
            ?? throw new \LogicException('autoShip() refuses a shipment with no shipping method before selecting a rate.');
        $channel = $this->postageSourceResolver->channelSourceFor($package);
        $blindAllowed = $channel?->postageSetting()->allowsAutomation() ?? false;

        /** @var Collection<int, BlindPurchaseOffer> $heldBlind */
        $heldBlind = collect();
        $finish = fn (UnattendedRateSelection $selection): UnattendedRateSelection => $channel === null
            ? $selection
            : $selection->holdingBlindOffers($heldBlind, $channel->name);

        // A blind purchase is never held to the method's requirements: a rule
        // naming it, or its being the method's only choice, is the operator's
        // consent to an undated purchase, due-by date or not
        // (`project-review/20`). The method form says so.
        if ($ruleResult->hasPreSelectedBlindPurchase()) {
            $blindOffer = $this->shippingRateService
                ->blindPurchaseOffersFor($package)
                ->reject($ruleResult->excludesBlindOffer(...))
                ->first(fn (BlindPurchaseOffer $offer): bool => $offer->id() === $ruleResult->preSelectedBlindPurchaseId);

            if ($blindOffer && $blindAllowed) {
                return new UnattendedRateSelection(
                    rate: null,
                    notAllowed: collect(),
                    blindOffer: $blindOffer,
                );
            }

            // A rule cannot reach what the connection sells to a packer only.
            // Rate shopping goes on, as it does when the offer is gone.
            if ($blindOffer) {
                $heldBlind->push($blindOffer);
            }
        }

        $deadline = $package->shipment->getDeliverByDate();
        $requirements = $this->offerRequirementsFor($package);

        // A rule naming a scope of quoted rates selects among them like any
        // rate-shopped offer, so a service outside the allowance is refused and named,
        // and a rate an *Exclude* rule matches is never in it
        // (`project-review/17`). A *Direct* rule's service is chosen this way
        // too, on a real quote with a price and a delivery date
        // (`project-review/18`). Never a blind purchase. A rule naming Amazon
        // buys from Amazon or not at all (`amazon-buy-shipping/19`); any other
        // scope with nothing quoted in it falls through to rate shopping.
        $quoted = null;

        if ($ruleResult->hasPreSelectedScope()) {
            $scope = $ruleResult->preSelectedScope;
            $quoted = $this->shippingRateService->getShippingRates($package->id);
            $rates = $quoted
                ->reject(fn (RateResponse $rate): bool => $ruleResult->excludes($rate))
                ->filter(fn (RateResponse $rate): bool => $scope->matches($rate))
                ->values();

            if ($rates->isNotEmpty() || $scope->strict) {
                if ($rates->isEmpty()) {
                    logger()->info('A shipping rule names a source that quoted nothing buyable for this package', [
                        'package_id' => $package->id,
                        ...$scope->toLogContext(),
                    ]);
                }

                return $finish($this->rateSelector->selectForAutomation($rates, $deadline, $method, $requirements, $channel));
            }

            logger()->info('A shipping rule names a service no source quoted, or an Exclude rule removed, for this package; rate shopping instead', [
                'package_id' => $package->id,
                ...$scope->toLogContext(),
            ]);
        }

        $rates = $quoted ?? $this->shippingRateService->getShippingRates($package->id);

        if ($ruleResult->shouldFilterRates()) {
            $rates = $rates->reject(fn (RateResponse $rate): bool => $ruleResult->excludes($rate));
        }

        $selection = $this->rateSelector->selectForAutomation($rates, $deadline, $method, $requirements, $channel);

        if ($selection->rate === null) {
            $blindOffer = $this->shippingRateService->soleBlindPurchaseOfferForAutomation(
                $package->id,
                $ruleResult->excludesBlindOffer(...),
            );

            if ($blindOffer && $blindAllowed) {
                return new UnattendedRateSelection(
                    rate: null,
                    notAllowed: $selection->notAllowed,
                    shippingMethodName: $selection->shippingMethodName,
                    blindOffer: $blindOffer,
                );
            }

            if ($blindOffer && ! $heldBlind->contains(fn (BlindPurchaseOffer $held): bool => $held->id() === $blindOffer->id())) {
                $heldBlind->push($blindOffer);
            }
        }

        return $finish(new UnattendedRateSelection(
            rate: $selection->rate,
            notAllowed: $selection->notAllowed,
            shippingMethodName: $selection->shippingMethodName,
            attendedAlternativeAvailable: $selection->attendedAlternativeAvailable
                || $this->shippingRateService->getBlindPurchaseOffers($ruleResult->excludesBlindOffer(...))->isNotEmpty(),
            late: $selection->late,
            unprotected: $selection->unprotected,
            requirements: $selection->requirements,
            deadlineMissing: $selection->deadlineMissing,
            contentRestricted: $selection->contentRestricted,
            deactivated: $selection->deactivated,
            heldByPostageSetting: $selection->heldByPostageSetting,
            blindOffersHeldByPostageSetting: $selection->blindOffersHeldByPostageSetting,
            postageSettingConnection: $selection->postageSettingConnection,
        ));
    }

    /**
     * What the order's shipping method requires of the rate automation buys
     * for it. OTDR protection is only ever required of Amazon's own orders.
     */
    private function offerRequirementsFor(Package $package): OfferRequirements
    {
        return OfferRequirements::forShipment(
            $package->shipment,
            $this->postageSourceResolver->isAmazonOrder($package),
        );
    }

    /**
     * Why nothing was bought, in words an operator can act on.
     *
     * "No shipping rates available" is true of an empty rate list and false of
     * a package that was quoted three services none of which its shipping
     * method allows — and it sends whoever reads it to the carrier rather than
     * to the shipping method. A batch of several hundred is exactly where that
     * misdirection costs the most, so the refusal names itself.
     */
    public function refusal(Package $package, UnattendedRateSelection $selection): PackageShippingResult
    {
        if (! $selection->attendedAlternativeAvailable) {
            return $selection->deactivatedAnything()
                ? $this->onlyInactiveServices($package, $selection)
                : PackageShippingResult::failed('Shipping Error', 'No shipping rates available for this package.');
        }

        if ($selection->contentRestrictedAnything()) {
            logger()->info('Withheld a content-restricted rate from automated purchase', [
                'package_id' => $package->id,
                'content_restricted' => $selection->contentRestrictedSummary(),
            ]);
        }

        if ($selection->deactivatedAnything()) {
            logger()->info('Refused a rate for a deactivated service or carrier', [
                'package_id' => $package->id,
                'deactivated' => $selection->deactivatedSummary(),
            ]);
        }

        if ($selection->heldByPostageSettingAnything()) {
            logger()->info('Held channel postage from automated purchase under the connection\'s postage setting', [
                'package_id' => $package->id,
                'connection' => $selection->postageSettingConnection,
                'held' => $selection->heldByPostageSettingSummary(),
            ]);
        }

        if ($selection->refusedForRequirements()) {
            return $this->refusedForMethodRequirements($package, $selection);
        }

        if (! $selection->notAllowedAnything() && $selection->heldByPostageSettingAnything()) {
            return PackageShippingResult::attendedSelectionRequired(
                'Connection Sells to Packers Only',
                "This package was offered {$selection->heldByPostageSettingSummary()}, but the connection \"{$selection->postageSettingConnection}\" sells postage to a packer only, so automation does not buy it. "
                .$this->contentRestrictionNote($selection)
                .$this->deactivatedNote($selection)
                .'Ship this package from the Ship page, or set the connection\'s postage setting to Packer and automation.',
            );
        }

        if (! $selection->notAllowedAnything() && $selection->contentRestrictedAnything()) {
            return PackageShippingResult::attendedSelectionRequired(
                'Content-Restricted Rates Only',
                'This package was quoted, but automation never buys '.$selection->contentRestrictedSummary()
                .': the service is valid only for restricted contents, and nothing in PolyBag vouches for what this package holds. '
                .$this->deactivatedNote($selection)
                .'Ship it from the Ship page, where a person checks the contents and chooses the rate.',
            );
        }

        if (! $selection->notAllowedAnything()) {
            return PackageShippingResult::attendedSelectionRequired(
                'Attended Shipping Required',
                'Auto Ship cannot purchase the available attended-only postage. Continue on the Ship page to review and confirm it.',
            );
        }

        logger()->warning('Refused a rate for automated purchase because the shipping method does not allow it', [
            'package_id' => $package->id,
            'shipping_method' => $selection->shippingMethodName,
            'not_allowed' => $selection->notAllowedForLog(),
        ]);

        return PackageShippingResult::attendedSelectionRequired(
            'Not Allowed by Shipping Method',
            'This package was quoted, but '.$this->methodPhrase($selection).' does not allow automation to buy any service it was offered: '
            .$selection->notAllowedSummary().'. '
            .$this->contentRestrictionNote($selection)
            .$this->deactivatedNote($selection)
            .$this->postageSettingNote($selection)
            .'Add the service to the shipping method or allow its source any service, or ship this package from the Ship page, where a person chooses the rate.',
        );
    }

    /**
     * The shipping method, named, or what stands in for one.
     */
    private function methodPhrase(UnattendedRateSelection $selection): string
    {
        return $selection->shippingMethodName !== null
            ? "the shipping method \"{$selection->shippingMethodName}\""
            : 'the shipping method';
    }

    /**
     * A sentence naming the content-restricted rates beside another refusal,
     * so an operator changing the shipping method does not expect that to
     * release them. Nothing would.
     */
    private function contentRestrictionNote(UnattendedRateSelection $selection): string
    {
        return $selection->contentRestrictedAnything()
            ? 'Automation also never buys '.$selection->contentRestrictedSummary()
                .', which is valid only for restricted contents nothing in PolyBag vouches for. '
            : '';
    }

    /**
     * A sentence naming the deactivated services beside another refusal, so an
     * operator changing the shipping method does not expect that to release
     * them. Only reactivating them would.
     */
    private function deactivatedNote(UnattendedRateSelection $selection): string
    {
        return $selection->deactivatedAnything()
            ? 'Nothing buys '.$selection->deactivatedSummary()
                .', because the service or its carrier is inactive. '
            : '';
    }

    /**
     * Every rate quoted names a deactivated service or carrier.
     *
     * Not an attended selection: the Ship page shows those rates greyed out
     * and the purchase refuses them, so sending the operator there would
     * leave them with nothing to choose. What helps is reactivating the
     * service or carrier, or a shipping method that lists an active one.
     */
    private function onlyInactiveServices(Package $package, UnattendedRateSelection $selection): PackageShippingResult
    {
        logger()->info('Every rate quoted names a deactivated service or carrier', [
            'package_id' => $package->id,
            'deactivated' => $selection->deactivatedSummary(),
        ]);

        return PackageShippingResult::failed(
            'Inactive Services Only',
            'This package was quoted only '.$selection->deactivatedSummary()
            .', and the service or its carrier is inactive, so nothing can buy it. '
            .'Reactivate it under Carriers or Carrier Services, or give the shipment a shipping method that lists an active service.',
        );
    }

    /**
     * A sentence naming what the connection's postage setting held back beside
     * another refusal, so an operator changing the shipping method does not
     * expect that to release it. Only the connection's setting would.
     */
    private function postageSettingNote(UnattendedRateSelection $selection): string
    {
        return $selection->heldByPostageSettingAnything()
            ? 'Automation also never buys '.$selection->heldByPostageSettingSummary()
                .", because the connection \"{$selection->postageSettingConnection}\" sells postage to a packer only. "
            : '';
    }

    /**
     * Nothing met what the order's shipping method requires. Said as such,
     * because "no rates" would send the operator to the carrier when the rates
     * are right there on the Ship page, marked (`amazon-buy-shipping/17`).
     */
    private function refusedForMethodRequirements(Package $package, UnattendedRateSelection $selection): PackageShippingResult
    {
        $requirements = $selection->requirements ?? OfferRequirements::none();
        $refusedLate = $selection->late?->isNotEmpty() ?? false;
        $refusedUnprotected = $selection->unprotected?->isNotEmpty() ?? false;

        $missing = match (true) {
            $refusedLate && $refusedUnprotected => 'arrives on time and is OTDR-protected',
            $refusedLate => 'arrives by the due-by date',
            default => 'is OTDR-protected',
        };

        $method = "The shipping method \"{$requirements->shippingMethodName}\"";

        if ($selection->deadlineMissing) {
            logger()->info('Refused every rate for an Amazon order that requires on-time delivery but has no due-by date', [
                'package_id' => $package->id,
            ]);

            return PackageShippingResult::attendedSelectionRequired(
                'No Due-By Date',
                "{$method} requires on-time delivery, but this order has no due-by date to check a rate against. "
                .'Ship it from the Ship page, where a person chooses the rate, or give its shipping method a delivery commitment.',
            );
        }

        logger()->info('Refused every rate under the shipping method\'s offer requirements', [
            'package_id' => $package->id,
            'shipping_method' => $requirements->shippingMethodName,
            'requires_on_time' => $requirements->onTime,
            'requires_otdr_protection' => $requirements->otdrProtection,
            'late' => $selection->late?->count() ?? 0,
            'unprotected' => $selection->unprotected?->count() ?? 0,
        ]);

        // The requirements are checked only against allowed rates, so with a
        // service outside the allowance the honest claim is about allowed
        // rates alone: the other one may well have met them.
        $allowed = $selection->notAllowedAnything() ? 'Allowed ' : '';
        $scope = $selection->notAllowedAnything()
            ? 'none of the rates it allows automation to buy does. Not allowed: '
                .$selection->notAllowedSummary().'.'
            : "none of this package's rates does.";

        return PackageShippingResult::attendedSelectionRequired(
            match (true) {
                $refusedLate && $refusedUnprotected => "No {$allowed}On-Time, Protected Rates",
                $refusedLate => "No {$allowed}On-Time Rates",
                default => "No {$allowed}OTDR-Protected Rates",
            },
            "{$method} requires a rate that {$missing}, and {$scope} "
            .$this->contentRestrictionNote($selection)
            .$this->deactivatedNote($selection)
            .$this->postageSettingNote($selection)
            .'Ship it from the Ship page, where a person chooses the rate, or change the requirement on the shipping method.',
        );
    }
}
