<?php

namespace App\Services\ShipmentImport;

use App\Models\DataSource;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShipmentItemImporter
{
    public function __construct(
        private readonly ImportReferenceResolver $references,
    ) {}

    /**
     * Import pre-fetched item rows for a shipment. Items are fetched upstream
     * (before the batch write) so they can feed the source checksum.
     *
     * One transaction covers the shipment's item writes and the items-version
     * increments they make, so a failure leaves neither behind.
     *
     * @param  Collection<int, covariant array<string, mixed>>  $items
     * @return array{items_created: int, items_updated: int, products_created: int, products_updated: int}
     */
    public function import(Shipment $shipment, Collection $items, DataSource $record): array
    {
        if (! $this->isEnabledFor($record)) {
            return $this->emptyStats();
        }

        return DB::transaction(fn (): array => $this->importItems($shipment, $items, $record));
    }

    /**
     * @param  Collection<int, covariant array<string, mixed>>  $items
     * @return array{items_created: int, items_updated: int, products_created: int, products_updated: int}
     */
    private function importItems(Shipment $shipment, Collection $items, DataSource $record): array
    {
        $stats = $this->emptyStats();
        $importedShipmentItemIds = [];
        $hasUnresolvedItems = false;

        // Read once and matched in memory, rather than looked up line by line.
        $existingItems = $shipment->shipmentItems()->orderBy('id')->get();

        foreach ($items as $itemData) {
            // An order line's price is what one buyer paid, so an order never sets a
            // Product's customs value; the catalog sync fills it (`manual-ship-contents/03`).
            $product = $this->references->productIdFor(Arr::except($itemData, ['customs_value']), $shipment->client);
            $productId = $product['id'];

            if (! $productId) {
                $hasUnresolvedItems = true;

                continue;
            }

            $sourceItemId = $itemData['source_item_id'] ?? null;
            $values = [
                'product_id' => $productId,
                'quantity' => $itemData['quantity'] ?? 1,
                'value' => $itemData['value'] ?? null,
                'transparency' => $itemData['transparency'] ?? false,
            ];

            if (filled($sourceItemId)) {
                $shipmentItem = $existingItems->first(
                    // Case-insensitive, as MySQL's collation compares the column.
                    fn (ShipmentItem $item): bool => $item->source_item_id !== null
                        && mb_strtolower((string) $item->source_item_id) === mb_strtolower((string) $sourceItemId),
                ) ?? $existingItems->first(
                    fn (ShipmentItem $item): bool => $item->source_item_id === null
                        && (int) $item->product_id === (int) $productId
                        && ! in_array($item->id, $importedShipmentItemIds, true),
                );
                $matchValues = $values + ['source_item_id' => $sourceItemId];
                $newKey = ['shipment_id' => $shipment->id, 'source_item_id' => $sourceItemId];
            } else {
                $shipmentItem = $existingItems->first(
                    fn (ShipmentItem $item): bool => (int) $item->product_id === (int) $productId,
                );
                $matchValues = $values;
                $newKey = ['shipment_id' => $shipment->id, 'product_id' => $productId];
            }

            $created = false;

            if ($shipmentItem) {
                $shipmentItem->update($matchValues);
            } else {
                // Still an upsert by its key: another import may have written
                // the line since the items were read.
                $shipmentItem = ShipmentItem::query()->updateOrCreate($newKey, $values);
                $created = $shipmentItem->wasRecentlyCreated;
                $existingItems->push($shipmentItem);
            }

            $importedShipmentItemIds[] = $shipmentItem->id;

            if ($created) {
                $stats['items_created']++;
            } else {
                $stats['items_updated']++;
            }

            if ($product['created']) {
                $stats['products_created']++;
            } elseif ($product['updated']) {
                $stats['products_updated']++;
            }
        }

        if ($record->hasAuthoritativeShipmentItems()
            && $items->isNotEmpty()
            && $importedShipmentItemIds !== []
            && ! $hasUnresolvedItems
            && ! $shipment->packages()->exists()) {
            $removed = $shipment->shipmentItems()
                ->whereNotIn('id', $importedShipmentItemIds)
                ->delete();

            // A bulk delete fires no model events, so it increments the version itself.
            if ($removed > 0) {
                Shipment::incrementItemsVersion($shipment->id);
            }
        }

        return $stats;
    }

    public function isEnabledFor(DataSource $record): bool
    {
        return (bool) ($record->settings['shipment_items_enabled'] ?? true);
    }

    /**
     * @return array{items_created: int, items_updated: int, products_created: int, products_updated: int}
     */
    private function emptyStats(): array
    {
        return [
            'items_created' => 0,
            'items_updated' => 0,
            'products_created' => 0,
            'products_updated' => 0,
        ];
    }
}
