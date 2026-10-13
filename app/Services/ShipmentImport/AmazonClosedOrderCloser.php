<?php

namespace App\Services\ShipmentImport;

use App\DataTransferObjects\ShipmentImport\SourceClosureResult;
use App\DataTransferObjects\ShipmentImport\SourceOrderReport;
use App\DataTransferObjects\ShipmentImport\SourceParcel;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Models\DataSource;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Closes the Shipments whose Amazon order the import read as shipped or
 * cancelled (`order-reconciliation/07`).
 *
 * The import asks Amazon for every order updated since it last read, open
 * and closed alike, so the closed ones arrive in the same search that imports
 * the open ones. {@see SourceClosureApplier} closes each Shipment still open
 * here.
 */
class AmazonClosedOrderCloser
{
    /** Order IDs per Shipment query. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly SourceClosureApplier $applier,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $closedOrders  shipped and cancelled orders, keyed by order ID
     */
    public function close(DataSource $dataSource, array $closedOrders): SourceClosureResult
    {
        $closed = 0;

        foreach (array_chunk($closedOrders, self::CHUNK_SIZE, preserve_keys: true) as $chunk) {
            $shipments = Shipment::query()
                ->where('data_source_id', $dataSource->id)
                ->where('status', ShipmentStatus::Open)
                // A Shipment a manager reopened stays open (decision 7).
                ->where(fn (Builder $query) => $query
                    ->whereNull('status_origin')
                    ->orWhere('status_origin', '!=', ShipmentStatusOrigin::Manager))
                ->whereIn('source_record_id', array_map('strval', array_keys($chunk)))
                ->get();

            foreach ($shipments as $shipment) {
                $report = $this->report($chunk[$shipment->source_record_id]);

                if ($report !== null && $this->applier->apply($shipment, $report)) {
                    $closed++;
                }
            }
        }

        return new SourceClosureResult($closed);
    }

    /**
     * What Amazon reported, read by the table in `order-reconciliation/07`.
     *
     * @param  array<string, mixed>  $order
     */
    private function report(array $order): ?SourceOrderReport
    {
        return match ($order['fulfillment']['fulfillmentStatus'] ?? null) {
            'CANCELLED' => SourceOrderReport::cancelled('Amazon reported the order cancelled'),
            'SHIPPED' => SourceOrderReport::shipped('Amazon reported the order shipped', $this->parcels($order)),
            default => null,
        };
    }

    /**
     * A parcel for each package Amazon lists. A package with no tracking
     * number is someone else's.
     *
     * @param  array<string, mixed>  $order
     * @return list<SourceParcel>
     */
    private function parcels(array $order): array
    {
        return collect($order['packages'] ?? [])
            ->filter(fn (mixed $package): bool => is_array($package))
            ->map(fn (array $package): SourceParcel => new SourceParcel(
                self::filled($package['carrier'] ?? null),
                self::filled($package['trackingNumber'] ?? null),
            ))
            ->values()
            ->all();
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
