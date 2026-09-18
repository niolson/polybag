<?php

namespace App\Services\PostageSources;

use App\DataTransferObjects\PostageSources\OfferDraft;
use App\DataTransferObjects\PostageSources\OfferRedemption;
use App\Enums\OfferRejection;
use App\Enums\SourceEnvironment;
use App\Models\Package;
use App\Models\ShippingOffer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Issues and spends purchase authority — ADR-0002 decision 4.
 *
 * Four properties the decision asks for, in one place so no adapter has to
 * re-implement them:
 *
 * - an **opaque identifier**, which is all the browser ever holds;
 * - **binding** to both the package and the postage-source instance, so a
 *   USPS rate quoted directly and the same service quoted through Amazon are
 *   two offers rather than one ambiguous pair of strings — and to the rate
 *   request *as quoted*, so an edit to what the carrier priced retires it;
 * - **expiry**, checked inside the claim so a window cannot close between
 *   reading a row and writing to it;
 * - **atomic consumption**, so one offer cannot be spent twice.
 *
 * The fifth — idempotent recovery — is a shared responsibility: this class
 * makes "spent, nothing confirmed" a visible state and refuses to let it be
 * spent again, and the adapter asks the source what happened under
 * {@see ShippingOffer::$public_id}, or under a key of its own it stored in
 * `purchase_context` before the purchase left (USPS's `X-Idempotency-Key`).
 *
 * Direct-carrier rates are offers too (`postage-source-split/14`): a row with
 * `postage_source = CarrierAccount` and no purchase context, issued by the
 * rate service for every rate an adapter returns. Nothing in that row can
 * spend money on its own — the account still buys — but it is the server's
 * copy of the price, service and metadata, so the browser names the offer
 * and restates nothing.
 */
class OfferStore
{
    public function issue(Package $package, OfferDraft $draft): ShippingOffer
    {
        // The datetime cast formats the instant in whatever zone it arrives
        // in and drops the zone, so a window that closes at the end of a ship
        // day in the location's timezone has to be moved into the app's
        // before it is written, or it would be read back hours early.
        $expiresAt = $draft->expiresAt?->toImmutable()->setTimezone(config('app.timezone'));

        return ShippingOffer::create([
            'package_id' => $package->id,
            'postage_source' => $draft->postageSource,
            'carrier_account_id' => $draft->carrierAccountId,
            'postage_data_source_id' => $draft->postageDataSourceId,
            'rate_quote_id' => $draft->rateQuoteId,
            'carrier' => $draft->carrier,
            'service_code' => $draft->serviceCode,
            'service_name' => $draft->serviceName,
            'price' => $draft->price,
            'currency' => $draft->currency,
            'rate_metadata' => $draft->rateMetadata === [] ? null : $draft->rateMetadata,
            'purchase_context' => $draft->purchaseContext === [] ? null : $draft->purchaseContext,
            'environment' => SourceEnvironment::current(),
            'marketplace' => $draft->marketplace,
            'expires_at' => $expiresAt,
            'quote_fingerprint' => $draft->quoteFingerprint,
            'carrier_account_fingerprint' => $draft->carrierAccountFingerprint,
        ]);
    }

    /**
     * Read an offer and say whether it could be spent, without spending it.
     *
     * Exists so that everything that can fail locally — a missing offer, the
     * wrong package, a closed window, and the caller's own validation — fails
     * before the one-way door. Consuming first and validating after turns an
     * ordinary "confirm these customs weights" prompt into a dead offer and a
     * re-quote.
     *
     * Advisory only: the answer can be stale by the time the caller acts on it,
     * which is why {@see redeem()} re-checks everything atomically.
     */
    public function inspect(Package $package, string $publicId): OfferRedemption
    {
        $offer = ShippingOffer::where('public_id', $publicId)->first();

        if (! $offer) {
            return OfferRedemption::rejected(OfferRejection::NotFound);
        }

        if ($offer->package_id !== $package->id) {
            return OfferRedemption::rejected(OfferRejection::WrongPackage, $offer);
        }

        if ($offer->isConsumed()) {
            return OfferRedemption::rejected($this->consumedRejection($offer), $offer);
        }

        if ($offer->environment !== SourceEnvironment::current()) {
            return OfferRedemption::rejected(OfferRejection::EnvironmentChanged, $offer);
        }

        if ($offer->hasExpired()) {
            return OfferRedemption::rejected(OfferRejection::Expired, $offer);
        }

        if ($offer->quoteInputsChangedSince($package)) {
            return OfferRedemption::rejected(OfferRejection::PackageChanged, $offer);
        }

        return OfferRedemption::available($offer);
    }

    /**
     * Claim an offer for this package, or say why not.
     *
     * The claim is a single conditional UPDATE rather than a read followed by a
     * write: two packers hitting Ship on the same package, or a retry racing
     * the request it is retrying, both reach this line, and only the row still
     * unconsumed and still inside its window may be stamped. Whoever loses gets
     * a rejection, never a second purchase.
     *
     * Call it immediately before the purchase, with local validation already
     * done — see {@see inspect()}.
     */
    public function redeem(Package $package, string $publicId): OfferRedemption
    {
        $offer = ShippingOffer::where('public_id', $publicId)->first();

        if (! $offer) {
            return OfferRedemption::rejected(OfferRejection::NotFound);
        }

        if ($offer->package_id !== $package->id) {
            return OfferRedemption::rejected(OfferRejection::WrongPackage, $offer);
        }

        // Checked before the claim rather than inside it: the quote inputs
        // are not what two concurrent purchases race over, and a stale offer
        // refused here is left unconsumed, which is the truth — a re-quote
        // supersedes it and nothing was ever spent on it.
        if ($offer->quoteInputsChangedSince($package)) {
            return OfferRedemption::rejected(OfferRejection::PackageChanged, $offer);
        }

        $environment = SourceEnvironment::current();

        $claimed = ShippingOffer::query()
            ->whereKey($offer->id)
            ->whereNull('consumed_at')
            ->where('environment', $environment)
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->update(['consumed_at' => now()]);

        if ($claimed !== 1) {
            $offer->refresh();

            // Consumption wins the reporting when several are true: "someone
            // already bought this" is more actionable than "and it had also
            // expired".
            return OfferRedemption::rejected(match (true) {
                $offer->isConsumed() => $this->consumedRejection($offer),
                $offer->environment !== $environment => OfferRejection::EnvironmentChanged,
                default => OfferRejection::Expired,
            }, $offer);
        }

        return OfferRedemption::available($offer->refresh());
    }

    /**
     * Which refusal a spent offer gets.
     *
     * A declined purchase is settled: the source answered and sold nothing, so
     * the packer is sent to re-quote rather than told to look for a label
     * that provably does not exist. Every other consumed offer — a label
     * bought, or an outcome nobody knows — is `AlreadyConsumed`, whose remedy
     * is a person looking before anything is bought again.
     */
    private function consumedRejection(ShippingOffer $offer): OfferRejection
    {
        return $offer->purchase_failed_at !== null
            ? OfferRejection::PurchaseDeclined
            : OfferRejection::AlreadyConsumed;
    }

    /**
     * Record what the source called the purchase this offer paid for.
     *
     * Only ever fills a blank. The adapter records the source's own identifier
     * as soon as the source confirms; anything later — a tracking number, say —
     * is a weaker reference and must not overwrite it.
     */
    public function recordPurchase(ShippingOffer $offer, string $reference): void
    {
        ShippingOffer::query()
            ->whereKey($offer->id)
            ->whereNull('purchase_reference')
            ->update(['purchase_reference' => $reference]);

        $offer->refresh();
    }

    /**
     * Record that the source answered and declined.
     *
     * Resolves the offer without a purchase. Only for a definite answer from
     * the source: a rejected request, a validation error, a refusal. A timeout
     * or a transport error is *not* one of these — the label may exist — and
     * must leave the offer unresolved so
     * {@see awaitingPurchaseConfirmation()} blocks further spending.
     *
     * One exception, made by the purchase path rather than here: an offer
     * left unresolved on a source that cannot be asked what happened — FedEx,
     * which implements no `RecoversUnresolvedPurchase` — is recorded as a
     * failure on the next attempt, because for that source the unresolved
     * state can never be resolved and would otherwise be terminal for the
     * package. See `EloquentPackageShippingWorkflow::recoverPurchase()`.
     */
    public function recordFailure(ShippingOffer $offer, string $reason): void
    {
        ShippingOffer::query()
            ->whereKey($offer->id)
            ->whereNull('purchase_reference')
            ->whereNull('purchase_failed_at')
            ->update([
                'purchase_failed_at' => now(),
                'purchase_failure_reason' => mb_substr($reason, 0, 255),
            ]);

        $offer->refresh();
    }

    /**
     * Record that the source was asked about this offer and could not say.
     *
     * Not a resolution — the offer stays in
     * {@see awaitingPurchaseConfirmation()} and the package stays blocked.
     * The stamp separates an unresolved offer nobody has retried yet, which
     * the next attempt will ask about, from one that was asked about and got
     * no usable answer, which is the real unknown a person has to look at.
     */
    public function recordUnansweredRecovery(ShippingOffer $offer): void
    {
        ShippingOffer::query()
            ->whereKey($offer->id)
            ->whereNull('purchase_reference')
            ->whereNull('purchase_failed_at')
            ->update(['recovery_unanswered_at' => now()]);

        $offer->refresh();
    }

    /**
     * Offers spent against this package whose outcome nobody knows.
     *
     * What a purchase path consults before spending anything else on a package:
     * a row here means a label may already exist upstream, and buying again
     * would pay for a second one. An offer the source declined is not one of
     * these — it resolved, it just resolved to "no".
     *
     * @return Collection<int, ShippingOffer>
     */
    public function awaitingPurchaseConfirmation(Package $package): Collection
    {
        return ShippingOffer::query()
            ->where('package_id', $package->id)
            ->whereNotNull('consumed_at')
            ->whereNull('purchase_reference')
            ->whereNull('purchase_failed_at')
            ->orderBy('consumed_at')
            ->get();
    }
}
