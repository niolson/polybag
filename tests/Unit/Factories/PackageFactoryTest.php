<?php

use App\Enums\PackageStatus;
use App\Models\BoxSize;
use App\Models\Manifest;
use App\Models\Package;

it('creates a basic package', function (): void {
    $package = Package::factory()->create();

    expect($package)->toBeInstanceOf(Package::class)
        ->and($package->weight)->not->toBeNull()
        ->and($package->height)->not->toBeNull()
        ->and($package->width)->not->toBeNull()
        ->and($package->length)->not->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->tracking_number)->toBeNull();
});

it('creates a shipped package', function (): void {
    $package = Package::factory()->shipped()->create();

    expect($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->tracking_number)->not->toBeNull()
        ->and($package->carrier)->toBeIn(['USPS', 'FedEx'])
        ->and($package->service)->toBe('Ground')
        ->and($package->cost)->not->toBeNull()
        ->and($package->label_data)->not->toBeNull()
        ->and($package->shipped_at)->not->toBeNull()
        ->and($package->shipped_by_user_id)->not->toBeNull();
});

it('creates a package with label but not shipped', function (): void {
    $package = Package::factory()->withLabel()->create();

    expect($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->label_data)->not->toBeNull()
        ->and($package->label_orientation)->toBe('portrait');
});

it('creates a USPS shipped package', function (): void {
    $package = Package::factory()->usps()->create();

    expect($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->carrier)->toBe('USPS')
        ->and($package->service)->toBe('Priority Mail')
        ->and($package->tracking_number)->toStartWith('94');
});

it('creates a FedEx shipped package', function (): void {
    $package = Package::factory()->fedex()->create();

    expect($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->carrier)->toBe('FedEx')
        ->and($package->service)->toBe('FedEx Ground')
        ->and($package->tracking_number)->toHaveLength(12);
});

it('creates a package with box size', function (): void {
    $package = Package::factory()->withBoxSize()->create();

    expect($package->box_size_id)->not->toBeNull()
        ->and($package->boxSize)->toBeInstanceOf(BoxSize::class);
});

it('creates an exported package', function (): void {
    $package = Package::factory()->exported()->create();

    expect($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->exported)->toBeTrue();
});

it('creates a manifested package', function (): void {
    $package = Package::factory()->manifested()->create();

    expect($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->manifest_id)->not->toBeNull()
        ->and($package->manifest)->toBeInstanceOf(Manifest::class);
});

it('can chain multiple states', function (): void {
    $package = Package::factory()
        ->withBoxSize()
        ->shipped()
        ->create(['exported' => true]);

    expect($package->box_size_id)->not->toBeNull()
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->exported)->toBeTrue();
});
