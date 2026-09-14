<?php

use App\Enums\AuditAction;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\VoidReason;
use App\Models\AuditLog;
use App\Models\Carrier;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShipmentItem;

function voidedPackageAudit(Package $package): AuditLog
{
    $audit = AuditLog::where('auditable_type', Package::class)
        ->where('auditable_id', $package->id)
        ->where('action', AuditAction::PackageCancelled)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();

    return $audit;
}

it('records the pre-void label in old_values when a label is voided', function (): void {
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
    $carrier = Carrier::factory()->create();

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => '9400111899223197428490',
        'carrier' => 'USPS',
        'normalized_carrier_id' => $carrier->id,
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Confirmed,
        'cost' => 12.34,
        'postage_source' => PostageSource::CarrierAccount,
        'label_printed_at' => now()->subMinutes(5),
        'shipped_at' => now()->subMinutes(10),
        'ship_date' => today(),
    ]);

    $before = $package->replicate();

    $package->clearShipping(VoidReason::Operator);

    $old = voidedPackageAudit($package)->old_values;

    expect($old)->not->toBeNull()
        ->and($old['tracking_number'])->toBe('9400111899223197428490')
        ->and($old['carrier'])->toBe('USPS')
        ->and($old['normalized_carrier_id'])->toBe($carrier->id)
        ->and($old['service'])->toBe('Priority Mail')
        ->and($old['service_evidence'])->toBe(ServiceEvidence::Confirmed->value)
        ->and($old['cost'])->toBe('12.34')
        ->and($old['postage_source'])->toBe(PostageSource::CarrierAccount->value)
        ->and($old['label_printed_at'])->toBe($before->label_printed_at->toIso8601String())
        ->and($old['shipped_at'])->toBe($before->shipped_at->toIso8601String())
        ->and($old['ship_date'])->toBe($before->ship_date->toDateString());

    // Sanity: the model really was cleared, so these could only have come from a snapshot.
    expect($package->refresh()->tracking_number)->toBeNull()
        ->and($package->label_printed_at)->toBeNull();
});

it('captures print state from the database row, not the stale model instance', function (): void {
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'label_printed_at' => null,
    ]);

    expect($package->label_printed_at)->toBeNull();

    // The printer acknowledges the print in a separate request, after this
    // instance was loaded and before the packer voids.
    $printedAt = now()->subMinute()->startOfSecond();
    Package::query()->whereKey($package->id)->update(['label_printed_at' => $printedAt]);

    $package->clearShipping(VoidReason::Operator);

    $old = voidedPackageAudit($package)->old_values;

    expect($old['label_printed_at'])->toBe($printedAt->toIso8601String());
});
