<?php

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;

it('marks a package as shipped from ShipResponse', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create();

    $response = ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('PDF content'),
        labelOrientation: 'portrait',
    );

    $package->markShipped($response);
    $package->refresh();

    expect($package->tracking_number)->toBe('9400111899223456789012')
        ->and((float) $package->cost)->toBe(8.50)
        ->and($package->carrier)->toBe('USPS')
        ->and($package->service)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($package->label_data)->toBe(base64_encode('PDF content'))
        ->and($package->label_orientation)->toBe('portrait')
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->shipped_at)->not->toBeNull()
        ->and($package->shipment->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('clears all shipping fields', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create();

    $package->clearShipping();
    $package->refresh();

    expect($package->tracking_number)->toBeNull()
        ->and($package->carrier)->toBeNull()
        ->and($package->service)->toBeNull()
        ->and($package->cost)->toBeNull()
        ->and($package->label_data)->toBeNull()
        ->and($package->label_orientation)->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->shipped_at)->toBeNull()
        ->and($package->shipped_by_user_id)->toBeNull()
        ->and($package->shipment->fresh()->status)->toBe(ShipmentStatus::Open);
});

it('sets shipped_by_user_id when provided', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create();
    $user = User::factory()->create();

    $response = ShipResponse::success(
        trackingNumber: '9400111899223456789012',
        cost: 8.50,
        carrier: 'USPS',
        service: 'USPS_GROUND_ADVANTAGE',
        labelData: base64_encode('PDF content'),
        labelOrientation: 'portrait',
    );

    $package->markShipped($response, $user->id);
    $package->refresh();

    expect($package->shipped_by_user_id)->toBe($user->id)
        ->and($package->shippedBy->id)->toBe($user->id);
});

it('preserves dimension fields when clearing shipping', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create([
        'weight' => 5.00,
        'height' => 10.00,
        'width' => 8.00,
        'length' => 6.00,
    ]);

    $package->clearShipping();
    $package->refresh();

    expect((float) $package->weight)->toBe(5.00)
        ->and((float) $package->height)->toBe(10.00)
        ->and((float) $package->width)->toBe(8.00)
        ->and((float) $package->length)->toBe(6.00);
});
