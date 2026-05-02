<?php

namespace App\Services\PackageLabels;

use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\PackageLabels\LabelReprintResult;
use App\DataTransferObjects\PackageLabels\LabelVoidResult;
use App\DataTransferObjects\PrintRequest;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\Package;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Saloon\Exceptions\Request\RequestException;

class EloquentPackageLabelWorkflow implements PackageLabelWorkflow
{
    public function __construct(
        private readonly CarrierRegistry $carrierRegistry,
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
            $adapter = $this->carrierRegistry->get($package->carrier);
            $response = $adapter->cancelShipment($package->tracking_number, $package);

            if (! $response->success) {
                return LabelVoidResult::failure('Void failed', $response->message ?? 'Failed to cancel the label.');
            }

            $package->clearShipping();

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

        if (! $user->role->isAtLeast(Role::Manager) && $package->shipped_by_user_id !== $user->id) {
            return LabelReprintResult::failure('Access Denied', 'You can only reprint labels for packages you shipped.');
        }

        return LabelReprintResult::success(
            printRequest: PrintRequest::fromPackage($package),
            message: "Reprinted label for tracking: {$package->tracking_number}",
        );
    }
}
