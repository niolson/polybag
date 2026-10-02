<?php

namespace App\Services\PostageSources;

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PostageSourceOperations;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;

/**
 * The postage source for a label we bought ourselves, on our own account with
 * the carrier that is carrying it.
 *
 * This is the case where the two axes coincide — the buyer *is* the carrier —
 * so the implementation is a thin pass to the carrier adapter through
 * `CarrierRegistry`, which decision 6 keeps as the carrier-side lookup. It
 * exists as a class rather than as a branch so the dispatcher resolves an
 * implementation for every package instead of asking which kind it is.
 */
class CarrierAccountPostageSource implements PostageSourceOperations
{
    public function __construct(
        private readonly CarrierRegistry $carrierRegistry,
    ) {}

    /**
     * @throws \InvalidArgumentException when the package names a carrier with no adapter
     */
    public function voidLabel(Package $package): CancelResponse
    {
        return $this->carrierRegistry
            ->directAdapterOrFail((string) $package->carrier)
            ->cancelShipment((string) $package->tracking_number, $package);
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        $adapter = $this->adapterFor($package);

        if (! $adapter) {
            return TrackShipmentResponse::failure("Unknown carrier: {$package->carrier}");
        }

        return $adapter->supportsTracking()
            ? $adapter->trackShipment($package)
            : TrackShipmentResponse::unsupported();
    }

    /**
     * We bought it, so the only remaining question is carrier policy: does this
     * carrier run a manifest program at all?
     */
    public function supportsPackageManifest(Package $package): bool
    {
        return $this->adapterFor($package)?->supportsCarrierManifest() ?? false;
    }

    private function adapterFor(Package $package): ?DirectCarrierAdapter
    {
        return $this->carrierRegistry->directAdapterFor($package->carrier);
    }
}
