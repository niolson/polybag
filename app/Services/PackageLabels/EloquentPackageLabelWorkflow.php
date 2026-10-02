<?php

namespace App\Services\PackageLabels;

use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\PackageLabels\LabelReprintResult;
use App\DataTransferObjects\PackageLabels\LabelVoidResult;
use App\DataTransferObjects\PrintRequest;
use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\VoidReason;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\User;
use App\Services\PostageSources\PostageSourceDispatcher;
use App\Services\ShopifyFulfillmentCanceller;
use Illuminate\Support\Facades\DB;
use Saloon\Exceptions\Request\RequestException;

class EloquentPackageLabelWorkflow implements PackageLabelWorkflow
{
    public function __construct(
        private readonly PostageSourceDispatcher $dispatcher,
        private readonly ShopifyFulfillmentCanceller $shopifyFulfillments,
    ) {}

    public function voidLabel(Package $package, User $user): LabelVoidResult
    {
        if ($user->cannot('voidLabel', $package)) {
            return LabelVoidResult::failure('Access Denied', 'Only a manager can void this label.');
        }

        return $this->void($package, $user);
    }

    public function voidOwnLabel(Package $package, User $user): LabelVoidResult
    {
        if ($user->cannot('voidOwnLabel', $package)) {
            return LabelVoidResult::failure('Access Denied', 'You can only void labels for packages you shipped.');
        }

        return $this->void($package, $user);
    }

    private function void(Package $package, User $user): LabelVoidResult
    {
        if ($package->status !== PackageStatus::Shipped) {
            return LabelVoidResult::failure('Package Not Found', 'The package could not be found or is not shipped.');
        }

        if (! $package->tracking_number || ! $package->carrier) {
            return LabelVoidResult::failure('Cannot Cancel', 'Package is missing tracking information.');
        }

        try {
            // Whoever bought the label voids it. Asking the carrier instead would
            // send a Shopify-bought USPS parcel to our own USPS account, which
            // never bought it — the account is no longer implied by the carrier
            // name now that the carrier of record is recorded honestly.
            $response = $this->dispatcher->voidLabel($package);

            if (! $response->success) {
                return LabelVoidResult::failure('Void failed', $response->message ?? 'Failed to cancel the label.');
            }
        } catch (\RuntimeException $e) {
            return LabelVoidResult::failure('Package State Changed', $e->getMessage());
        } catch (RequestException) {
            return LabelVoidResult::failure('Carrier Error', 'Unable to connect to carrier. Please try again.');
        } catch (\Exception) {
            return LabelVoidResult::failure('Cancel Error', 'An unexpected error occurred.');
        }

        try {
            $package->clearShipping(VoidReason::Operator, $user->id);
        } catch (\PDOException|\LogicException $e) {
            // The source has voided the label, and asking it again would be
            // refused, so "try again" is the wrong advice (`project-review/10`).
            // PDOException covers QueryException and DeadlockException, which
            // are RuntimeExceptions but no sign of a race with another void.
            logger()->error('A label was voided at its source but the void could not be recorded', [
                'package_id' => $package->id,
                'tracking_number' => $package->tracking_number,
                'error' => $e->getMessage(),
            ]);

            return LabelVoidResult::failure(
                'Voided, not recorded',
                'The label was voided, but PolyBag could not record it, so the package still shows as shipped. '
                .'A manager can use Record Void on the Packages table to un-ship it without voiding it again.',
            );
        } catch (\RuntimeException $e) {
            return LabelVoidResult::failure('Package State Changed', $e->getMessage());
        }

        return LabelVoidResult::success($response->message, $this->takeBackFromChannel($package));
    }

    public function recordVoid(Package $package, User $user): LabelVoidResult
    {
        if ($user->cannot('voidLabel', $package)) {
            return LabelVoidResult::failure('Access Denied', 'Only a manager can record a void.');
        }

        if ($package->status !== PackageStatus::Shipped) {
            return LabelVoidResult::failure('Package Not Found', 'The package could not be found or is not shipped.');
        }

        try {
            $package->clearShipping(VoidReason::Recorded, $user->id);
        } catch (\PDOException $e) {
            logger()->error('Could not record a void', ['package_id' => $package->id, 'error' => $e->getMessage()]);

            return LabelVoidResult::failure('Cancel Error', 'An unexpected error occurred. Please try again.');
        } catch (\RuntimeException $e) {
            return LabelVoidResult::failure('Package State Changed', $e->getMessage());
        }

        return LabelVoidResult::success(
            'The void was recorded and the package can be shipped again. Nothing was sent to the postage source.',
            $this->takeBackFromChannel($package),
        );
    }

    /**
     * Take the voided Label's tracking number back off the sales channel.
     *
     * Read from the Label just voided, which is the Package's newest. Shopify
     * is the only channel with anything to take back: an Amazon re-confirm with
     * the same package reference replaces the number on the order, per the
     * Orders v0 docs (not yet seen on a production order).
     *
     * @return string|null what the operator has to put right on the channel
     */
    private function takeBackFromChannel(Package $package): ?string
    {
        return $this->shopifyFulfillments->cancelAfterVoid(
            $package,
            $package->labels()->latest('id')->value('shopify_fulfillment_id'),
        );
    }

    public function labelForReprint(Package $package, User $user): LabelReprintResult
    {
        if ($package->status !== PackageStatus::Shipped || ! $package->label_data) {
            return LabelReprintResult::failure('Label Not Available', 'The label for the package is not available.');
        }

        if ($user->cannot('printLabel', $package)) {
            return LabelReprintResult::failure('Access Denied', 'You cannot print this label.');
        }

        $isReprint = $package->label_printed_at !== null;

        return LabelReprintResult::success(
            printRequest: PrintRequest::fromPackage($package),
            message: $isReprint
                ? "Reprinted label for tracking: {$package->tracking_number}"
                : "Printed label for tracking: {$package->tracking_number}",
            title: $isReprint ? 'Label Reprinted' : 'Label Printed',
        );
    }

    /**
     * Record that a label physically reached a printer.
     *
     * The timestamp tracks the most recent print; the audit trail is what preserves
     * every individual print.
     *
     * @return bool Whether this was a reprint (the label had been printed before)
     */
    public function markLabelPrinted(Package $package, ?User $user = null): bool
    {
        $isReprint = $package->label_printed_at !== null;

        DB::transaction(function () use ($package): void {
            $printedAt = now();

            // Package row first, then its label: the lock order every writer
            // keeps, so a print acknowledgement racing a void cannot deadlock.
            $package->forceFill(['label_printed_at' => $printedAt])->save();

            // Exactly one, thrown rather than skipped: the structural assertion
            // below would pass an unshipped package with no label, and the only
            // caller refuses those before reaching here, so a zero-row stamp is
            // a broken invariant and not a case to paper over.
            $stamped = DB::table('package_labels')
                ->where('package_id', $package->id)
                ->whereNull('voided_at')
                ->update(['last_printed_at' => $printedAt, 'updated_at' => $printedAt]);

            if ($stamped !== 1) {
                throw new \LogicException("Package {$package->id} has no active label to record the print on.");
            }

            $package->assertLabelStateIsConsistent();
        });

        AuditLog::record(
            AuditAction::LabelPrinted,
            $package,
            metadata: [
                'reprint' => $isReprint,
                'tracking_number' => $package->tracking_number,
                'carrier' => $package->carrier,
                'label_format' => $package->label_format,
                'label_dpi' => $package->label_dpi,
            ],
            userId: $user?->id,
        );

        return $isReprint;
    }
}
