<?php

namespace App\Services;

use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\TrackingStatus;
use App\Events\TrackingStatusUpdated;
use App\Models\Package;
use App\Models\User;
use App\Notifications\TrackingExceptionDetected;
use App\Services\Carriers\CarrierRegistry;
use Carbon\CarbonInterface;

class TrackingService
{
    public function __construct(
        private readonly CarrierRegistry $carrierRegistry,
    ) {}

    public function refreshPackage(Package $package): TrackShipmentResponse
    {
        if ($package->status !== PackageStatus::Shipped || ! $package->tracking_number || ! $package->carrier) {
            return TrackShipmentResponse::failure('Package is not eligible for tracking.');
        }

        if (! $this->carrierRegistry->has($package->carrier)) {
            return TrackShipmentResponse::failure("Unknown carrier: {$package->carrier}");
        }

        $adapter = $this->carrierRegistry->get($package->carrier);

        if (! $adapter->supportsTracking()) {
            $response = TrackShipmentResponse::unsupported();
            $this->persistResult($package, $response);

            return $response;
        }

        $response = $adapter->trackShipment($package);
        $this->persistResult($package, $response);

        return $response;
    }

    private function persistResult(Package $package, TrackShipmentResponse $response): void
    {
        $previousStatus = $package->tracking_status;
        $details = $package->tracking_details ?? [];

        $details['message'] = $response->message;
        $details['supported'] = $response->supported;
        $details['status_label'] = $response->statusLabel;
        $details['estimated_delivery_at'] = $response->estimatedDeliveryAt?->toIso8601String();
        $details['events'] = $response->eventsToArray();
        $details['raw'] = $response->details['raw'] ?? ($details['raw'] ?? null);

        $package->forceFill([
            'tracking_checked_at' => now(),
            'tracking_updated_at' => $response->success ? now() : ($package->tracking_updated_at ?? now()),
            'tracking_details' => $details,
        ]);

        if ($response->success && $response->status) {
            $package->tracking_status = $response->status;
            $package->delivered_at = $response->deliveredAt;
        }

        $package->save();

        if ($response->success && $response->status && $previousStatus !== $response->status) {
            TrackingStatusUpdated::dispatch($package->fresh(), $previousStatus, $response->status);
        }

        $this->notifyIfNeeded($package->fresh(), $previousStatus, $response);
    }

    private function notifyIfNeeded(Package $package, ?TrackingStatus $previousStatus, TrackShipmentResponse $response): void
    {
        if (! $response->success || ! $response->status) {
            return;
        }

        if ($response->status === TrackingStatus::Exception && $previousStatus !== TrackingStatus::Exception) {
            $this->notifyOperations($package, 'Carrier reported an exception for this package.');
            $this->markAlertSent($package, 'exception_notified_at');
        }

        if (
            $response->status === TrackingStatus::PreTransit
            && $package->shipped_at instanceof CarbonInterface
            && $package->shipped_at->lte(now()->subHours(48))
            && ! data_get($package->tracking_details, 'alerts.pre_transit_48h_notified_at')
        ) {
            $this->notifyOperations($package, 'Package has remained in pre-transit for more than 48 hours.');
            $this->markAlertSent($package, 'pre_transit_48h_notified_at');
        }
    }

    private function notifyOperations(Package $package, string $reason): void
    {
        User::query()
            ->where('active', true)
            ->whereIn('role', [Role::Manager->value, Role::Admin->value])
            ->get()
            ->each(fn (User $user) => $user->notify(new TrackingExceptionDetected($package, $reason)));
    }

    private function markAlertSent(Package $package, string $key): void
    {
        $details = $package->tracking_details ?? [];
        data_set($details, "alerts.{$key}", now()->toIso8601String());
        $package->forceFill(['tracking_details' => $details])->save();
    }
}
