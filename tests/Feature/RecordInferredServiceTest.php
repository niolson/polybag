<?php

use App\DataTransferObjects\Shipping\ServiceInference;
use App\Enums\ServiceEvidence;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

function inference(string $service = 'USPS Ground Advantage', string $version = '2026-09-06'): ServiceInference
{
    return ServiceInference::resolved($service, 'usps-impb-stc', $version);
}

it('records an inferred service over an unknown one', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
        'service_inference_method' => null,
        'service_ruleset_version' => null,
    ]);

    expect($package->recordInferredService(inference()))->toBeTrue();

    $package->refresh();

    expect($package->service)->toBe('USPS Ground Advantage')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Inferred)
        ->and($package->service_inference_method)->toBe('usps-impb-stc')
        ->and($package->service_ruleset_version)->toBe('2026-09-06');
});

it('never writes an inferred service over a confirmed one', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Confirmed,
    ]);

    expect($package->recordInferredService(inference()))->toBeFalse();

    $package->refresh();

    expect($package->service)->toBe('Priority Mail')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($package->service_inference_method)->toBeNull();
});

it('never downgrades an inferred service when a later run resolves nothing', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-06',
    ]);

    expect($package->recordInferredService(ServiceInference::inconclusive('nothing matched')))->toBeFalse();

    $package->refresh();

    expect($package->service)->toBe('USPS Ground Advantage')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Inferred);
});

it('replaces an inferred value and its version stamp together under a newer ruleset', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-06-24',
    ]);

    expect($package->recordInferredService(inference('Priority Mail', '2026-09-06')))->toBeTrue();

    $package->refresh();

    expect($package->service)->toBe('Priority Mail')
        ->and($package->service_ruleset_version)->toBe('2026-09-06');
});

it('leaves an inference from the same ruleset alone', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-06',
    ]);

    expect($package->recordInferredService(inference(version: '2026-09-06')))->toBeFalse();
});

it('refuses an inference from an older ruleset than the one on the package', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-06',
    ]);

    expect($package->recordInferredService(inference('USPS Ground Advantage', '2026-06-24')))->toBeFalse();

    $package->refresh();

    expect($package->service)->toBe('Priority Mail');
});

it('keeps an inferred service out of anything a channel publishes', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    $package->recordInferredService(inference());

    expect($package->service)->toBe('USPS Ground Advantage')
        ->and($package->confirmedService())->toBeNull();
});

// The command runs over packages in bulk while shipping continues, so the guards
// have to hold against a write that lands between loading a package and writing
// to it -- not merely against the state the model was loaded with.
it('loses the race when the postage source confirms the service mid-run', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    // Another process confirms it after this model was loaded.
    DB::table('packages')->where('id', $package->id)->update([
        'service' => 'Priority Mail Express',
        'service_evidence' => ServiceEvidence::Confirmed->value,
    ]);

    expect($package->recordInferredService(inference()))->toBeFalse();

    $fresh = Package::find($package->id);

    expect($fresh->service)->toBe('Priority Mail Express')
        ->and($fresh->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($fresh->service_inference_method)->toBeNull();
});

it('loses the race when a newer ruleset lands mid-run', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-06-24',
    ]);

    DB::table('packages')->where('id', $package->id)->update([
        'service' => 'Parcel Select',
        'service_ruleset_version' => '2026-12-01',
    ]);

    expect($package->recordInferredService(inference('Priority Mail', '2026-09-06')))->toBeFalse();

    expect(Package::find($package->id)->service)->toBe('Parcel Select');
});

it('writes nothing for a package that was never persisted', function (): void {
    expect((new Package(['service_evidence' => ServiceEvidence::Unknown]))->recordInferredService(inference()))
        ->toBeFalse();
});

// --- withdrawInferredService() ---------------------------------------------
//
// The one inconclusive result that acts on an earlier inference: a run that
// found evidence *against* the stored value, not merely none for one.

it('withdraws an inferred service the current ruleset contradicts', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-09',
    ]);

    expect($package->withdrawInferredService(ServiceInference::contradicted('decoded one thing, selection names another')))->toBeTrue();

    $package->refresh();

    expect($package->service)->toBeNull()
        ->and($package->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->service_inference_method)->toBeNull()
        ->and($package->service_ruleset_version)->toBeNull()
        ->and($package->activeLabel->service)->toBeNull()
        ->and($package->activeLabel->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->activeLabel->service_ruleset_version)->toBeNull();
});

it('never withdraws a confirmed service, whatever the ladder says', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Confirmed,
    ]);

    expect($package->withdrawInferredService(ServiceInference::contradicted('decoded one thing, selection names another')))->toBeFalse();

    $package->refresh();

    expect($package->service)->toBe('Priority Mail')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($package->activeLabel->service)->toBe('Priority Mail');
});

it('withdraws nothing on a plain miss', function (): void {
    // A rung that no longer runs -- the label purged -- says nothing about
    // whether what it once read was right. Only a contradiction withdraws.
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'label-text-zpl',
        'service_ruleset_version' => '2026-09-09',
    ]);

    expect($package->withdrawInferredService(ServiceInference::inconclusive('no readable label')))->toBeFalse()
        ->and($package->fresh()->service)->toBe('USPS Ground Advantage');
});

it('loses the race when the postage source confirms the service before the withdrawal lands', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-09',
    ]);

    DB::table('packages')->where('id', $package->id)->update([
        'service' => 'Priority Mail Express',
        'service_evidence' => ServiceEvidence::Confirmed->value,
        'service_inference_method' => null,
        'service_ruleset_version' => null,
    ]);
    DB::table('package_labels')->where('package_id', $package->id)->update([
        'service' => 'Priority Mail Express',
        'service_evidence' => ServiceEvidence::Confirmed->value,
        'service_inference_method' => null,
        'service_ruleset_version' => null,
    ]);

    expect($package->withdrawInferredService(ServiceInference::contradicted('stale')))->toBeFalse()
        ->and(Package::find($package->id)->service)->toBe('Priority Mail Express');
});
