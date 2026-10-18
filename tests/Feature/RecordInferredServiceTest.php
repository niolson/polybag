<?php

use App\DataTransferObjects\Shipping\ServiceInference;
use App\Enums\PostageSource;
use App\Models\DataSource;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

function inference(string $service = 'USPS Ground Advantage'): ServiceInference
{
    return ServiceInference::resolved($service, 'usps-impb-stc');
}

/** A shipped Package whose postage was bought through Shopify, the one source that reports no service. */
function shopifyBoughtPackage(?string $service = null): Package
{
    return Package::factory()->shipped()->create([
        'service' => $service,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory()->shopify()->create()->id,
    ]);
}

it('records an inferred service on a Shopify purchase that has none', function (): void {
    $package = shopifyBoughtPackage();

    expect($package->recordInferredService(inference()))->toBeTrue();

    $package->refresh();

    expect($package->service)->toBe('USPS Ground Advantage')
        ->and($package->activeLabel->service)->toBe('USPS Ground Advantage')
        ->and($package->serviceIsInferred())->toBeTrue();
});

it('never writes an inferred service over one the postage source reported', function (): void {
    $package = Package::factory()->shipped()->create(['service' => 'Priority Mail']);

    expect($package->recordInferredService(inference()))->toBeFalse();

    expect($package->refresh()->service)->toBe('Priority Mail');
});

it('never downgrades an inferred service when a later run resolves nothing', function (): void {
    $package = shopifyBoughtPackage('USPS Ground Advantage');

    expect($package->recordInferredService(ServiceInference::inconclusive('nothing matched')))->toBeFalse();

    expect($package->refresh()->service)->toBe('USPS Ground Advantage');
});

it('replaces an earlier inference with a different one', function (): void {
    $package = shopifyBoughtPackage('USPS Ground Advantage');

    expect($package->recordInferredService(inference('Priority Mail')))->toBeTrue();

    expect($package->refresh()->service)->toBe('Priority Mail')
        ->and($package->activeLabel->service)->toBe('Priority Mail');
});

it('leaves an inference that says the same thing alone', function (): void {
    $package = shopifyBoughtPackage('USPS Ground Advantage');

    expect($package->recordInferredService(inference('USPS Ground Advantage')))->toBeFalse();
});

it('keeps an inferred service out of anything a channel publishes', function (): void {
    $package = shopifyBoughtPackage();

    $package->recordInferredService(inference());

    expect($package->service)->toBe('USPS Ground Advantage')
        ->and($package->confirmedService())->toBeNull();
});

// The command runs over packages in bulk while shipping continues, so the guard
// has to hold against a write that lands between loading a package and writing
// to it -- not merely against the state the model was loaded with.
it('loses the race when the package stops being a Shopify purchase mid-run', function (): void {
    $package = shopifyBoughtPackage();

    DB::table('packages')->where('id', $package->id)->update([
        'postage_source' => PostageSource::CarrierAccount->value,
        'postage_data_source_id' => null,
        'service' => 'Priority Mail Express',
    ]);

    expect($package->recordInferredService(inference()))->toBeFalse();

    expect(Package::find($package->id)->service)->toBe('Priority Mail Express');
});

it('writes nothing for a package that was never persisted', function (): void {
    expect((new Package)->recordInferredService(inference()))->toBeFalse();
});

// --- withdrawInferredService() ---------------------------------------------
//
// The one inconclusive result that acts on an earlier inference: a run that
// found evidence *against* the stored value, not merely none for one.

it('withdraws an inferred service the current ruleset contradicts', function (): void {
    $package = shopifyBoughtPackage('USPS Ground Advantage');

    expect($package->withdrawInferredService(ServiceInference::contradicted('decoded one thing, selection names another')))->toBeTrue();

    $package->refresh();

    expect($package->service)->toBeNull()
        ->and($package->activeLabel->service)->toBeNull();
});

it('never withdraws a service the postage source reported, whatever the ladder says', function (): void {
    $package = Package::factory()->shipped()->create(['service' => 'Priority Mail']);

    expect($package->withdrawInferredService(ServiceInference::contradicted('decoded one thing, selection names another')))->toBeFalse();

    $package->refresh();

    expect($package->service)->toBe('Priority Mail')
        ->and($package->activeLabel->service)->toBe('Priority Mail');
});

it('withdraws nothing on a plain miss', function (): void {
    // A rung that no longer runs -- the label purged -- says nothing about
    // whether what it once read was right. Only a contradiction withdraws.
    $package = shopifyBoughtPackage('USPS Ground Advantage');

    expect($package->withdrawInferredService(ServiceInference::inconclusive('no readable label')))->toBeFalse()
        ->and($package->fresh()->service)->toBe('USPS Ground Advantage');
});
