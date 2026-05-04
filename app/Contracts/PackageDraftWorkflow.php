<?php

namespace App\Contracts;

use App\DataTransferObjects\PackageDrafts\BatchPackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftOptions;
use App\DataTransferObjects\PackageDrafts\PackageDraftSnapshot;
use App\DataTransferObjects\PackageDrafts\ReadyPackageDraft;
use App\Models\Shipment;

interface PackageDraftWorkflow
{
    public function resumeForShipment(Shipment $shipment): PackageDraftSnapshot;

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

    public function createBatchReadyDraft(
        Shipment $shipment,
        BatchPackageDraftInput $input,
    ): ReadyPackageDraft;
}
