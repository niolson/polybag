<?php

use App\Models\Manifest;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('has fillable attributes', function (): void {
    $manifest = Manifest::factory()->create([
        'carrier' => 'USPS',
        'manifest_number' => '1234567890',
        'manifest_date' => '2026-01-29',
        'package_count' => 5,
    ]);

    expect($manifest->carrier)->toBe('USPS')
        ->and($manifest->manifest_number)->toBe('1234567890')
        ->and($manifest->package_count)->toBe(5);
});

it('casts manifest_date to a date', function (): void {
    $manifest = Manifest::factory()->create([
        'manifest_date' => '2026-01-29',
    ]);

    expect($manifest->manifest_date)->toBeInstanceOf(Carbon::class)
        ->and($manifest->manifest_date->format('Y-m-d'))->toBe('2026-01-29');
});

it('casts package_count to integer', function (): void {
    $manifest = Manifest::factory()->create([
        'package_count' => '7',
    ]);

    expect($manifest->package_count)->toBe(7)
        ->and($manifest->package_count)->toBeInt();
});

it('has many packages', function (): void {
    $manifest = Manifest::factory()->create();
    $packages = Package::factory()->shipped()->count(3)->create([
        'manifest_id' => $manifest->id,
    ]);

    expect($manifest->packages)->toHaveCount(3)
        ->and($manifest->packages->first())->toBeInstanceOf(Package::class);
});
