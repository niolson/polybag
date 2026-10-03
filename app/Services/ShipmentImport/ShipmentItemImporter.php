<?php

namespace App\Services\ShipmentImport;

use App\Models\DataSource;
use App\Models\Shipment;
use App\Models\ShipmentItem;
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

        foreach ($items as $itemData) {
            $product = $this->references->productIdFor($itemData, $shipment->client);
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
                $shipmentItem = ShipmentItem::query()
                    ->where('shipment_id', $shipment->id)
                    ->where('source_item_id', $sourceItemId)
                    ->first()
                    ?? ShipmentItem::query()
                        ->where('shipment_id', $shipment->id)
                        ->where('product_id', $productId)
                        ->whereNull('source_item_id')
                        ->when($importedShipmentItemIds !== [], fn ($query) => $query->whereNotIn('id', $importedShipmentItemIds))
                        ->orderBy('id')
                        ->first();

                if ($shipmentItem) {
                    $shipmentItem->update($values + ['source_item_id' => $sourceItemId]);
                } else {
                    $shipmentItem = ShipmentItem::query()->updateOrCreate(
                        [
                            'shipment_id' => $shipment->id,
                            'source_item_id' => $sourceItemId,
                        ],
                        $values,
                    );
                }
            } else {
                $shipmentItem = ShipmentItem::query()->updateOrCreate(
                    [
                        'shipment_id' => $shipment->id,
                        'product_id' => $productId,
                    ],
                    $values,
                );
            }

            $importedShipmentItemIds[] = $shipmentItem->id;

            if ($shipmentItem->wasRecentlyCreated) {
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

        if (($record->settings['authoritative_shipment_items'] ?? false)
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
