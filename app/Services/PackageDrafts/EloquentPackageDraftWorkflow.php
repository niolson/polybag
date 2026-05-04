<?php

namespace App\Services\PackageDrafts;

use App\Contracts\PackageDraftWorkflow;
use App\DataTransferObjects\PackageDrafts\BatchPackageDraftInput;
use App\DataTransferObjects\PackageDrafts\Measurements;
use App\DataTransferObjects\PackageDrafts\PackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftItemInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftItemSnapshot;
use App\DataTransferObjects\PackageDrafts\PackageDraftOptions;
use App\DataTransferObjects\PackageDrafts\PackageDraftSnapshot;
use App\DataTransferObjects\PackageDrafts\ReadyPackageDraft;
use App\Enums\PackageStatus;
use App\Events\PackageCreated;
use App\Exceptions\PackageDraftIncompleteException;
use App\Exceptions\PackageDraftInvalidException;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

class EloquentPackageDraftWorkflow implements PackageDraftWorkflow
{
    public function resumeForShipment(Shipment $shipment): PackageDraftSnapshot
    {
        return DB::transaction(function () use ($shipment): PackageDraftSnapshot {
            $lockedShipment = $this->lockShipment($shipment);
            $package = $this->activeDraftFor($lockedShipment, lock: true) ?? $this->createDraft($lockedShipment);

            return $this->snapshot($lockedShipment, $package);
        });
    }

    public function saveForShipment(
        Shipment $shipment,
        PackageDraftInput $input,
        PackageDraftOptions $options = new PackageDraftOptions,
    ): PackageDraftSnapshot {
        $this->validateBoxSize($input->boxSizeId);
        $this->validateItems($shipment, $input->items);

        return DB::transaction(function () use ($shipment, $input, $options): PackageDraftSnapshot {
            $lockedShipment = $this->lockShipment($shipment);
            $package = $this->activeDraftFor($lockedShipment, lock: true) ?? $this->createDraft($lockedShipment);

            $package->update([
                'box_size_id' => $input->boxSizeId,
                'weight' => $this->nullableDecimal($input->measurements->weight),
                'height' => $this->nullableDecimal($input->measurements->height),
                'width' => $this->nullableDecimal($input->measurements->width),
                'length' => $this->nullableDecimal($input->measurements->length),
            ]);

            $package->packageItems()->delete();
            $package->packageItems()->createMany(
                array_map(fn (PackageDraftItemInput $item): array => [
                    'shipment_item_id' => $item->shipmentItemId,
                    'product_id' => $item->productId,
                    'quantity' => $item->quantity,
                    'transparency_codes' => $item->transparencyCodes,
                ], $input->items)
            );

            $package->refresh();
            $package->update(['weight_mismatch' => $package->computeWeightMismatch()]);
            $package->refresh();

            return $this->snapshot($lockedShipment, $package, $options);
        });
    }

    public function assertReadyToShip(
        Shipment $shipment,
        ?int $packageDraftId = null,
        PackageDraftOptions $options = new PackageDraftOptions,
    ): ReadyPackageDraft {
        $package = $packageDraftId
            ? Package::where('shipment_id', $shipment->id)->find($packageDraftId)
            : $this->activeDraftFor($shipment);

        if (! $package || $package->status !== PackageStatus::Unshipped) {
            throw new PackageDraftIncompleteException('Shipment has no active package draft.');
        }

        return $this->buildReadyDraft($shipment, $package, $options);
    }

    public function createBatchReadyDraft(
        Shipment $shipment,
        BatchPackageDraftInput $input,
    ): ReadyPackageDraft {
        $shipment->loadMissing('shipmentItems.product');

        $draftInput = $this->batchDraftInput($shipment, $input);
        $options = new PackageDraftOptions(requireCompletePackedItems: true);

        return DB::transaction(function () use ($shipment, $draftInput, $options): ReadyPackageDraft {
            $lockedShipment = $this->lockShipment($shipment);

            if ($this->activeDraftFor($lockedShipment, lock: true)) {
                throw new PackageDraftInvalidException('Shipment already has an active package draft.');
            }

            $package = $this->createDraft($lockedShipment);

            $package->update([
                'box_size_id' => $draftInput->boxSizeId,
                'weight' => $this->nullableDecimal($draftInput->measurements->weight),
                'height' => $this->nullableDecimal($draftInput->measurements->height),
                'width' => $this->nullableDecimal($draftInput->measurements->width),
                'length' => $this->nullableDecimal($draftInput->measurements->length),
            ]);

            $package->packageItems()->createMany(
                array_map(fn (PackageDraftItemInput $item): array => [
                    'shipment_item_id' => $item->shipmentItemId,
                    'product_id' => $item->productId,
                    'quantity' => $item->quantity,
                    'transparency_codes' => $item->transparencyCodes,
                ], $draftInput->items)
            );

            $package->refresh();
            $package->update(['weight_mismatch' => $package->computeWeightMismatch()]);
            $package->refresh();

            return $this->buildReadyDraft($lockedShipment, $package, $options);
        });
    }

    private function buildReadyDraft(Shipment $shipment, Package $package, PackageDraftOptions $options): ReadyPackageDraft
    {
        $snapshot = $this->snapshot($shipment, $package, $options);

        if (! $snapshot->measurements->hasPositiveValues()) {
            throw new PackageDraftIncompleteException('Package draft is missing valid measurements.');
        }

        if ($options->requireCompletePackedItems && ! $this->hasCompletePackedItems($shipment, $package)) {
            throw new PackageDraftIncompleteException('Not all shipment items are packed.');
        }

        return new ReadyPackageDraft(
            package: $package->fresh(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod', 'boxSize']),
            snapshot: $snapshot,
        );
    }

    private function activeDraftFor(Shipment $shipment, bool $lock = false): ?Package
    {
        $query = Package::query()
            ->where('shipment_id', $shipment->id)
            ->where('status', PackageStatus::Unshipped)
            ->oldest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $drafts = $query->get();

        if ($drafts->count() > 1) {
            logger()->warning('Multiple active package drafts found for shipment', [
                'shipment_id' => $shipment->id,
                'package_ids' => $drafts->pluck('id')->all(),
            ]);
        }

        return $drafts->first();
    }

    private function lockShipment(Shipment $shipment): Shipment
    {
        return Shipment::query()
            ->whereKey($shipment->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function createDraft(Shipment $shipment): Package
    {
        $package = Package::create([
            'shipment_id' => $shipment->id,
            'status' => PackageStatus::Unshipped,
        ]);

        PackageCreated::dispatch($package, $shipment);

        return $package;
    }

    private function validateBoxSize(?int $boxSizeId): void
    {
        if ($boxSizeId !== null && ! BoxSize::where('id', $boxSizeId)->exists()) {
            throw new PackageDraftInvalidException('The selected box size does not exist.');
        }
    }

    /**
     * @param  array<int, PackageDraftItemInput>  $items
     */
    private function validateItems(Shipment $shipment, array $items): void
    {
        $validItems = $shipment->shipmentItems()
            ->pluck('product_id', 'id');

        foreach ($items as $item) {
            if (! $validItems->has($item->shipmentItemId)) {
                throw new PackageDraftInvalidException('Package draft item does not belong to this shipment.');
            }

            if ($validItems[$item->shipmentItemId] !== $item->productId) {
                throw new PackageDraftInvalidException('Product mismatch detected in package draft item.');
            }

            if ($item->quantity < 0 || $item->quantity > 10000) {
                throw new PackageDraftInvalidException('Package draft item quantity is out of range.');
            }
        }
    }

    private function snapshot(
        Shipment $shipment,
        Package $package,
        PackageDraftOptions $options = new PackageDraftOptions,
    ): PackageDraftSnapshot {
        $package->loadMissing('packageItems');

        $measurements = new Measurements(
            weight: $package->weight,
            height: $package->height,
            width: $package->width,
            length: $package->length,
        );

        return new PackageDraftSnapshot(
            packageDraftId: $package->id,
            shipmentId: $shipment->id,
            measurements: $measurements,
            boxSizeId: $package->box_size_id,
            weightMismatch: (bool) $package->weight_mismatch,
            readyToShip: $package->status === PackageStatus::Unshipped
                && $measurements->hasPositiveValues()
                && (! $options->requireCompletePackedItems || $this->hasCompletePackedItems($shipment, $package)),
            items: $package->packageItems
                ->map(fn ($item): PackageDraftItemSnapshot => new PackageDraftItemSnapshot(
                    shipmentItemId: $item->shipment_item_id,
                    productId: $item->product_id,
                    quantity: $item->quantity,
                    transparencyCodes: $item->transparency_codes ?? [],
                ))
                ->values()
                ->all(),
        );
    }

    private function nullableDecimal(string|float|int|null $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new PackageDraftInvalidException('Package draft measurement must be numeric.');
        }

        return (float) $value;
    }

    private function hasCompletePackedItems(Shipment $shipment, Package $package): bool
    {
        $shipment->loadMissing('shipmentItems');
        $package->loadMissing('packageItems');

        if ($shipment->shipmentItems->isEmpty()) {
            return true;
        }

        $packedQuantities = $package->packageItems
            ->groupBy('shipment_item_id')
            ->map(fn ($items): int => (int) $items->sum('quantity'));

        foreach ($shipment->shipmentItems as $shipmentItem) {
            if (($packedQuantities[$shipmentItem->id] ?? 0) !== (int) $shipmentItem->quantity) {
                return false;
            }
        }

        return true;
    }

    private function batchDraftInput(Shipment $shipment, BatchPackageDraftInput $input): PackageDraftInput
    {
        $items = [];
        $itemsWeight = 0.0;

        foreach ($shipment->shipmentItems as $shipmentItem) {
            $product = $shipmentItem->product;

            if (! $product || (float) $product->weight <= 0) {
                throw new PackageDraftInvalidException('Batch package draft item is missing product weight.');
            }

            $quantity = (int) $shipmentItem->quantity;
            $itemsWeight += $quantity * (float) $product->weight;
            $items[] = new PackageDraftItemInput(
                shipmentItemId: $shipmentItem->id,
                productId: $shipmentItem->product_id,
                quantity: $quantity,
            );
        }

        return new PackageDraftInput(
            measurements: new Measurements(
                weight: (float) $input->boxSize->empty_weight + $itemsWeight,
                height: $input->boxSize->height,
                width: $input->boxSize->width,
                length: $input->boxSize->length,
            ),
            boxSizeId: $input->boxSize->id,
            items: $items,
        );
    }
}
