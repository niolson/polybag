<?php

namespace App\Services\PackageShipping;

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\Contracts\PostageOfferSource;
use App\Contracts\RecoversUnresolvedPurchase;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\BuyShippingBenefits;
use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\OfferRequirements;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\RuleEvaluationResult;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Shipping\UnattendedRateSelection;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\Role;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Exceptions\Carriers\UnreadablePurchaseResponseException;
use App\Exceptions\MissingDeclaredValueException;
use App\Exceptions\MissingProductIdentifierException;
use App\Exceptions\PackageDraftIncompleteException;
use App\Exceptions\ShopifyDeclaredWeightException;
use App\Exceptions\ZeroValueCustomsItemException;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingOffer;
use App\Models\SpecialService;
use App\Models\User;
use App\Notifications\LabelNotRecorded;
use App\Services\Carriers\CarrierRegistry;
use App\Services\InactiveCatalog;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\PostageSourceDispatcher;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\RateQuoteLogger;
use App\Services\RateSelector;
use App\Services\RuleEvaluator;
use App\Services\ShippingRateService;
use App\Services\SpecialServiceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\ServerException;
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
        private readonly PostageSourceResolver $postageSourceResolver,
        private readonly PackageDraftWorkflow $packageDrafts,
    ) {}

    public function prepareRates(Package $package): PackageShippingOptions
    {
        $package->loadMissing(['shipment.shippingMethod']);

        $rates = $this->shippingRateService->getShippingRates($package->id);

        $exclusions = $this->shippingRateService->getExclusions();

        $ruleResult = $this->ruleEvaluator->evaluate($package->shipment, $package);
        if ($ruleResult->shouldFilterRates()) {
            $rates = $rates->reject(fn (RateResponse $rate): bool => $ruleResult->excludes($rate));
        }

        $deadline = $package->shipment->getDeliverByDate();
        $classified = $this->rateSelector->classify($rates, $deadline);
        $inactive = InactiveCatalog::among($rates);

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

            // Amazon's OTDR protection, beside the lateness marked above: the
            // two are separate facts and can disagree (`amazon-buy-shipping/16`).
            if ($benefits = BuyShippingBenefits::fromRateMetadata($classifiedRate->rate->metadata)) {
                $rateArray['otdrProtection'] = [
                    'protected' => $benefits->isOtdrProtected(),
                    'reasons' => $benefits->otdrExclusionReasons(),
                ];
            }

            // Shown, so the packer sees why an offer cannot be chosen, and
            // unselectable: nothing buys a deactivated service or carrier.
            if (($reason = $inactive->reasonFor($classifiedRate->rate)) !== null) {
                $rateArray['inactive'] = $reason;
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
            selectedRateIndex: $this->selectedRateIndex($classified, $ruleResult, $inactive),
            // Alongside the rates, never among them, and never pre-selected on
            // the attended page: a person must choose and confirm it here.
            blindPurchaseOffers: $this->shippingRateService->getBlindPurchaseOffers($ruleResult->excludesBlindOffer(...))
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
     * Buy what the Ship page chose.
     *
     * The one entry point the browser reaches, and so the one that trusts
     * nothing it is handed: a quoted rate must name an offer, because the
     * offer row is the server's copy of the price, service and metadata and a
     * rate without one is a description the browser could have written
     * (`postage-source-split/14`). {@see autoShip()} is the other side of
     * that boundary — its rates are built server-side and never round-trip —
     * so trust is decided by entry point rather than by a flag on the request.
     *
     * A blind offer carries no rate and is revalidated against the server's
     * own list in {@see resolveBlindOffer()}, so it is not subject to this.
     */
    public function ship(Package $package, PackageShippingRequest $request): PackageShippingResult
    {
        if ($request->selectedRate !== null && $request->selectedRate->offerId === null) {
            logger()->warning('Refused a rate from the Ship page that names no offer', [
                'package_id' => $package->id,
                'carrier' => $request->selectedRate->carrier,
                'service_code' => $request->selectedRate->serviceCode,
            ]);

            return PackageShippingResult::offerUnavailable(
                'Rate Unavailable',
                'This rate is not one on file for this package. Get rates again and choose one.',
                requiresRequote: true,
            );
        }

        if ($refusal = $this->notReadyToShip($package)) {
            return $refusal;
        }

        return $this->purchase($package, $request);
    }

    /**
     * Why this package may not be bought for yet, or null when it may.
     *
     * The Pack page checks this before sending anyone to buy, but the Ship
     * page is reachable by URL for any unshipped package — one opened on the
     * Pack page and left before a box or an item was scanned among them. So
     * the purchase checks for itself, whoever sent it.
     */
    private function notReadyToShip(Package $package): ?PackageShippingResult
    {
        // Refused later, with its own message, where the purchase re-reads
        // it. Read here too: a stale page's package can still say unshipped.
        if (Package::whereKey($package->id)->where('status', PackageStatus::Shipped)->exists()) {
            return null;
        }

        if ($package->shipment?->isBlockedByPicking()) {
            return PackageShippingResult::notReady('This shipment must be picked before it can be shipped.');
        }

        try {
            $this->packageDrafts->assertPackageReadyToShip($package);
        } catch (PackageDraftIncompleteException $e) {
            return PackageShippingResult::notReady($e->getMessage());
        }

        return null;
    }

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
    private function purchase(Package $package, PackageShippingRequest $request): PackageShippingResult
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
        // Read from the database, not the instance: the Ship page keeps its
        // package loaded from before the purchase, and stays open when the
        // label it bought could not be printed. A second Ship on that page,
        // with a different rate selected, would otherwise pay for a second
        // label on a package that already has one.
        if (($stored = Package::query()->find($package->id)) && $stored->status === PackageStatus::Shipped) {
            return PackageShippingResult::stateConflict(
                'This package already has a label'
                .(filled($stored->tracking_number) ? " ({$stored->tracking_number})" : '')
                .'. Reprint it, or void it first, from the Packages page.'
            );
        }

        // Nothing is bought for a shipment with no shipping method, whatever a
        // stale page or an old Offer says (`carrier-catalog-reset/16`). Read
        // from the database for the same reason as the check above.
        if (Shipment::query()->whereKey($package->shipment_id)->value('shipping_method_id') === null) {
            return PackageShippingResult::shippingMethodRequired();
        }

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
        // buy something else. Every rate the Ship page lists carries one now,
        // direct or resold; ship() refuses one that does not.
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

        // The rate's packaging requirement was checked once at rate shopping;
        // it is checked again here, against the Package as it is now, because
        // this is the only check `04` cannot ship without (ADR-0005 decision 4).
        if ($selectedRate !== null && ($refused = $this->packagingRefused($package, $offer, $selectedRate)) !== null) {
            return $refused;
        }

        // Nothing buys a deactivated service or carrier, a packer included.
        // The Ship page shows such an offer unselectable; this is the check a
        // stale page, or one deactivated since it was quoted, cannot pass.
        if ($selectedRate !== null && ($reason = InactiveCatalog::among(collect([$selectedRate]))->reasonFor($selectedRate)) !== null) {
            logger()->info('Refused a purchase for a deactivated service or carrier', [
                'package_id' => $package->id,
                'offer' => $offer?->public_id,
                'carrier_service_id' => $selectedRate->carrierServiceId,
                'carrier_id' => $selectedRate->carrierId,
            ]);

            return PackageShippingResult::failed(
                'Service Inactive',
                "{$reason} Choose another rate, or reactivate it under Carriers or Carrier Services.",
            );
        }

        // Marked through the offer, which points at the row the quote log
        // wrote for exactly this rate. Nothing to mark for a blind purchase,
        // which logged no quote (`postage-source-split/17`).
        if ($offer !== null) {
            $this->rateQuoteLogger->markSelected($offer);
        }

        $adapter = null;

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
            // The workstation is checked first: a purchase that returns a
            // customs document this workstation cannot print is refused
            // outright, before any question about the data is asked.
            if (($refused = $this->reportPrinterRefused($adapter, $shipRequest, $request)) !== null) {
                return $refused;
            }

            // A zero-value customs line is refused next, and outright: there
            // is no override for it, so asking the operator to confirm a weight
            // and then refusing anyway would be the worse order.
            if (($zeroValued = $shipRequest->zeroValueCustomsItems()) !== []) {
                throw new ZeroValueCustomsItemException($zeroValued);
            }

            // Refused just as outright, and for the same reason: a fix made
            // once on the product, not per label, so there is no override.
            if (($unidentified = $shipRequest->customsItemsMissingProductIdentifiers()) !== []) {
                throw new MissingProductIdentifierException($unidentified);
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

            // The catalog service comes off the server's copy of the rate:
            // the offer for a Ship-page purchase, the rate service or the
            // rule for automation. A blind purchase records none.
            $unrecorded = $this->recordBoughtLabel(
                $package,
                $offer,
                $response,
                $request,
                $blindOffer === null ? $selectedRate?->carrierServiceId : null,
            );

            return $unrecorded ?? PackageShippingResult::shipped($response, $selectedRate, $package);
        } catch (MissingDeclaredValueException $e) {
            return PackageShippingResult::failed('Declared Value Required', $e->getMessage());
        } catch (ZeroValueCustomsItemException $e) {
            return PackageShippingResult::failed('Customs Value Required', $e->getMessage());
        } catch (MissingProductIdentifierException $e) {
            return PackageShippingResult::failed('Product Identifier Required', $e->getMessage());
        } catch (ShopifyDeclaredWeightException $e) {
            // Nothing was bought and nothing was claimed — the seller's own
            // declaration would have made the purchase fail, and it was
            // withheld before the mutation. The packer is shown both numbers
            // and may insist; only they can, since the remedy is a catalogue
            // PolyBag does not own.
            return PackageShippingResult::declaredWeightOverrideRequired($e->getMessage());
        } catch (UnreadablePurchaseResponseException $e) {
            // The carrier answered 2xx — the label exists and is paid for — but
            // the adapter could not read the reply. Like a timeout, nothing
            // settles the offer: the next attempt asks the carrier for the same
            // label before buying another (`project-review/11`). The adapter
            // has already logged the raw reply.
            if ($offer !== null && $e->trackingNumber !== null) {
                $this->offerStore->recordReportedTrackingNumber($offer, $e->trackingNumber);
            }

            logger()->error('Carrier accepted a purchase but its reply could not be read', [
                'carrier' => $e->carrier,
                'package_id' => $package->id,
                'offer' => $offer?->public_id,
                'tracking_number' => $e->trackingNumber,
                'error' => $e->getMessage(),
            ]);

            return PackageShippingResult::failed(
                'Carrier Reply Unreadable',
                "{$e->carrier} accepted the purchase but its reply could not be read"
                .($e->trackingNumber !== null ? " (tracking number {$e->trackingNumber})" : '')
                .'. Try again to retrieve the same label; a second label will not be bought.',
            );
        } catch (RequestTimeOutException|FatalRequestException|ServerException) {
            // No usable reply either way — a 5xx included, since the carrier
            // may have created the label before failing. Nothing here settles
            // the offer: the adapters that can be asked let these through on
            // purpose, so that the next attempt asks the source before buying
            // again.
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
        } catch (QueryException $e) {
            // Before the carrier sold anything: everything after the sale is
            // caught in recordBoughtLabel(). A database error is not a race,
            // and its SQL is no message for a packer.
            logger()->error('Database error while buying postage', [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return PackageShippingResult::unexpectedError();
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
        if ($refusal = $this->notReadyToShip($package)) {
            return $refusal;
        }

        // Before any quote: a shipment with no method is not rated, and the
        // remedy is a person choosing one on the Ship page.
        if ($package->shipment()->value('shipping_method_id') === null) {
            return PackageShippingResult::shippingMethodRequired();
        }

        try {
            $selection = $this->selectedRateForAutoShip($package);
            $selectedRate = $selection->rate;
            $blindOffer = $selection->blindOffer;

            if (! $selectedRate && ! $blindOffer) {
                $result = $this->nothingToBuyUnattended($package, $selection);
                $this->cleanupPackage($package, $request, $result);

                return $result;
            }

            // Every rate selected here was rate-shopped, a rule's choice
            // included, so it names the Offer that quote issued: the claim,
            // the unresolved state after a timeout, and the handle the carrier
            // is asked about all live on it (`project-review/02`, `/18`).

            // Through purchase() rather than ship(): this is the trusted side
            // of the boundary ship() enforces.
            $result = $this->purchase(
                $package,
                new PackageShippingRequest(
                    selectedRate: $selectedRate,
                    labelFormat: $request->labelFormat,
                    labelDpi: $request->labelDpi,
                    requireCustomsWeightOverride: false,
                    userId: $request->userId,
                    blindOffer: $blindOffer,
                    hasReportPrinter: $request->hasReportPrinter,
                ),
            );

            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (RequestTimeOutException|FatalRequestException|ServerException) {
            logger()->error('AutoShip timeout', ['package_id' => $package->id]);
            $result = PackageShippingResult::failed('Carrier Timeout', 'The carrier API is not responding. Please try again in a few moments.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (RequestException $e) {
            logger()->error('AutoShip carrier error', ['package_id' => $package->id, 'error' => $e->getMessage()]);
            $result = PackageShippingResult::failed('Carrier Error', 'Unable to connect to the carrier. Please try again.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (MissingDeclaredValueException $e) {
            // Thrown by rate shopping, before anything was offered or bought:
            // the same refusal the Ship page shows instead of rates, not an
            // unexpected error. A rule's choice is rate-shopped too
            // (`project-review/18`), so it reaches here as well.
            $result = PackageShippingResult::failed('Declared Value Required', $e->getMessage());
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (QueryException $e) {
            logger()->error('AutoShip database error', ['package_id' => $package->id, 'error' => $e->getMessage()]);

            return PackageShippingResult::unexpectedError();
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
     * Tell the buyer and every active manager and admin, in the bell rather
     * than only a toast, so an unrecorded label is not forgotten. Best effort:
     * the database that refused the label may refuse this too, and the error
     * log already carries the tracking number.
     */
    private function notifyLabelNotRecorded(Package $package, string $seller, ?string $trackingNumber, bool $recoverable, ?int $userId): void
    {
        try {
            User::query()
                ->where('active', true)
                ->where(fn ($query) => $query
                    ->whereIn('role', [Role::Manager->value, Role::Admin->value])
                    ->when($userId !== null, fn ($query) => $query->orWhere('id', $userId)))
                ->get()
                ->each(fn (User $user) => $user->notify(new LabelNotRecorded($package, $seller, $trackingNumber, $recoverable)));
        } catch (\Throwable $e) {
            logger()->error('Could not send the label-not-recorded notification', [
                'package_id' => $package->id,
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);
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
     * message — the offer will also be absent for a connection that does not
     * sell postage, and "no longer available" would send an operator looking
     * for the wrong thing.
     *
     * @return BlindPurchaseOffer|PackageShippingResult the offer to buy, or the reason not to
     */
    private function resolveBlindOffer(Package $package, BlindPurchaseOffer $requested): BlindPurchaseOffer|PackageShippingResult
    {
        $package->loadMissing(['shipment.shippingMethod']);

        $connection = $this->postageSourceResolver->channelSourceFor($package);

        if ($connection && ! $connection->postageSetting()->sells()) {
            logger()->warning('Refused a blind purchase from a connection that does not sell postage', [
                'package_id' => $package->id,
                'source' => $requested->source,
                'data_source_id' => $connection->id,
            ]);

            return PackageShippingResult::offerUnavailable(
                'Connection Does Not Sell Postage',
                "{$requested->sourceLabel} buys postage without reporting a price or a service, and the connection \"{$connection->name}\" is set not to sell postage. "
                .'Change its postage setting under Integrations → Connections, or choose a rate from a carrier account.',
            );
        }

        try {
            $advertised = $this->shippingRateService->blindPurchaseOffersFor($package);

            $ruleResult = $this->ruleEvaluator->evaluate($package->shipment, $package);
            $advertised = $advertised->reject($ruleResult->excludesBlindOffer(...));
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
            ->first(fn (array $entry): bool => $entry['source'] === $requested->source);

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
     * Every rate reaching a purchase carries an offer now — ship() refuses one
     * that does not, and autoShip() buys only rates rate shopping quoted.
     * The carrier-name fallback is for a caller that bypasses both, and
     * dispatches exactly as a direct offer would.
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
     * back the shipment it already made, USPS reprints by the
     * `X-Idempotency-Key` the purchase carried, and UPS finds the shipment by
     * the reference it was created with — each a lookup rather than a second
     * purchase, which is exactly what {@see RecoversUnresolvedPurchase}
     * claims of whoever implements it.
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
        // Both kinds of earlier purchase: the unconfirmed, and the confirmed
        // whose Label was never saved. The source is asked about either before
        // anything new is bought.
        $unresolved = $this->unaccountedPurchases($package);

        if ($unresolved->isEmpty()) {
            return null;
        }

        foreach ($unresolved as $offer) {
            if ($shipped = $this->recoverPurchase($package, $offer, $request)) {
                return $shipped;
            }
        }

        if (($stillUnresolved = $this->unaccountedPurchases($package))->isEmpty()) {
            return null;
        }

        logger()->warning('Refused to buy postage while an earlier purchase is unaccounted for', [
            'package_id' => $package->id,
            'offers' => $stillUnresolved->pluck('public_id')->all(),
        ]);

        $reported = $stillUnresolved
            ->map(fn (ShippingOffer $offer): mixed => $offer->purchase_context[OfferStore::REPORTED_TRACKING_NUMBER] ?? null)
            ->filter(fn (mixed $trackingNumber): bool => is_string($trackingNumber))
            ->implode(', ');

        return PackageShippingResult::offerUnavailable(
            'Earlier Purchase Unresolved',
            'A previous attempt to buy postage for this package did not report back, so a label may already exist. '
            .($reported !== '' ? "The carrier reported tracking number {$reported}. " : '')
            .'Check the carrier or channel for a label on this package before buying again.',
        );
    }

    /**
     * @return Collection<int, ShippingOffer>
     */
    private function unaccountedPurchases(Package $package): Collection
    {
        return $this->offerStore->awaitingPurchaseConfirmation($package)
            ->concat($this->offerStore->boughtButUnrecorded($package))
            ->sortBy('consumed_at')
            ->values();
    }

    /**
     * Ask one source what became of one spent offer.
     *
     * Returns a shipped result only when the source produced the label it had
     * already been paid for. A definite "nothing was bought" resolves the offer
     * and returns null, so the caller carries on to the purchase the operator
     * actually asked for; anything else leaves the offer unresolved on purpose.
     *
     * A direct carrier that cannot be asked gets the second answer without
     * the question. "Spent, nothing confirmed" is only a useful state when a
     * later attempt can ask what happened, which is what
     * {@see RecoversUnresolvedPurchase} promises. USPS and UPS implement it
     * (`postage-source-split/18`); FedEx does not and cannot — its
     * `customerTransactionId` is echoed, not deduplicated, and the Ship API
     * has no lookup — but FedEx also never bills a label that was created and
     * not tendered, so for it the state can never resolve and costs nothing.
     * Leaving it would strand the package behind a refusal nobody can clear,
     * over a purchase that in practice was a worker killed between the claim
     * and the reply — the FedEx adapter itself turns a timeout into a
     * decline. Settling it keeps the package buyable, and the claim has
     * already stopped the double-click.
     *
     * Only a direct carrier. An offer whose channel can no longer be found —
     * a data source deleted or re-pointed since the quote — is not settled:
     * the channel may well have sold the label, and someone restoring the
     * source is how it gets asked. Until then it blocks, and `16` is the
     * by-hand way out.
     */
    private function recoverPurchase(Package $package, ShippingOffer $offer, PackageShippingRequest $request): ?PackageShippingResult
    {
        $seller = $this->postageSources->sellerFor($offer);

        if ($offer->postage_source === PostageSource::CarrierAccount && ! $seller instanceof RecoversUnresolvedPurchase) {
            logger()->warning('Settled an unresolved purchase on a source that cannot be asked what became of it', [
                'package_id' => $package->id,
                'offer' => $offer->public_id,
                'carrier' => $offer->carrier,
            ]);

            $this->offerStore->recordFailure(
                $offer,
                'No reply was recorded and the carrier cannot be asked what happened; settled on the next attempt',
            );

            return null;
        }

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

            $this->offerStore->recordUnansweredRecovery($offer);

            return null;
        }

        if ($response === null) {
            // Asked, and nobody could say. Stamped so the purge command can
            // tell a real unknown from an offer nobody has retried yet.
            $this->offerStore->recordUnansweredRecovery($offer);

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

        return $this->recordBoughtLabel($package, $offer, $response, $request, $offer->carrier_service_id)
            ?? PackageShippingResult::shipped($response, null, $package);
    }

    /**
     * Record a label the source has already sold: stamp the offer and ship the
     * package, together or not at all.
     *
     * Stamping the offer first and shipping second left a window in which the
     * offer read as settled while the package had no label — so the next
     * attempt found nothing unresolved and bought a second label. In one
     * transaction a failure leaves the offer unresolved, and the next attempt
     * asks the source for this label before buying another.
     *
     * Anything thrown here is thrown after the money was spent, so it is never
     * reported as a race or a generic error: the packer is told a label exists
     * and which one, and the tracking number is logged and kept on the offer
     * for a person to find if recovery cannot.
     */
    private function recordBoughtLabel(
        Package $package,
        ?ShippingOffer $offer,
        ShipResponse $response,
        PackageShippingRequest $request,
        ?int $carrierServiceId,
    ): ?PackageShippingResult {
        try {
            DB::transaction(function () use ($package, $offer, $response, $request, $carrierServiceId): void {
                $this->recordPurchaseAgainstOffer($offer, $response->trackingNumber);
                $package->markShipped($response, $response->postageSource, $request->userId, $carrierServiceId);
            });

            return null;
        } catch (\Throwable $e) {
            if ($offer !== null && $response->trackingNumber !== null) {
                try {
                    $this->offerStore->recordReportedTrackingNumber($offer, $response->trackingNumber);
                } catch (\Throwable) {
                    // The log line below still carries the tracking number.
                }
            }

            $seller = $this->sellerOfBoughtLabel($response, $request);
            $recoverable = $this->sourceCanBeAskedAbout($offer);

            logger()->error('Bought a label but could not record it', [
                'seller' => $seller,
                'carrier' => $response->carrier,
                'package_id' => $package->id,
                'offer' => $offer?->public_id,
                'tracking_number' => $response->trackingNumber,
                'recoverable' => $recoverable,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->notifyLabelNotRecorded($package, $seller, $response->trackingNumber, $recoverable, $request->userId);

            return PackageShippingResult::labelNotRecorded($seller, $response->trackingNumber, $recoverable);
        }
    }

    /**
     * Who sold a label, named by where its postage was bought rather than who
     * carries it: USPS postage bought through Amazon was sold, and must be
     * voided, by Amazon.
     */
    private function sellerOfBoughtLabel(ShipResponse $response, PackageShippingRequest $request): string
    {
        if ($request->blindOffer !== null) {
            return $request->blindOffer->sourceLabel;
        }

        if ($response->postageSource === PostageSource::PostageDataSource) {
            // Best effort: the database may be what just failed.
            $connection = rescue(
                fn (): ?DataSource => DataSource::query()->find($response->postageDataSourceId),
                null,
                report: false,
            );

            return match (true) {
                $connection === null => 'The sales channel',
                $connection->isAmazon() => "Amazon (connection \"{$connection->name}\")",
                default => "The connection \"{$connection->name}\"",
            };
        }

        return filled($response->carrier) ? $response->carrier : $this->sellerName($request);
    }

    /**
     * Whether the next attempt can ask the source for this label rather than
     * buy another — the only case in which "try again" is safe advice.
     */
    private function sourceCanBeAskedAbout(?ShippingOffer $offer): bool
    {
        if ($offer === null) {
            return false;
        }

        return rescue(
            fn (): bool => $this->postageSources->sellerFor($offer) instanceof RecoversUnresolvedPurchase,
            false,
            report: false,
        );
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
            return $this->accountNowBillsSomeoneElse($offer, $resolved, $package);
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
     * Refuse an offer whose account row is the same but whose payer is not.
     *
     * The id check above says the same `CarrierAccount` would buy. It does
     * not say the same account would be billed: the adapters read the account
     * number, EPS account or CRID fresh from the row's credentials at
     * purchase, and those are editable. The offer recorded a digest of that
     * billing identity — and of nothing secret, so a refreshed OAuth token or
     * a rotated client secret leaves it alone — and the purchase compares.
     * An offer that recorded none, issued before the digest existed or
     * resold through a channel, is not judged by it.
     */
    private function accountNowBillsSomeoneElse(ShippingOffer $offer, CarrierAccount $resolved, Package $package): ?PackageShippingResult
    {
        if ($offer->carrier_account_fingerprint === null
            || $resolved->fingerprint() === $offer->carrier_account_fingerprint) {
            return null;
        }

        logger()->warning('Refused an offer whose carrier account credentials changed after the quote', [
            'package_id' => $package->id,
            'offer' => $offer->public_id,
            'carrier_account_id' => $resolved->id,
        ]);

        return PackageShippingResult::offerUnavailable(
            'Carrier Account Changed',
            'The carrier account this rate was quoted on has had its account details changed since. '
            .'Get rates again so the price matches the account that will be billed.',
        );
    }

    /**
     * Refuse a purchase that returns a customs document this workstation has
     * nowhere to print.
     *
     * The gate `shopify-shipping-carrier/07` decided on (constraint 3), read
     * off the seller's own answer through
     * {@see PostageOfferSource::customsDocumentDelivery()} rather than off
     * {@see AddressData::requiresCustomsDeclaration()}: the address says a
     * declaration exists, the seller says whether it comes back on paper. USPS
     * fuses its CP72 into the label and is never refused; UPS, Shopify and an
     * Amazon offering that declares a `CUSTOM_FORM` return a separate Letter
     * document and are, when no report printer is configured.
     *
     * Only ever asked when the workstation has no report printer and the lane
     * crosses a customs zone: with a report printer every answer prints, and
     * a lane inside one zone carries no declaration for any seller — the same
     * pair test {@see requiresCustomsWeightOverride()} gates on. The addresses
     * and rate come off the ship request the seller is about to be handed, so
     * the lane and the offering checked are the ones bought.
     */
    private function reportPrinterRefused(PostageOfferSource $seller, ShipRequest $shipRequest, PackageShippingRequest $request): ?PackageShippingResult
    {
        if ($request->hasReportPrinter || $shipRequest->fromAddress->sharesCustomsZoneWith($shipRequest->toAddress)) {
            return null;
        }

        $delivery = $seller->customsDocumentDelivery($shipRequest->fromAddress, $shipRequest->toAddress, $shipRequest->selectedRate);

        if (! $delivery->needsReportPrinter()) {
            return null;
        }

        logger()->info('Refused a purchase that returns a customs document with no report printer configured', [
            'package_id' => $shipRequest->packageId,
            'seller' => $seller->getCarrierName(),
            'destination_country' => $shipRequest->toAddress->country,
        ]);

        return PackageShippingResult::reportPrinterRequired(
            'This shipment needs a customs form, and '.$seller->getCarrierName().' returns it as a separate document '
            .'that prints on the document printer. Choose a document printer in Device Settings on this workstation, '
            .'then ship again.',
        );
    }

    /**
     * Refuse a rate that is not valid in the packaging this Package is in.
     *
     * The requirement is asked of the adapter that will buy, through
     * {@see CarrierAdapterInterface::packagingRequirementFor()}, and not read
     * off {@see RateResponse::$packagingRequirement}: for a direct-carrier rate
     * that field was rebuilt from browser state, and a check that trusted it
     * would be one the browser could switch off. The adapter classifies from
     * the same metadata it will send — which, for an offer-backed rate, the
     * server has already rebuilt from the stored offer.
     *
     * The packaging is read through {@see PackageData::fromPackage()}, the
     * same fact every adapter is handed, rather than off a column of its own.
     *
     * A seller that is not a quoting adapter — none, or a source that no
     * longer sells postage — is left to {@see unsupportedDispatch()}.
     */
    private function packagingRefused(Package $package, ?ShippingOffer $offer, RateResponse $rate): ?PackageShippingResult
    {
        $seller = $this->sellerFor($offer, $rate);

        if (! $seller instanceof CarrierAdapterInterface) {
            return null;
        }

        $packaging = PackageData::fromPackage($package)->carrierPackaging;

        try {
            $required = $seller->packagingRequirementFor($rate);
        } catch (UnclassifiablePackagingException $e) {
            logger()->warning('Refused a rate whose packaging the adapter could not classify', [
                'package_id' => $package->id,
                'carrier' => $rate->carrier,
                'service_code' => $rate->serviceCode,
                'metadata' => $rate->metadata,
                'reason' => $e->getMessage(),
            ]);

            return PackageShippingResult::packagingMismatch(
                'This rate names a '.$rate->carrier.' product PolyBag cannot match to a packaging. '
                .'Get rates again and choose one quoted for this package.',
            );
        }

        if ($required->accepts($packaging)) {
            return null;
        }

        logger()->warning('Refused a rate whose packaging requirement the package does not meet', [
            'package_id' => $package->id,
            'carrier' => $rate->carrier,
            'service_code' => $rate->serviceCode,
            'required' => $required->toArray(),
            'package_packaging' => $packaging?->value,
        ]);

        return PackageShippingResult::packagingMismatch(
            "This rate is only valid in {$required->describe()}, and this package is in "
            .($packaging?->getLabel() ?? 'your own packaging')
            .'. Get rates again and choose one quoted for this packaging.',
        );
    }

    /**
     * The rate as the server knows it, for an offer the browser only named.
     *
     * Carrier, service, price and rate metadata all come off the stored offer,
     * and so do the catalog service and carrier ids the Label records and the
     * purchase is dated by; the delivery commitment and transit time are
     * carried through because they are display text that cannot change what
     * is bought.
     *
     * The metadata matters more than it looks. FedEx reads
     * `metadata['serviceType']` with no fallback and USPS reads `mailClass`,
     * `rateIndicator` and `processingCategory` the same way, so an offer that
     * dropped it would not buy the wrong label — it would fail to buy one at
     * all. It is restored from the offer rather than from the request for the
     * same reason the price is: the source stated it, and the browser does not
     * get to restate it. The packaging requirement travels the same way, under
     * {@see PackagingRequirement::RATE_METADATA_KEY}, so that the purchase
     * re-check runs on what the source said and not on what came back.
     */
    private function rateFromOffer(ShippingOffer $offer, ?RateResponse $selected = null): RateResponse
    {
        $metadata = $offer->rate_metadata ?? [];

        return new RateResponse(
            carrier: $offer->carrier,
            serviceCode: $offer->service_code ?? '',
            serviceName: $offer->service_name ?? '',
            price: (float) ($offer->price ?? 0.0),
            deliveryCommitment: $selected?->deliveryCommitment,
            deliveryDate: $selected?->deliveryDate,
            transitTime: $selected?->transitTime,
            metadata: $metadata,
            priceUnknown: $offer->price === null,
            offerId: $offer->public_id,
            packagingRequirement: PackagingRequirement::fromRateMetadata($metadata),
            carrierAccountId: $offer->carrier_account_id,
            carrierServiceId: $offer->carrier_service_id,
            carrierId: $offer->carrier_id,
        );
    }

    /**
     * Settle an offer the source declined.
     *
     * Only ever called on a response — a reply from the source is proof that
     * nothing was bought. An exception is not: a timeout leaves the offer
     * unresolved on purpose, so the next attempt asks the source what
     * happened before anything else is bought — see {@see recoverPurchase()},
     * including what it does for a source that cannot be asked.
     *
     * Which adapters let a transport error reach the caller follows from
     * which can be asked: USPS, UPS and Amazon let it through, so their offer
     * stays unresolved and {@see recoverPurchase()} asks; FedEx catches it
     * inside `createShipment()` and answers with a failed `ShipResponse`, so
     * its timeout arrives as a decline and is settled here like one.
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
     * The rate the Ship page highlights by default.
     *
     * A rule's choice, matched on its source and catalog service rather than
     * on carrier and code: a mapped Amazon offer carries the mapped carrier
     * and code, so *Direct, UPS Ground* would otherwise highlight Amazon's.
     * The keys of the rate options are those of the classified list, so its
     * first match is the one the packer sees offered first. Without a rule's
     * choice, the first rate. Never a rate for a deactivated service or
     * carrier, which the page shows but cannot select; with nothing else,
     * nothing is highlighted.
     *
     * @param  Collection<int, ClassifiedRate>  $classified
     */
    private function selectedRateIndex(Collection $classified, RuleEvaluationResult $ruleResult, InactiveCatalog $inactive): ?int
    {
        $selectable = $classified->reject(fn (ClassifiedRate $cr): bool => $inactive->includes($cr->rate));

        $key = $selectable->search(fn (ClassifiedRate $cr): bool => $ruleResult->isPreSelected($cr->rate));

        if ($key !== false) {
            return $key;
        }

        return $selectable->isEmpty() ? null : $selectable->keys()->first();
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
            || $shipRequest->fromAddress->sharesCustomsZoneWith($shipRequest->toAddress)
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
     * Deliberately not routed through {@see prepareRates()}. That builds the
     * attended view — where a service outside the allowance is *supposed* to appear, with
     * its price, for a packer to take responsibility for — and its
     * `selectedRateIndex` is a default highlight, not a decision. Reading a
     * choice off the attended list is how the two would come to mean the same
     * thing again.
     */
    private function selectedRateForAutoShip(Package $package): UnattendedRateSelection
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
    private function nothingToBuyUnattended(Package $package, UnattendedRateSelection $selection): PackageShippingResult
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

    private function cleanupPackage(Package $package, PackageAutoShippingRequest $request, PackageShippingResult $result): void
    {
        if (! $request->cleanupOnFailure || $result->success || $result->leavePackageIntact) {
            return;
        }

        // A timeout reads as a plain failure, but the offer it spent is the
        // only record that a label may exist, and it would go with the package.
        if ($this->offerStore->hasUnresolvedPurchase($package)) {
            return;
        }

        if ($package->exists && $package->status !== PackageStatus::Shipped) {
            $package->packageItems()->delete();
            $package->delete();
        }
    }
}
