<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ImportSourceInterface;
use App\Models\Shipment;
use App\Models\ShipmentItem;

class ShipmentItemImporter
{
    public function __construct(
        private readonly ImportReferenceResolver $references,
    ) {}

    /**
     * @return array{items_created: int, items_updated: int, products_created: int, products_updated: int}
     */
    public function import(Shipment $shipment, ImportSourceInterface $source): array
    {
        if (! $this->isEnabledFor($source)) {
            return $this->emptyStats();
        }

        $stats = $this->emptyStats();
        $items = $source->fetchShipmentItems((string) $shipment->source_record_id);

        foreach ($items as $itemData) {
            $product = $this->references->productIdFor($itemData);
            $productId = $product['id'];

            if (! $productId) {
                continue;
            }

            $shipmentItem = ShipmentItem::updateOrCreate(
                [
                    'shipment_id' => $shipment->id,
                    'product_id' => $productId,
                ],
                [
                    'barcode' => $itemData['barcode'] ?? null,
                    'quantity' => $itemData['quantity'] ?? 1,
                    'value' => $itemData['value'] ?? null,
                    'description' => $itemData['description'] ?? null,
                    'transparency' => $itemData['transparency'] ?? false,
                ]
            );

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

        return $stats;
    }

    private function isEnabledFor(ImportSourceInterface $source): bool
    {
        return (bool) config("shipment-import.sources.{$source->getSourceName()}.shipment_items.enabled", true);
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
