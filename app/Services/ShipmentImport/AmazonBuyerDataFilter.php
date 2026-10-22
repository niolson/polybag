<?php

namespace App\Services\ShipmentImport;

use App\Enums\AppMode;
use App\Models\Shipment;
use App\Services\ShipmentImport\Sources\AmazonSource;

/**
 * Keeps a prospect's buyer data out of a demo tenant (`live-store-demo/03`).
 *
 * In Demo an Amazon order keeps only where it is going: the delivery
 * address's city, state or region, postal code and country. The buyer, the
 * recipient's name, street lines, phone, address type and delivery
 * instructions, and the buyer's gift messages and customizations are dropped
 * as soon as the order is parsed, before anything maps, writes, logs or
 * keeps it, so none of it reaches a Shipment, a log line or an error. The
 * Shipment's line 1 then reads {@see Shipment::WITHHELD_ADDRESS_LINE}.
 *
 * Keyed on the mode, not on where the order was read: a sandbox order is
 * withheld in Demo too, and nothing is withheld outside it.
 *
 * Amazon is still asked for `RECIPIENT`: the delivery address, the four
 * fields kept included, is only in that dataset ({@see AmazonSource}).
 */
class AmazonBuyerDataFilter
{
    /**
     * The delivery address fields a withheld order keeps.
     *
     * @var list<string>
     */
    public const KEPT_ADDRESS_FIELDS = ['city', 'stateOrRegion', 'postalCode', 'countryCode'];

    public function withholds(): bool
    {
        return AppMode::current() === AppMode::Demo;
    }

    /**
     * The orders as Amazon sent them, or in Demo without their buyer data.
     *
     * @param  array<int, mixed>  $orders
     * @return list<array<string, mixed>>
     */
    public function filter(array $orders): array
    {
        $orders = array_values(array_filter($orders, 'is_array'));

        return $this->withholds() ? array_map($this->withoutBuyerData(...), $orders) : $orders;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function withoutBuyerData(array $order): array
    {
        $address = $order['recipient']['deliveryAddress'] ?? null;
        unset($order['buyer'], $order['recipient']);

        if (is_array($address)) {
            $order['recipient'] = [
                'deliveryAddress' => array_intersect_key($address, array_flip(self::KEPT_ADDRESS_FIELDS)),
            ];
        }

        if (is_array($order['orderItems'] ?? null)) {
            $order['orderItems'] = array_map(function (mixed $item): mixed {
                if (is_array($item)) {
                    unset($item['fulfillment']['packing']['giftOption'], $item['product']['customization']);
                }

                return $item;
            }, $order['orderItems']);
        }

        return $order;
    }
}
