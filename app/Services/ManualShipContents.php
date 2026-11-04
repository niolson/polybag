<?php

namespace App\Services;

use App\DataTransferObjects\ManualShip\ManualShipLine;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Validation\ValidationException;

/**
 * Records the items entered on Manual Ship as the Draft Shipment's items
 * (`manual-ship-contents/02`). A typed-in item becomes a Product in the
 * Client's catalog, as an import makes one for a SKU it has not seen, so every
 * reader of an item's Product (customs, Pack, pack slips) works unchanged.
 */
class ManualShipContents
{
    /**
     * The most `shipments.value` holds.
     */
    public const MAX_VALUE = 999999.99;

    /**
     * @param  list<ManualShipLine>  $lines
     * @param  float|null  $enteredValue  The Shipment's value as entered; null totals the lines
     * @return list<ShipmentItem>
     *
     * @throws ValidationException When a typed-in SKU is already in the catalog, a picked Product is not an active one of the Client's, or the items' total is more than a Shipment holds
     */
    public function record(Shipment $shipment, array $lines, ?float $enteredValue = null): array
    {
        $value = $enteredValue ?? $this->total($lines);

        if ($value !== null && $value > self::MAX_VALUE) {
            throw ValidationException::withMessages([
                'data.value' => 'The items total more than '.number_format(self::MAX_VALUE, 2).'. Enter the Shipment\'s value.',
            ]);
        }

        $items = [];

        foreach ($lines as $line) {
            $product = $line->isFreehand()
                ? $this->createProduct($shipment, $line)
                : $this->catalogProduct($shipment, $line);

            $items[] = ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'product_id' => $product->id,
                'quantity' => $line->quantity,
                'value' => $line->unitValue,
            ]);
        }

        $shipment->update(['value' => $value]);

        return $items;
    }

    /**
     * The lines' value, or null when there are none or any has no value: an
     * unknown value is not 0, and a total without it would understate the Shipment.
     *
     * @param  list<ManualShipLine>  $lines
     */
    private function total(array $lines): ?float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            if ($line->unitValue === null) {
                return null;
            }

            $total += $line->quantity * $line->unitValue;
        }

        return $lines === [] ? null : round($total, 2);
    }

    private function catalogProduct(Shipment $shipment, ManualShipLine $line): Product
    {
        return Product::query()
            ->where('client_id', $shipment->client_id)
            ->where('active', true)
            ->find($line->productId)
            ?? throw ValidationException::withMessages([
                'data.items' => 'A picked product is not an active product in this client\'s catalog. Pick it again.',
            ]);
    }

    /**
     * A typed-in SKU already in the catalog is refused rather than reused: the
     * catalog's description, weight and origin would silently replace what was typed.
     */
    private function createProduct(Shipment $shipment, ManualShipLine $line): Product
    {
        if (($conflict = self::catalogConflict($shipment->client_id, $line->sku)) !== null) {
            throw ValidationException::withMessages(['data.items' => "SKU {$line->sku}: {$conflict}"]);
        }

        return Product::create([
            'client_id' => $shipment->client_id,
            'name' => $line->description,
            'sku' => $line->sku,
            'weight' => $line->weight,
            'hs_tariff_number' => $line->hsTariffNumber,
            'country_of_origin' => $line->countryOfOrigin,
            // A Manual Ship Shipment is in its Client's currency, so the value is the Product's too.
            'customs_value' => $line->unitValue,
            'active' => true,
        ]);
    }

    /**
     * Why a typed-in SKU cannot make a new Product, or null when it can. An
     * inactive Product is named as such: the catalog search does not offer it.
     */
    public static function catalogConflict(?int $clientId, ?string $sku): ?string
    {
        $product = filled($sku)
            ? Product::query()->where('client_id', $clientId)->where('sku', trim($sku))->first()
            : null;

        return match (true) {
            $product === null => null,
            $product->active => 'This SKU is already in the catalog. Pick it from the catalog instead.',
            default => 'This SKU belongs to an inactive product. Reactivate it in the product catalog to pick it.',
        };
    }
}
