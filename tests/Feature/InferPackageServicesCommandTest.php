<?php

use App\Enums\ServiceEvidence;
use App\Models\Package;

const COVERAGE_IMPB_GROUND_ADVANTAGE = '9300199999999900000011';
const COVERAGE_IMPB_UNLISTED_STC = '9299999999999900000036';

it('reports coverage without writing anything by default', function (): void {
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_GROUND_ADVANTAGE,
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    $this->artisan('app:infer-package-services')
        ->expectsOutputToContain('usps-impb-stc')
        ->expectsOutputToContain('Coverage: 1 of 1')
        ->assertSuccessful();

    expect(Package::first()->service)->toBeNull();
});

it('writes what it inferred under --apply', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_GROUND_ADVANTAGE,
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    $this->artisan('app:infer-package-services --apply')->assertSuccessful();

    expect($package->refresh()->service)->toBe('USPS Ground Advantage')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Inferred);
});

it('counts what each rung left unknown', function (): void {
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_UNLISTED_STC,
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
        'label_data' => null,
    ]);

    $this->artisan('app:infer-package-services')
        ->expectsOutputToContain('service type code names no product')
        ->expectsOutputToContain('Coverage: 0 of 1')
        ->assertSuccessful();
});

it('leaves a confirmed service out of the measurement entirely', function (): void {
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_GROUND_ADVANTAGE,
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Confirmed,
    ]);

    $this->artisan('app:infer-package-services')
        ->expectsOutputToContain('No packages with an unconfirmed service')
        ->assertSuccessful();
});

it('withdraws an earlier inference the current ruleset contradicts, under --apply', function (): void {
    // Inferred under the ruleset before the selection rung existed: the IMpb
    // decoded Ground Advantage. The purchase asked for Priority Mail and
    // Shopify named the carrier, so the current ruleset reads that selection as
    // honoured, finds it disagrees with the decode, and refuses both -- which
    // must take the stored value with it rather than leave it reported under a
    // ruleset that now contradicts it.
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_GROUND_ADVANTAGE,
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-09',
        'requested_service' => "Shopify's USPS Priority Mail",
        'metadata' => ['shopify_honoured_selection' => 'usps:Priority'],
    ]);

    $this->artisan('app:infer-package-services')
        ->expectsOutputToContain('decode disagrees with the honoured selection')
        ->assertSuccessful();

    expect($package->fresh()->service)->toBe('USPS Ground Advantage');

    $this->artisan('app:infer-package-services --apply')
        ->expectsOutputToContain('Withdrew 1 earlier inference(s)')
        ->assertSuccessful();

    $package->refresh();

    expect($package->service)->toBeNull()
        ->and($package->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->service_ruleset_version)->toBeNull()
        ->and($package->activeLabel->service)->toBeNull();
});
