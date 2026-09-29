<?php

namespace App\Contracts;

use App\DataTransferObjects\PackageDrafts\BatchPackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftOptions;
use App\DataTransferObjects\PackageDrafts\PackageDraftSnapshot;
use App\DataTransferObjects\PackageDrafts\ReadyPackageDraft;
use App\Exceptions\PackageDraftIncompleteException;
use App\Models\Package;
use App\Models\Shipment;

interface PackageDraftWorkflow
{
    /**
     * The Shipment's active Package Draft, or null when it has none. Opening a
     * Shipment creates nothing: the first save of packing progress does.
     */
    public function resumeForShipment(Shipment $shipment): ?PackageDraftSnapshot;

    public function saveForShipment(
        Shipment $shipment,
        PackageDraftInput $input,
        PackageDraftOptions $options = new PackageDraftOptions,
    ): PackageDraftSnapshot;

    public function assertReadyToShip(
        Shipment $shipment,
        ?int $packageDraftId = null,
        PackageDraftOptions $options = new PackageDraftOptions,
    ): ReadyPackageDraft;

    /**
     * Refuse a Package that is not ready for label purchase, whichever page
     * the purchase starts from. Measurements are always required; complete
     * packed items follow the packing validation setting, as on the Pack page.
     *
     * @throws PackageDraftIncompleteException
     */
    public function assertPackageReadyToShip(Package $package): ReadyPackageDraft;

    public function createBatchReadyDraft(
        Shipment $shipment,
        BatchPackageDraftInput $input,
    ): ReadyPackageDraft;
}
