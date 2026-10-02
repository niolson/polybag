<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Package;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\Sources\ShopifySource;

/**
 * Takes a voided Label's tracking number back off its Shopify order.
 *
 * A direct Label on a Shopify order reaches Shopify as a fulfillment, made by
 * the export. Voiding the Label at the carrier leaves that fulfillment in place,
 * still carrying the dead number to the customer, and keeps the fulfillment
 * order closed, so neither a re-export nor a Shopify Shipping purchase could go
 * through (`project-review/09`). Cancelling it reopens the goods. The shipment
 * then moves to the fulfillment order they reopened on, and the re-shipped
 * Label exports as a first export.
 *
 * Runs after the void is recorded and never undoes it: the carrier has already
 * voided the label, so failing the void would only leave a dead label marked
 * live. A failed cancel is reported to the operator instead.
 */
class ShopifyFulfillmentCanceller
{
    public function __construct(
        private readonly ShopifyShippingLabelService $labelService,
        private readonly ShopifyFulfillmentOrderReplacer $replacer,
        private readonly DataSourceFactory $dataSourceFactory,
    ) {}

    /**
     * Cancel the fulfillment the voided Label's export created, if it made one.
     *
     * @return string|null what the operator has to put right in Shopify, or null when nothing
     */
    public function cancelAfterVoid(Package $package, ?string $fulfillmentId): ?string
    {
        if (blank($fulfillmentId)) {
            return null;
        }

        $dataSource = $this->labelService->dataSourceFor($package);

        if ($dataSource === null) {
            logger()->warning('No active Shopify connection to cancel a voided label\'s fulfillment through', [
                'package_id' => $package->id,
                'shopify_fulfillment_id' => $fulfillmentId,
            ]);

            return self::manualCancelMessage('its Shopify connection is inactive or gone');
        }

        try {
            $source = $this->dataSourceFactory->make($dataSource);

            if (! $source instanceof ShopifySource) {
                return self::manualCancelMessage('its connection is not a Shopify connection');
            }

            $source->cancelFulfillment($fulfillmentId);
        } catch (\Exception $e) {
            logger()->warning('Could not cancel the Shopify fulfillment of a voided label', [
                'package_id' => $package->id,
                'shopify_fulfillment_id' => $fulfillmentId,
                'error' => $e->getMessage(),
            ]);

            return self::manualCancelMessage($e->getMessage());
        }

        AuditLog::record(
            action: AuditAction::ShipmentUpdated,
            auditable: $package->shipment,
            metadata: [
                'reason' => 'Shopify fulfillment cancelled after its label was voided',
                'package_id' => $package->id,
                'shopify_fulfillment_id' => $fulfillmentId,
            ],
        );

        $this->replacer->repoint($package);

        return null;
    }

    private static function manualCancelMessage(string $reason): string
    {
        return "Shopify still shows the voided tracking number, because PolyBag could not cancel the order's fulfillment: {$reason}. "
            .'Cancel the fulfillment in the Shopify admin before shipping this package again.';
    }
}
