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
use Illuminate\Support\Facades\DB;
use Saloon\Exceptions\Request\RequestException;

class EloquentPackageLabelWorkflow implements PackageLabelWorkflow
{
    public function __construct(
        private readonly PostageSourceDispatcher $dispatcher,
    ) {}

    public function voidLabel(Package $package): LabelVoidResult
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

            // The operator asking for the void is whoever is signed in. Resolved
            // here rather than passed by the caller because the contract's
            // `voidLabel(Package)` takes no user and its callers are Filament pages.
            $package->clearShipping(VoidReason::Operator, auth()->id());

            return LabelVoidResult::success($response->message);
        } catch (\RuntimeException $e) {
            return LabelVoidResult::failure('Package State Changed', $e->getMessage());
        } catch (RequestException) {
            return LabelVoidResult::failure('Carrier Error', 'Unable to connect to carrier. Please try again.');
        } catch (\Exception) {
            return LabelVoidResult::failure('Cancel Error', 'An unexpected error occurred.');
        }
    }

    public function labelForReprint(Package $package, User $user): LabelReprintResult
    {
        if ($package->status !== PackageStatus::Shipped || ! $package->label_data) {
            return LabelReprintResult::failure('Label Not Available', 'The label for the package is not available.');
        }

        if ($user->cannot('printLabel', $package)) {
            return LabelReprintResult::failure('Access Denied', 'You can only reprint labels for packages you shipped.');
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
