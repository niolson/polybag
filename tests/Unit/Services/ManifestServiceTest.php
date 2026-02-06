<?php

use App\Models\Manifest;
use App\Models\Package;
use App\Services\ManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns unmanifested packages grouped by carrier', function (): void {
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111']);
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400222']);
    Package::factory()->shipped()->create(['carrier' => 'FedEx', 'tracking_number' => '7890001']);

    $grouped = ManifestService::getUnmanifestedPackages();

    expect($grouped)->toHaveKey('USPS')
        ->and($grouped)->toHaveKey('FedEx')
        ->and($grouped['USPS'])->toHaveCount(2)
        ->and($grouped['FedEx'])->toHaveCount(1);
});

it('excludes already manifested packages', function (): void {
    $manifest = Manifest::factory()->create();
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'manifest_id' => $manifest->id,
    ]);
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400222',
    ]);

    $grouped = ManifestService::getUnmanifestedPackages();

    expect($grouped['USPS'])->toHaveCount(1);
});

it('excludes unshipped packages', function (): void {
    Package::factory()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'shipped' => false,
    ]);

    $grouped = ManifestService::getUnmanifestedPackages();

    expect($grouped)->toBeEmpty();
});

it('excludes packages without tracking numbers', function (): void {
    Package::factory()->create([
        'carrier' => 'USPS',
        'tracking_number' => null,
        'shipped' => true,
        'shipped_at' => now(),
    ]);

    $grouped = ManifestService::getUnmanifestedPackages();

    expect($grouped)->toBeEmpty();
});

it('returns failure for unsupported carrier', function (): void {
    $packages = collect([Package::factory()->shipped()->create(['carrier' => 'DHL'])]);

    $result = ManifestService::createManifest('DHL', $packages);

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('Unsupported carrier');
});

it('returns failure for FedEx manifest stub', function (): void {
    $packages = collect([Package::factory()->shipped()->create(['carrier' => 'FedEx'])]);

    $result = ManifestService::createManifest('FedEx', $packages);

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('not yet implemented');
});
