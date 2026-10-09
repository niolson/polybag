<?php

namespace App\Services\PostageSources;

use App\Contracts\PackageLabelWorkflow;
use App\Contracts\RecoversUnresolvedPurchase;
use App\DataTransferObjects\PackageLabels\LabelVoidResult;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Exceptions\PurchaseNotResolvableException;
use App\Listeners\InvalidateDashboardCache;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\CarrierRegistry;
use App\Services\PackageShipping\PurchaseLock;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A person's answer to a purchase nothing accounts for —
 * `postage-source-split/16`.
 *
 * The way out of ADR-0002 decision 4's "spent, nothing confirmed" state when
 * the source cannot answer: recovery keeps getting no reply, the connection
 * that sold it is gone, or the source confirmed a sale whose Label was never
 * saved. Neither outcome asks the source anything; it has been asked, or
 * cannot be. The person checked the carrier or channel and says what they
 * found, and that is what is recorded, audit-logged with who said it.
 */
class UnresolvedPurchaseResolver
{
    public function __construct(
        private readonly OfferStore $offerStore,
        private readonly CarrierRegistry $carrierRegistry,
        private readonly PackageLabelWorkflow $labels,
    ) {}

    /**
     * The unaccounted offers a person has to answer.
     *
     * All of them but one kind: an unanswered direct purchase on a carrier
     * that cannot be asked — FedEx, which bills only on tender. The next
     * attempt settles that as failed on its own
     * (`EloquentPackageShippingWorkflow::recoverPurchase()`), so it never
     * blocks a package and sending someone to check the carrier would be work
     * for nothing. A sale such a carrier *confirmed* stays: a retry cannot
     * settle a confirmed sale, so a person is the only way out of it.
     *
     * @return Builder<ShippingOffer>
     */
    public function needingAPerson(): Builder
    {
        return ShippingOffer::query()
            ->unaccounted()
            ->where(fn (Builder $query) => $query
                ->where('postage_source', '!=', PostageSource::CarrierAccount->value)
                ->orWhereNotNull('purchase_reference')
                ->orWhereIn('carrier', $this->carriersThatCanBeAsked()));
    }

    /**
     * Whether the source that sold this offer can be asked what became of it
     * — USPS, UPS and Amazon — so *Check again* has something to do.
     */
    public function canBeAsked(ShippingOffer $offer): bool
    {
        return app(PostageSourceDispatcher::class)->sellerFor($offer) instanceof RecoversUnresolvedPurchase;
    }

    /**
     * @return list<string>
     */
    private function carriersThatCanBeAsked(): array
    {
        return array_values(array_filter(
            CarrierRegistry::carrierAccountCarrierNames(),
            fn (string $carrier): bool => $this->carrierRegistry->has($carrier)
                && $this->carrierRegistry->quotingAdapterFor($carrier) instanceof RecoversUnresolvedPurchase,
        ));
    }

    /**
     * A label exists: ship the package on it.
     *
     * Everything but the tracking number comes from the offer, which is what
     * was quoted and paid for — carrier, service, catalog service, price and
     * source — so the service is `confirmed`, as a purchase that replied
     * records it. There is no label image, so the package cannot be printed or
     * reprinted from here; it can be tracked, exported and voided.
     *
     * @throws PurchaseNotResolvableException
     */
    public function recordLabel(ShippingOffer $offer, string $trackingNumber, User $user, ?string $note = null): Package
    {
        return $this->underPurchaseLock($offer, fn (): Package => $this->record($offer, $trackingNumber, $user, $note, 'label_recorded'));
    }

    /**
     * A label exists and should not be used: record it, then void it through
     * the source that sold it — a cancel, or a refund request once it is past
     * cancelling — so the package can be bought again.
     *
     * The label is recorded whatever the void says. A refused void leaves it
     * recorded and active, to be voided again from the package page, rather
     * than leave a paid-for label nothing knows about. It is held from every
     * export path until then, and released only if the void was refused:
     * exporting first would fulfill the order on a label being voided.
     *
     * @throws PurchaseNotResolvableException
     */
    public function recordLabelAndVoid(ShippingOffer $offer, string $trackingNumber, User $user, ?string $note = null): LabelVoidResult
    {
        return $this->underPurchaseLock($offer, function () use ($offer, $trackingNumber, $user, $note): LabelVoidResult {
            $package = $this->record($offer, $trackingNumber, $user, $note, 'label_recorded_to_void', announce: false);

            return $this->releaseIfVoidRefused($package, $this->labels->voidLabel($package, $user));
        });
    }

    /**
     * A label exists and was already voided on the source's own site: record
     * it, then record the void without asking the source again.
     *
     * @throws PurchaseNotResolvableException
     */
    public function recordLabelAlreadyVoided(ShippingOffer $offer, string $trackingNumber, User $user, ?string $note = null): LabelVoidResult
    {
        return $this->underPurchaseLock($offer, function () use ($offer, $trackingNumber, $user, $note): LabelVoidResult {
            $package = $this->record($offer, $trackingNumber, $user, $note, 'label_recorded_already_voided', announce: false);

            // Never released: the label is dead at the source whether or not
            // recording the void succeeds.
            return $this->labels->recordVoid($package, $user);
        });
    }

    /**
     * Nothing was bought: settle the offer as failed, freeing the package to
     * be quoted again.
     *
     * Refused once the source has said a label exists — it confirmed the sale,
     * or said it will not send the label again. That label exists whatever a
     * person finds, and the honest record is to record it and void it.
     *
     * @throws PurchaseNotResolvableException
     */
    public function recordNothingBought(ShippingOffer $offer, User $user, ?string $note = null): void
    {
        $this->underPurchaseLock($offer, function () use ($offer, $user, $note): void {
            DB::transaction(function () use ($offer, $user): void {
                $locked = $this->lockUnaccounted($offer);

                if ($locked->isKnownSold()) {
                    throw PurchaseNotResolvableException::sourceConfirmedSale();
                }

                $this->offerStore->recordFailure($locked, "Resolved by hand by {$user->name}: no label was bought");
            });

            $this->audit($offer, $offer->package, 'nothing_bought', $user, $note);
        });
    }

    /**
     * @throws PurchaseNotResolvableException
     */
    private function record(ShippingOffer $offer, string $trackingNumber, User $user, ?string $note, string $outcome, bool $announce = true): Package
    {
        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            throw PurchaseNotResolvableException::missingTrackingNumber();
        }

        $package = $offer->package;

        DB::transaction(function () use ($offer, $package, $trackingNumber, $user, $announce): void {
            $locked = $this->lockUnaccounted($offer);

            if ($locked->postage_source === PostageSource::PostageDataSource && $locked->postage_data_source_id === null) {
                throw PurchaseNotResolvableException::connectionGone();
            }

            if ($package->fresh()?->status !== PackageStatus::Unshipped) {
                throw PurchaseNotResolvableException::packageAlreadyShipped();
            }

            // Read before the stamp below: for Amazon this is the shipment ID
            // the sale was confirmed under.
            $sourceReference = $locked->purchase_reference;

            $this->offerStore->recordPurchase($locked, $trackingNumber);

            $package->markShipped(new ShipResponse(
                success: true,
                trackingNumber: $trackingNumber,
                cost: $locked->price === null ? null : (float) $locked->price,
                carrier: $locked->carrier,
                service: $locked->service_name,
                // The day it was bought, where the location keeps its days.
                shipDate: CarbonImmutable::parse($locked->consumed_at)->setTimezone(Location::timezone())->startOfDay(),
                carrierAccountId: $locked->carrier_account_id,
                postageSource: $locked->postage_source,
                postageDataSourceId: $locked->postage_data_source_id,
                serviceEvidence: ServiceEvidence::Confirmed,
                sourceLabelReference: $sourceReference,
                // What voiding and tracking an Amazon label read, as a sale that
                // replied would have recorded it.
                metadata: $locked->postageDataSource?->source_type === AmazonSource::class
                    ? AmazonBuyShippingAdapter::labelMetadata($locked, $sourceReference)
                    : [],
            ), $locked->postage_source, $user->id, $locked->carrier_service_id, $announce, $locked->declared_customs_terms);
        });

        $this->audit($offer, $package, $outcome, $user, $note, ['tracking_number' => $trackingNumber]);

        return $package->refresh();
    }

    /**
     * A label recorded to be voided is released to the channel only when the
     * source refused the void, so the package really is shipped on it. Not
     * when the source voided it and PolyBag failed to record that: the package
     * still reads as shipped, but on a label that no longer exists, and it
     * stays held from export until someone records the void.
     */
    private function releaseIfVoidRefused(Package $package, LabelVoidResult $result): LabelVoidResult
    {
        if (! $result->voidedAtSource) {
            $package->releaseForExport();
        }

        return $result;
    }

    /**
     * Settle under the same per-package lock as a purchase and as asking the
     * source, so a purchase still in flight cannot be settled underneath it.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $settle
     * @return TResult
     *
     * @throws PurchaseNotResolvableException
     */
    private function underPurchaseLock(ShippingOffer $offer, callable $settle): mixed
    {
        $lock = PurchaseLock::for($offer->package);

        if (! $lock->get()) {
            throw PurchaseNotResolvableException::purchaseInProgress();
        }

        try {
            return $settle();
        } finally {
            $lock->release();
            InvalidateDashboardCache::invalidateAll();
        }
    }

    private function lockUnaccounted(ShippingOffer $offer): ShippingOffer
    {
        return ShippingOffer::query()
            ->whereKey($offer->id)
            ->unaccounted()
            ->lockForUpdate()
            ->first()
            ?? throw PurchaseNotResolvableException::alreadyResolved();
    }

    /**
     * @param  array<string, mixed>  $entered
     */
    private function audit(ShippingOffer $offer, Package $package, string $outcome, User $user, ?string $note, array $entered = []): void
    {
        AuditLog::record(
            AuditAction::PurchaseResolvedByHand,
            $package,
            newValues: ['outcome' => $outcome, ...$entered],
            metadata: array_filter([
                'offer' => $offer->public_id,
                'carrier' => $offer->carrier,
                'service' => $offer->service_name,
                'price' => $offer->price,
                'attempted_at' => $offer->consumed_at?->toIso8601String(),
                'note' => filled($note) ? $note : null,
            ], fn (mixed $value): bool => $value !== null),
            userId: $user->id,
        );
    }
}
