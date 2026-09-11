<?php

namespace App\Services\PackageShipping;

use App\Contracts\PackageShippingWorkflow;
use App\Contracts\PostageOfferSource;
use App\Contracts\RecoversUnresolvedPurchase;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\UnattendedRateSelection;
use App\Enums\PackageStatus;
use App\Exceptions\MissingDeclaredValueException;
use App\Exceptions\ShopifyDeclaredWeightException;
use App\Exceptions\ZeroValueCustomsItemException;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Models\SpecialService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\PostageSourceDispatcher;
use App\Services\RateQuoteLogger;
use App\Services\RateSelector;
use App\Services\RuleEvaluator;
use App\Services\ShippingRateService;
use App\Services\SpecialServiceResolver;
use Illuminate\Support\Facades\Cache;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;

class EloquentPackageShippingWorkflow implements PackageShippingWorkflow
{
    public function __construct(
        private readonly ShippingRateService $shippingRateService,
        private readonly RuleEvaluator $ruleEvaluator,
        private readonly RateSelector $rateSelector,
        private readonly RateQuoteLogger $rateQuoteLogger,
        private readonly CarrierRegistry $carrierRegistry,
        private readonly OfferStore $offerStore,
        private readonly PostageSourceDispatcher $postageSources,
    ) {}

    public function prepareRates(Package $package): PackageShippingOptions
    {
        $package->loadMissing(['shipment.shippingMethod']);

        $rates = $this->shippingRateService->getShippingRates($package->id);

        $exclusions = $this->shippingRateService->getExclusions();

        $ruleResult = $this->ruleEvaluator->evaluate($package->shipment);
        if ($ruleResult->shouldFilterRates()) {
            $rates = $rates->reject(
                fn (RateResponse $rate): bool => in_array($rate->serviceCode, $ruleResult->excludedServiceCodes, true)
            );
        }

        $deadline = $package->shipment->getDeliverByDate();
        $classified = $this->rateSelector->classify($rates, $deadline);

        // Per-rate special service visibility: which requested services will
        // actually be purchased with each rate, and which get stripped by
        // carrier-service scoping — so behavioral differences between rates
        // are never silent on the Ship page.
        $resolver = app(SpecialServiceResolver::class);
        $requestedCodes = $resolver->resolveForPackage($package);
        $serviceNames = $requestedCodes === []
            ? collect()
            : SpecialService::whereIn('code', $requestedCodes)->pluck('name', 'code');

        $labels = [];
        $descriptions = [];
        $options = [];

        foreach ($classified as $key => $classifiedRate) {
            $labels[$key] = $classifiedRate->rate->formLabel();
            $description = $classifiedRate->rate->formDescription();
            if (! $classifiedRate->isOnTime) {
                $description .= ' — LATE';
            }
            $descriptions[$key] = $description;
            $rateArray = $classifiedRate->rate->toArray();

            if ($requestedCodes !== []) {
                $appliedCodes = $resolver->resolveForPackageAndRate($package, $classifiedRate->rate);
                $toNames = fn (array $codes): array => array_values(
                    array_map(fn (string $code): string => $serviceNames->get($code, $code), $codes)
                );
                $rateArray['specialServices'] = [
                    'applied' => $toNames($appliedCodes),
                    'stripped' => $toNames(array_values(array_diff($requestedCodes, $appliedCodes))),
                ];
            }

            $options[$key] = $rateArray;
        }

        return new PackageShippingOptions(
            rateOptions: $options,
            rateOptionLabels: $labels,
            rateOptionDescriptions: $descriptions,
            deliverByDate: $deadline?->format('D, M j'),
            allRatesLate: $deadline !== null && $classified->isNotEmpty() && $classified->every(fn (ClassifiedRate $cr): bool => ! $cr->isOnTime),
            exclusions: $exclusions,
            selectedRateIndex: $this->selectedRateIndex($options, $ruleResult->preSelectedRate ?? null),
            // Alongside the rates, never among them, and never pre-selected:
            // a blind purchase is only ever chosen by a person who confirms it.
            blindPurchaseOffers: $this->shippingRateService->getBlindPurchaseOffers()
                ->map(fn (BlindPurchaseOffer $offer): array => $offer->toArray())
                ->values()
                ->all(),
        );
    }

    /**
     * How long one package's purchase may hold the line before the lock is
     * assumed abandoned. Generous on purpose: it spans an external label call,
     * and a lock that expires mid-purchase is worse than one held too long.
     */
    private const PURCHASE_LOCK_SECONDS = 180;

    /**
     * Buy postage for exactly one attempt at a time, per package.
     *
     * The unresolved-purchase guard and the offer claim inside are two separate
     * writes, so without this two requests carrying two *different* valid
     * offers would both read a clean package, claim their own row, and buy a
     * label each. The lock is what makes "does this package already have a
     * purchase in flight?" and "claim this offer" one decision.
     *
     * Taken without waiting. Queueing behind a carrier call that may run for a
     * minute would leave a packer staring at a frozen button, and the honest
     * answer — someone is already buying this — is one they can act on.
     *
     * A blind purchase needs a second, coarser lock on top of this one, because
     * what it buys against belongs to the shipment rather than to the package —
     * see {@see withBlindPurchaseLock()}.
     */
    public function ship(Package $package, PackageShippingRequest $request): PackageShippingResult
    {
        $lock = Cache::lock("package-purchase:{$package->id}", self::PURCHASE_LOCK_SECONDS);

        if (! $lock->get()) {
            return PackageShippingResult::offerUnavailable(
                'Purchase In Progress',
                'Postage for this package is already being bought. Wait for that attempt to finish before trying again.',
            );
        }

        try {
            return $this->withBlindPurchaseLock(
                $package,
                $request,
                fn (): PackageShippingResult => $this->buyPostage($package, $request),
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Serialize a blind purchase across every package of one shipment.
     *
     * The per-package lock above is keyed by package, which is the right grain
     * for postage bought against a package. A blind purchase is not: it is
     * bought against a shipment-level resource — for Shopify, the fulfillment
     * order recorded on `shipments.metadata`, which every package of the
     * shipment shares. Two packages therefore hold two different package locks,
     * both revalidate cleanly because neither sibling has been marked shipped
     * or carries a purchase marker yet, and both buy against the same
     * fulfillment order.
     *
     * Withdrawing the offer (see `ShopifyAdapter::shipmentAlreadyBoughtALabel()`)
     * closes that only once the first purchase has left a trace. This closes
     * the window before it does, by making revalidation and purchase one
     * decision per shipment rather than per package.
     *
     * Only blind purchases take it. Postage bought from a carrier account is
     * per-package by nature, and serializing those would refuse a second packer
     * boxing a second parcel of the same shipment for no reason.
     *
     * Taken after the package lock and never the other way round, and like that
     * one taken without waiting — so there is no ordering in which two requests
     * can wait on each other.
     *
     * @param  \Closure(): PackageShippingResult  $buy
     */
    private function withBlindPurchaseLock(
        Package $package,
        PackageShippingRequest $request,
        \Closure $buy,
    ): PackageShippingResult {
        $offer = $request->blindOffer;

        if ($offer === null || ! $package->shipment_id) {
            return $buy();
        }

        $lock = Cache::lock("shipment-blind-purchase:{$package->shipment_id}", self::PURCHASE_LOCK_SECONDS);

        if (! $lock->get()) {
            return PackageShippingResult::offerUnavailable(
                'Purchase In Progress',
                "{$offer->sourceLabel} buys against the whole order, and another package on this shipment is buying from it right now. "
                .'Wait for that attempt to finish, then get rates again.',
            );
        }

        try {
            return $buy();
        } finally {
            $lock->release();
        }
    }

    private function buyPostage(Package $package, PackageShippingRequest $request): PackageShippingResult
    {
        // Nothing is spent on a package that already has a purchase nobody can
        // account for. An offer consumed without the source either confirming
        // or declining may have bought a label we never recorded, and a second
        // purchase would pay for a second one.
        if (($blocked = $this->settleEarlierPurchases($package, $request)) !== null) {
            return $blocked;
        }

        // A blind offer names itself and nothing else: the carrier, the
        // selection and the eligibility all come back off the server's own
        // list, never off the request.
        $blindOffer = $request->blindOffer;

        if ($blindOffer !== null) {
            $resolved = $this->resolveBlindOffer($package, $blindOffer);

            if ($resolved instanceof PackageShippingResult) {
                return $resolved;
            }

            $blindOffer = $resolved;
        }

        // A rate carrying an offer identifier is bought against the offer, not
        // against its description: what came back from the browser says which
        // offer, and nothing more. The carrier, service and price come off the
        // stored row, so a tampered or stale rate cannot spend one offer and
        // buy something else.
        $offer = null;
        $selectedRate = $request->selectedRate;

        if ($selectedRate !== null && $selectedRate->offerId !== null) {
            $inspection = $this->offerStore->inspect($package, $selectedRate->offerId);

            if ($inspection->wasRejected()) {
                return PackageShippingResult::offerUnavailable(
                    $inspection->title(),
                    $inspection->message(),
                    $inspection->requiresRequote(),
                );
            }

            $offer = $inspection->offer;

            if ($rejection = $this->accountNoLongerResolves($offer, $package)) {
                return $rejection;
            }

            $selectedRate = $this->rateFromOffer($offer, $selectedRate);
        }

        // Nothing to mark for a blind purchase: no quote was logged, because
        // none was given.
        if ($selectedRate !== null) {
            $this->rateQuoteLogger->markSelected($package->id, $selectedRate);
        }

        try {
            $adapter = $blindOffer !== null
                ? $this->carrierRegistry->get($blindOffer->source)
                : $this->sellerFor($offer, $selectedRate);

            if ($adapter === null) {
                return $this->unsupportedDispatch($offer, $selectedRate);
            }

            $shipRequest = $blindOffer !== null
                ? ShipRequest::fromPackageAndBlindOffer(
                    $package,
                    $blindOffer,
                    $request->labelFormat,
                    $request->labelDpi,
                )
                : ShipRequest::fromPackageAndRate(
                    $package,
                    $selectedRate,
                    $request->labelFormat,
                    $request->labelDpi,
                    $offer,
                );

            // Everything that can fail locally fails before the offer is
            // claimed. A customs-weight prompt is a round trip through the
            // operator, and consuming the offer on the way out would leave the
            // confirmed retry with nothing to buy.
            //
            // A zero-value customs line is refused first, and outright: there
            // is no override for it, so asking the operator to confirm a weight
            // and then refusing anyway would be the worse order.
            if (($zeroValued = $shipRequest->zeroValueCustomsItems()) !== []) {
                throw new ZeroValueCustomsItemException($zeroValued);
            }

            if ($request->requireCustomsWeightOverride && $this->requiresCustomsWeightOverride($shipRequest, $request->overrideCustomsWeights)) {
                return PackageShippingResult::customsWeightOverrideRequired();
            }

            if ($request->overrideCustomsWeights) {
                $shipRequest = $shipRequest->withScaledCustomsWeights();
            }

            if ($request->overrideDeclaredWeight) {
                $shipRequest = $shipRequest->withDeclaredWeightOverride();
            }

            // The one-way door, immediately before the money is spent. The
            // inspection above was advisory; this is the claim that a
            // concurrent attempt loses.
            if ($offer !== null) {
                $claim = $this->offerStore->redeem($package, $offer->public_id);

                if ($claim->wasRejected()) {
                    return PackageShippingResult::offerUnavailable(
                        $claim->title(),
                        $claim->message(),
                        $claim->requiresRequote(),
                    );
                }

                $offer = $claim->offer;
            }

            $response = $adapter->createShipment($shipRequest);

            if (! $response->success) {
                // The source answered and declined, so the offer is settled
                // rather than ambiguous: nothing was bought, and the package is
                // free to be quoted again.
                $this->resolveOfferAsFailed($offer, $response->errorMessage ?? 'The carrier rejected the shipment.');

                return PackageShippingResult::failed('Shipping Error', $response->errorMessage ?? 'Failed to create shipment.');
            }

            $this->recordPurchaseAgainstOffer($offer, $response->trackingNumber);

            $package->markShipped($response, $response->postageSource, $request->userId);

            return PackageShippingResult::shipped($response, $selectedRate, $package);
        } catch (MissingDeclaredValueException $e) {
            return PackageShippingResult::failed('Declared Value Required', $e->getMessage());
        } catch (ZeroValueCustomsItemException $e) {
            return PackageShippingResult::failed('Customs Value Required', $e->getMessage());
        } catch (ShopifyDeclaredWeightException $e) {
            // Nothing was bought and nothing was claimed — the seller's own
            // declaration would have made the purchase fail, and it was
            // withheld before the mutation. The packer is shown both numbers
            // and may insist; only they can, since the remedy is a catalogue
            // PolyBag does not own.
            return PackageShippingResult::declaredWeightOverrideRequired($e->getMessage());
        } catch (RequestTimeOutException) {
            $seller = $this->sellerName($request);

            logger()->error('Carrier API timeout', [
                'carrier' => $seller,
                'package_id' => $package->id,
            ]);

            return PackageShippingResult::failed(
                'Carrier Timeout',
                "The {$seller} API is not responding. Please try again in a few moments.",
            );
        } catch (RequestException $e) {
            $seller = $this->sellerName($request);

            logger()->error('Carrier API error', [
                'carrier' => $seller,
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return PackageShippingResult::failed(
                'Carrier Error',
                "Unable to connect to {$seller}. Please check your connection and try again.",
            );
        } catch (\RuntimeException $e) {
            return PackageShippingResult::stateConflict($e->getMessage());
        } catch (\Exception $e) {
            logger()->error('Shipping error', [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return PackageShippingResult::failed('Shipping Error', 'An unexpected error occurred. Please try again.');
        }
    }

    public function autoShip(Package $package, PackageAutoShippingRequest $request): PackageShippingResult
    {
        try {
            $selection = $this->selectedRateForAutoShip($package);
            $selectedRate = $selection->rate;

            if (! $selectedRate) {
                $result = $this->nothingToBuyUnattended($package, $selection);
                $this->cleanupPackage($package, $request, $result);

                return $result;
            }

            $result = $this->ship(
                $package,
                new PackageShippingRequest(
                    selectedRate: $selectedRate,
                    labelFormat: $request->labelFormat,
                    labelDpi: $request->labelDpi,
                    requireCustomsWeightOverride: false,
                    userId: $request->userId,
                ),
            );

            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (RequestTimeOutException) {
            logger()->error('AutoShip timeout', ['package_id' => $package->id]);
            $result = PackageShippingResult::failed('Carrier Timeout', 'The carrier API is not responding. Please try again in a few moments.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (RequestException $e) {
            logger()->error('AutoShip carrier error', ['package_id' => $package->id, 'error' => $e->getMessage()]);
            $result = PackageShippingResult::failed('Carrier Error', 'Unable to connect to the carrier. Please try again.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (\RuntimeException $e) {
            logger()->warning('AutoShip race condition', ['package_id' => $package->id, 'error' => $e->getMessage()]);

            return PackageShippingResult::stateConflict($e->getMessage());
        } catch (\Exception $e) {
            logger()->error('AutoShip error', ['package_id' => $package->id, 'error' => $e->getMessage()]);
            $result = PackageShippingResult::failed('Auto Ship Error', 'An unexpected error occurred. Please try again.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        }
    }

    /**
     * Who the label is being bought from, for a message a packer reads.
     */
    private function sellerName(PackageShippingRequest $request): string
    {
        if ($request->blindOffer !== null) {
            return $request->blindOffer->sourceLabel;
        }

        return $request->selectedRate === null ? 'carrier' : $request->selectedRate->carrier;
    }

    /**
     * The blind offer as the server knows it, or the refusal to buy one.
     *
     * What arrives from the browser is a *claim* that this package was offered
     * something — a public Livewire property, hydrated from whatever the client
     * sent back, so its source and service code are the client's words. Taking
     * them at face value would let anyone who can reach the Ship page name a
     * selection that was never advertised: a service code outside the shipping
     * method, one that a hard-required special service had excluded, or a
     * source that never offered anything for this package at all.
     *
     * So the offers are derived again here, from the package, and the incoming
     * one is used only to pick from them by identifier. It is the same rule the
     * offer store follows for a quoted rate: what comes back says *which*
     * offer, and nothing more.
     *
     * Consent is checked first and separately because it deserves its own
     * message — the offer will also be absent for a client that has not opted
     * in, and "no longer available" would send an operator looking for the
     * wrong thing.
     *
     * @return BlindPurchaseOffer|PackageShippingResult the offer to buy, or the reason not to
     */
    private function resolveBlindOffer(Package $package, BlindPurchaseOffer $requested): BlindPurchaseOffer|PackageShippingResult
    {
        $package->loadMissing(['shipment.client', 'shipment.shippingMethod']);

        if (! $package->shipment?->client?->blind_purchase_enabled) {
            logger()->warning('Refused a blind purchase for a client that has not opted in', [
                'package_id' => $package->id,
                'source' => $requested->source,
                'client_id' => $package->shipment?->client_id,
            ]);

            return PackageShippingResult::offerUnavailable(
                'Blind Purchase Not Enabled',
                "{$requested->sourceLabel} buys postage without reporting a price or a service, so it is only available to clients that have opted in. "
                .'Enable it on the client, or choose a rate from a carrier account.',
            );
        }

        try {
            $advertised = $this->shippingRateService->blindPurchaseOffersFor($package);
        } catch (\Exception $e) {
            logger()->error('Could not re-derive blind purchase offers before buying', [
                'package_id' => $package->id,
                'source' => $requested->source,
                'error' => $e->getMessage(),
            ]);

            $advertised = collect();
        }

        $offer = $advertised->first(fn (BlindPurchaseOffer $candidate): bool => $candidate->id() === $requested->id());

        if ($offer) {
            return $offer;
        }

        // The most useful thing to say is usually why the source dropped out,
        // which rate shopping has just recorded — "cannot guarantee Signature
        // Required", rather than a bare "not available". Only exclusions naming
        // this source are relevant; another carrier's is somebody else's news.
        $exclusion = collect($this->shippingRateService->getExclusions())
            ->first(fn (array $entry): bool => $entry['carrier'] === $requested->source);

        logger()->warning('Refused a blind purchase that is not on offer for this package', [
            'package_id' => $package->id,
            'source' => $requested->source,
            'service_code' => $requested->serviceCode,
            'exclusion' => $exclusion['reason'] ?? null,
        ]);

        return PackageShippingResult::offerUnavailable(
            'Offer No Longer Available',
            $exclusion['reason'] ?? "{$requested->sourceLabel} is not offering this option for this package. Get rates again and choose from what comes back.",
        );
    }

    /**
     * Who to ask to buy this rate.
     *
     * An offer names its own seller, and that is the only correct answer for
     * channel postage: an Amazon rate carried by OnTrac has to be bought from
     * Amazon, while the carrier name on it — the carrier of record, which is
     * what the packer reads and what the package will record — would find a
     * direct adapter we do not have and hold no account with.
     *
     * A rate with no offer behind it is a direct-carrier rate quoted before
     * offers existed for that source, and dispatches by carrier name exactly as
     * it always did.
     */
    private function sellerFor(?ShippingOffer $offer, ?RateResponse $selectedRate): ?PostageOfferSource
    {
        if ($offer !== null) {
            return $this->postageSources->sellerFor($offer);
        }

        return $selectedRate === null
            ? null
            : $this->carrierRegistry->quotingAdapterFor($selectedRate->carrier);
    }

    /**
     * Refuse a rate nothing can honestly be asked to buy.
     *
     * Reached when the offer's source no longer sells postage — a data source
     * re-pointed at a database driver between quote and purchase, say. Falling
     * back to the carrier here is the one thing that must not happen: it would
     * buy the label on an account of ours that never quoted the price.
     */
    private function unsupportedDispatch(?ShippingOffer $offer, ?RateResponse $selectedRate): PackageShippingResult
    {
        logger()->error('An offer was selected that no source can be asked to buy', [
            'package_id' => $offer?->package_id,
            'offer' => $offer?->public_id,
            'postage_source' => $offer?->postage_source->value,
            'carrier' => $offer->carrier ?? $selectedRate?->carrier,
        ]);

        return PackageShippingResult::offerUnavailable(
            'Rate Not Purchasable',
            'Nothing configured can sell this rate any more — the account or channel it was quoted through '
            .'no longer offers postage. Get rates again and choose from what comes back.',
        );
    }

    /**
     * Account for every purchase this package has already spent an offer on.
     *
     * A consumed offer with no answer either way may have bought a label
     * upstream that we never recorded, so nothing else may be spent until it is
     * settled. Settling it is a question for the source, not for us: Amazon
     * recognizes a repeated purchase under the same idempotency key and hands
     * back the shipment it already made, so asking again is a lookup rather
     * than a second purchase — which is exactly what
     * {@see RecoversUnresolvedPurchase} claims of whoever implements it.
     *
     * Three ways out, in the order they are worth having: the label exists and
     * the package ships on it; the source is certain nothing was bought and the
     * offer resolves, freeing the package to be quoted again; or nobody can
     * say, and the package stays blocked. Only the last is what this used to do
     * unconditionally, and it is a state a single dropped connection could put
     * a parcel into permanently.
     */
    private function settleEarlierPurchases(Package $package, PackageShippingRequest $request): ?PackageShippingResult
    {
        $unresolved = $this->offerStore->awaitingPurchaseConfirmation($package);

        if ($unresolved->isEmpty()) {
            return null;
        }

        foreach ($unresolved as $offer) {
            if ($shipped = $this->recoverPurchase($package, $offer, $request)) {
                return $shipped;
            }
        }

        if (($stillUnresolved = $this->offerStore->awaitingPurchaseConfirmation($package))->isEmpty()) {
            return null;
        }

        logger()->warning('Refused to buy postage while an earlier purchase is unaccounted for', [
            'package_id' => $package->id,
            'offers' => $stillUnresolved->pluck('public_id')->all(),
        ]);

        return PackageShippingResult::offerUnavailable(
            'Earlier Purchase Unresolved',
            'A previous attempt to buy postage for this package did not report back, so a label may already exist. '
            .'Check the carrier or channel for a label on this package before buying again.',
        );
    }

    /**
     * Ask one source what became of one spent offer.
     *
     * Returns a shipped result only when the source produced the label it had
     * already been paid for. A definite "nothing was bought" resolves the offer
     * and returns null, so the caller carries on to the purchase the operator
     * actually asked for; anything else leaves the offer unresolved on purpose.
     */
    private function recoverPurchase(Package $package, ShippingOffer $offer, PackageShippingRequest $request): ?PackageShippingResult
    {
        $seller = $this->postageSources->sellerFor($offer);

        if (! $seller instanceof RecoversUnresolvedPurchase) {
            return null;
        }

        try {
            $response = $seller->recoverPurchase(ShipRequest::fromPackageAndRate(
                $package,
                $this->rateFromOffer($offer),
                $request->labelFormat,
                $request->labelDpi,
                $offer,
            ));
        } catch (\Exception $e) {
            logger()->error('Could not ask a postage source about an unresolved purchase', [
                'package_id' => $package->id,
                'offer' => $offer->public_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response === null) {
            return null;
        }

        if (! $response->success) {
            $this->offerStore->recordFailure(
                $offer,
                $response->errorMessage ?? 'The source reported no purchase against this offer.',
            );

            return null;
        }

        logger()->info('Recovered a label bought against an offer whose reply never arrived', [
            'package_id' => $package->id,
            'offer' => $offer->public_id,
        ]);

        $this->recordPurchaseAgainstOffer($offer, $response->trackingNumber);
        $package->markShipped($response, $response->postageSource, $request->userId);

        return PackageShippingResult::shipped($response, null, $package);
    }

    /**
     * Refuse an offer whose carrier account is no longer the one that would buy.
     *
     * An offer records which account quoted it, but the purchase cannot yet be
     * told to use that account: adapters resolve their own through
     * `ResolvesCarrierAccount`, from the package's location and client, and
     * `ShipRequest` has nowhere to name one. Usually the two agree. They stop
     * agreeing when scopes are edited between quote and purchase, or when rate
     * shopping quoted several accounts for one carrier and priority has since
     * moved — and then the label is bought on an account that never offered
     * that price.
     *
     * So this compares rather than plumbs, using the same resolution the
     * adapter will use rather than a copy of its rules, and refuses when they
     * diverge. Passing the account through `ShipRequest` is the real answer and
     * belongs with the interface work in `amazon-buy-shipping/03`.
     */
    private function accountNoLongerResolves(ShippingOffer $offer, Package $package): ?PackageShippingResult
    {
        if ($offer->carrier_account_id === null) {
            return null;
        }

        $carrierId = Carrier::where('name', $offer->carrier)->value('id');

        $resolved = $carrierId === null ? null : CarrierAccount::resolveForShipment(
            $carrierId,
            $package->location_id,
            $package->shipment?->client_id,
        )->first();

        if ($resolved?->id === $offer->carrier_account_id) {
            return null;
        }

        logger()->warning('Refused an offer whose carrier account is no longer the one that would be used', [
            'package_id' => $package->id,
            'offer' => $offer->public_id,
            'quoted_on_account' => $offer->carrier_account_id,
            'would_buy_on_account' => $resolved?->id,
        ]);

        return PackageShippingResult::offerUnavailable(
            'Carrier Account Changed',
            'This rate was quoted on a carrier account that is no longer the one this package would ship on. '
            .'Get rates again so the price matches the account that will be billed.',
        );
    }

    /**
     * The rate as the server knows it, for an offer the browser only named.
     *
     * Carrier, service, price and rate metadata all come off the stored offer;
     * the delivery commitment and transit time are carried through because they
     * are display text that cannot change what is bought.
     *
     * The metadata matters more than it looks. FedEx reads
     * `metadata['serviceType']` with no fallback and USPS reads `mailClass`,
     * `rateIndicator` and `processingCategory` the same way, so an offer that
     * dropped it would not buy the wrong label — it would fail to buy one at
     * all. It is restored from the offer rather than from the request for the
     * same reason the price is: the source stated it, and the browser does not
     * get to restate it.
     */
    private function rateFromOffer(ShippingOffer $offer, ?RateResponse $selected = null): RateResponse
    {
        return new RateResponse(
            carrier: $offer->carrier,
            serviceCode: $offer->service_code ?? '',
            serviceName: $offer->service_name ?? '',
            price: (float) ($offer->price ?? 0.0),
            deliveryCommitment: $selected?->deliveryCommitment,
            deliveryDate: $selected?->deliveryDate,
            transitTime: $selected?->transitTime,
            metadata: $offer->rate_metadata ?? [],
            priceUnknown: $offer->price === null,
            offerId: $offer->public_id,
        );
    }

    /**
     * Settle an offer the source declined.
     *
     * Only ever called on a response — a reply from the source is proof that
     * nothing was bought. An exception is not: a timeout leaves the offer
     * unresolved on purpose, so the next attempt is blocked until someone
     * establishes whether a label exists.
     */
    private function resolveOfferAsFailed(?ShippingOffer $offer, string $reason): void
    {
        if ($offer !== null) {
            $this->offerStore->recordFailure($offer, $reason);
        }
    }

    /**
     * Tie the offer to the purchase it paid for, if nothing else did first.
     *
     * A backstop, not the primary record: an adapter that talks to the source
     * should stamp the source's own identifier the moment the source confirms,
     * because the window where a purchase exists upstream and not here opens
     * before this line is reached. A tracking number is the weaker reference
     * that stops the offer looking unresolved when nobody set a better one.
     */
    private function recordPurchaseAgainstOffer(?ShippingOffer $offer, ?string $trackingNumber): void
    {
        if ($offer === null || $trackingNumber === null) {
            return;
        }

        $this->offerStore->recordPurchase($offer, $trackingNumber);
    }

    /**
     * Which rate a shipping rule pre-selected, in this list.
     *
     * The offer identifier wins when there is one, which is the collision
     * ADR-0002 decision 4 named: the same carrier and service code can now
     * arrive twice in one list — once quoted directly and once resold through a
     * channel — and they are different purchases at different prices. Carrier
     * plus service code remains the fallback, because a rule pre-selects a rate
     * that was resolved separately and may carry no offer of its own.
     *
     * @param  array<int, array<string, mixed>>  $rateOptions
     */
    private function selectedRateIndex(array $rateOptions, ?RateResponse $preSelectedRate): ?int
    {
        if (! $preSelectedRate) {
            return $rateOptions === [] ? null : 0;
        }

        foreach ($rateOptions as $key => $rateArray) {
            if ($preSelectedRate->offerId !== null && ($rateArray['offerId'] ?? null) === $preSelectedRate->offerId) {
                return $key;
            }
        }

        foreach ($rateOptions as $key => $rateArray) {
            if ($rateArray['carrier'] === $preSelectedRate->carrier && $rateArray['serviceCode'] === $preSelectedRate->serviceCode) {
                return $key;
            }
        }

        return $rateOptions === [] ? null : 0;
    }

    /**
     * Whether the packer has to be asked before our customs declaration is
     * scaled down to fit the box.
     *
     * Never for a blind purchase. The remedy behind this prompt is
     * {@see ShipRequest::withScaledCustomsWeights()}, which rewrites the
     * `customsItems` array — and a blind purchase does not send one: the seller
     * builds the declaration from its own catalogue, so the scaling is applied
     * to an array nobody reads. Asking anyway would put a confirmation in front
     * of an operator that changes nothing, and then fail the purchase for the
     * reason they thought they had just resolved. Shopify's version of this
     * condition runs the other way — its declaration must not exceed the total
     * weight we send — and is refused before the mutation instead, raising
     * {@see ShopifyDeclaredWeightException}.
     */
    private function requiresCustomsWeightOverride(ShipRequest $shipRequest, bool $overrideCustomsWeights): bool
    {
        if ($overrideCustomsWeights
            || $shipRequest->blindOffer !== null
            || ! $shipRequest->toAddress->requiresCustomsDeclaration()
            || empty($shipRequest->customsItems)) {
            return false;
        }

        $totalCustomsWeight = collect($shipRequest->customsItems)->sum(fn ($item): float => $item->weight * $item->quantity);

        return $totalCustomsWeight > $shipRequest->packageData->weight;
    }

    /**
     * What automation may buy for this package, and what it refused to.
     *
     * Every unattended path arrives here — auto-ship from Pack and Manual Ship,
     * and batch ship through `GenerateLabelJob` — and every one of them leaves
     * through {@see RateSelector::selectForAutomation()}, which is the single
     * place ADR-0003 decision 4 is enforced. A shipping rule's pre-selected rate
     * goes through it too rather than around it: a rule is automation choosing,
     * so a rule naming a service nobody approved must not buy it either.
     *
     * Deliberately not routed through {@see prepareRates()}. That builds the
     * attended view — where an unapproved service is *supposed* to appear, with
     * its price, for a packer to take responsibility for — and its
     * `selectedRateIndex` is a default highlight, not a decision. Reading a
     * choice off the attended list is how the two would come to mean the same
     * thing again.
     */
    private function selectedRateForAutoShip(Package $package): UnattendedRateSelection
    {
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod']);

        $ruleResult = $this->ruleEvaluator->evaluate($package->shipment, $package);
        $clientId = $package->shipment?->client_id;

        // Only a source that quotes can resolve a pre-selected rate. Anything
        // else falls through to rate shopping rather than being asked to
        // invent one — `RuleEvaluator` already declines to pre-select a blind
        // purchase, and this is the same refusal one layer down.
        $adapter = $ruleResult->hasPreSelectedRate()
            ? $this->carrierRegistry->quotingAdapterFor($ruleResult->preSelectedRate->carrier)
            : null;

        if ($adapter) {
            return $this->rateSelector->selectForAutomation(
                collect([$adapter->resolvePreSelectedRate($ruleResult->preSelectedRate, $package)]),
                deadline: null,
                clientId: $clientId,
            );
        }

        $rates = $this->shippingRateService->getShippingRates($package->id);

        if ($ruleResult->shouldFilterRates()) {
            $rates = $rates->reject(
                fn (RateResponse $rate): bool => in_array($rate->serviceCode, $ruleResult->excludedServiceCodes, true)
            );
        }

        return $this->rateSelector->selectForAutomation(
            $rates,
            $package->shipment->getDeliverByDate(),
            $clientId,
        );
    }

    /**
     * Why nothing was bought, in words an operator can act on.
     *
     * "No shipping rates available" is true of an empty rate list and false of
     * a package that was quoted three services none of which an administrator
     * has approved — and it sends whoever reads it to the carrier rather than
     * to the approval page. A batch of several hundred is exactly where that
     * misdirection costs the most, so the refusal names itself.
     */
    private function nothingToBuyUnattended(Package $package, UnattendedRateSelection $selection): PackageShippingResult
    {
        if (! $selection->withheldAnything()) {
            return PackageShippingResult::failed('Shipping Error', 'No shipping rates available for this package.');
        }

        logger()->warning('Withheld a rate from automated purchase because nobody has approved the service', [
            'package_id' => $package->id,
            'client_id' => $package->shipment?->client_id,
            'withheld' => $selection->withheldForLog(),
        ]);

        return PackageShippingResult::failed(
            'No Approved Rates',
            'This package was quoted, but no service it was offered is approved for automated purchase: '
            .$selection->withheldSummary().'. '
            .'Approve it on Map Carrier Services, or ship this package from the Ship page, where a person chooses the rate.',
        );
    }

    private function cleanupPackage(Package $package, PackageAutoShippingRequest $request, PackageShippingResult $result): void
    {
        if (! $request->cleanupOnFailure || $result->success || $result->leavePackageIntact) {
            return;
        }

        if ($package->exists && $package->status !== PackageStatus::Shipped) {
            $package->packageItems()->delete();
            $package->delete();
        }
    }
}
