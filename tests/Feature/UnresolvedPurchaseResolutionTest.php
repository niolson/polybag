<?php

use App\Contracts\PackageLabelWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageLabels\LabelVoidResult;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\Role;
use App\Enums\ServiceEvidence;
use App\Enums\VoidReason;
use App\Events\PackageShipped;
use App\Exceptions\PurchaseNotResolvableException;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Filament\Support\ResolveUnaccountedPurchaseAction;
use App\Filament\Widgets\ExceptionsWidget;
use App\Models\AuditLog;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\PackageShipping\PurchaseLock;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\UnresolvedPurchaseResolver;
use App\Services\ShipmentImport\PackageExportService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Issue `postage-source-split/16`: a person resolves a purchase nothing
 * accounts for, having checked the carrier or channel.
 */
function unshippedPackage(): Package
{
    return Package::factory()->create(['status' => PackageStatus::Unshipped]);
}

/**
 * A direct USPS purchase that never reported back.
 *
 * @param  array<string, mixed>  $attributes
 */
function strandedDirectOffer(Package $package, array $attributes = []): ShippingOffer
{
    return ShippingOffer::factory()->direct()->awaitingConfirmation()->create([
        'package_id' => $package->id,
        'price' => 8.40,
        'consumed_at' => now()->subDay(),
        ...$attributes,
    ]);
}

function resolver(): UnresolvedPurchaseResolver
{
    return app(UnresolvedPurchaseResolver::class);
}

// --- Which offers are unaccounted ---------------------------------------------

it('counts an unanswered purchase and a confirmed one never saved, and nothing settled', function (): void {
    $package = unshippedPackage();

    $awaiting = strandedDirectOffer($package);
    $confirmedUnsaved = ShippingOffer::factory()->direct()->consumed()->create(['package_id' => $package->id]);
    ShippingOffer::factory()->direct()->declined()->create(['package_id' => $package->id]);
    ShippingOffer::factory()->direct()->create(['package_id' => $package->id]);

    $recorded = Package::factory()->shipped()->create();
    ShippingOffer::factory()->direct()->consumed()->create([
        'package_id' => $recorded->id,
        'consumed_at' => $recorded->activeLabel->created_at->subMinute(),
    ]);

    expect(ShippingOffer::query()->unaccounted()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$awaiting->id, $confirmedUnsaved->id])->sort()->values()->all());
});

// --- A label exists --------------------------------------------------------------

it('ships the package on the offer\'s facts and the entered tracking number', function (): void {
    $package = unshippedPackage();
    $priority = CarrierService::factory()->uspsPriority()->create();
    $offer = strandedDirectOffer($package, ['carrier_service_id' => $priority->id]);
    $manager = User::factory()->manager()->create();

    resolver()->recordLabel($offer, ' 9200190380793700250067 ', $manager, 'Found it in the USPS account');

    $package->refresh();
    $label = $package->activeLabel;

    expect($package->status)->toBe(PackageStatus::Shipped)
        ->and($package->tracking_number)->toBe('9200190380793700250067')
        ->and($package->carrier)->toBe('USPS')
        ->and($package->service)->toBe('Priority Mail')
        ->and((float) $package->cost)->toBe(8.40)
        ->and($package->postage_source)->toBe(PostageSource::CarrierAccount)
        ->and($package->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($package->label_data)->toBeNull()
        ->and($package->shipped_by_user_id)->toBe($manager->id)
        ->and($label->carrier_service_id)->toBe($priority->id)
        ->and($offer->fresh()->purchase_reference)->toBe('9200190380793700250067')
        ->and(ShippingOffer::query()->unaccounted()->exists())->toBeFalse()
        ->and(app(OfferStore::class)->hasUnresolvedPurchase($package))->toBeFalse();

    $audit = AuditLog::query()->where('action', AuditAction::PurchaseResolvedByHand)->sole();

    expect($audit->user_id)->toBe($manager->id)
        ->and((int) $audit->auditable_id)->toBe($package->id)
        ->and($audit->new_values)->toBe(['outcome' => 'label_recorded', 'tracking_number' => '9200190380793700250067'])
        ->and($audit->metadata['offer'])->toBe($offer->public_id)
        ->and($audit->metadata['note'])->toBe('Found it in the USPS account');
});

it('ships a sale the source confirmed, keeping its reference as the label\'s', function (): void {
    $package = unshippedPackage();
    $offer = ShippingOffer::factory()->direct()->consumed()->create([
        'package_id' => $package->id,
        'purchase_reference' => '9200190380793700250067',
    ]);

    resolver()->recordLabel($offer, '9200190380793700250067', User::factory()->manager()->create());

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($package->activeLabel()->value('source_label_reference'))->toBe('9200190380793700250067');
});

it('dates the label the day it was bought', function (): void {
    $package = unshippedPackage();
    $offer = strandedDirectOffer($package, ['consumed_at' => now()->subDays(3)->setTime(15, 0)]);

    resolver()->recordLabel($offer, 'TRACK-1', User::factory()->manager()->create());

    expect($package->fresh()->ship_date->toDateString())->toBe(now()->subDays(3)->toDateString());
});

it('refuses to record a label without a tracking number, or one bought through a connection that is gone', function (): void {
    $package = unshippedPackage();
    $manager = User::factory()->manager()->create();

    expect(fn (): Package => resolver()->recordLabel(strandedDirectOffer($package), '  ', $manager))
        ->toThrow(PurchaseNotResolvableException::class, 'Enter the tracking number');

    // The factory's default is a channel offer with no connection left.
    $orphaned = ShippingOffer::factory()->awaitingConfirmation()->create(['package_id' => $package->id]);

    expect(fn (): Package => resolver()->recordLabel($orphaned, 'TRACK-1', $manager))
        ->toThrow(PurchaseNotResolvableException::class, 'no longer exists');

    expect($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and(ShippingOffer::query()->unaccounted()->count())->toBe(2);
});

it('refuses to record a second label on a package that already has one', function (): void {
    $package = Package::factory()->shipped()->create();
    $offer = strandedDirectOffer($package);

    expect(fn (): Package => resolver()->recordLabel($offer, 'TRACK-2', User::factory()->manager()->create()))
        ->toThrow(PurchaseNotResolvableException::class, 'already has an active label');

    expect($offer->fresh()->isAwaitingPurchaseConfirmation())->toBeTrue();
});

// --- Nothing was bought ----------------------------------------------------------

it('settles an unanswered purchase as nothing bought, freeing the package', function (): void {
    $package = unshippedPackage();
    $offer = strandedDirectOffer($package);
    $manager = User::factory()->manager()->create(['name' => 'Pat Doe']);

    resolver()->recordNothingBought($offer, $manager, 'Nothing in the USPS account');

    $offer->refresh();

    expect($offer->purchase_failed_at)->not->toBeNull()
        ->and($offer->purchase_failure_reason)->toBe('Resolved by hand by Pat Doe: no label was bought')
        ->and(app(OfferStore::class)->hasUnresolvedPurchase($package))->toBeFalse()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    expect(AuditLog::query()->where('action', AuditAction::PurchaseResolvedByHand)->sole()->new_values)
        ->toBe(['outcome' => 'nothing_bought']);
});

it('refuses to call a confirmed sale nothing bought, and a purchase resolved twice', function (): void {
    $package = unshippedPackage();
    $manager = User::factory()->manager()->create();
    $confirmed = ShippingOffer::factory()->direct()->consumed()->create(['package_id' => $package->id]);

    expect(fn () => resolver()->recordNothingBought($confirmed, $manager))
        ->toThrow(PurchaseNotResolvableException::class, 'confirmed it sold this label');

    $offer = strandedDirectOffer($package);
    resolver()->recordNothingBought($offer, $manager);

    expect(fn () => resolver()->recordNothingBought($offer, $manager))
        ->toThrow(PurchaseNotResolvableException::class, 'already been resolved');
});

// --- Package page ----------------------------------------------------------------

function askAgain(): TestAction
{
    return TestAction::make('askAgain')->schemaComponent(ResolveUnaccountedPurchaseAction::CALLOUT_KEY, 'infolist');
}

function recordWhatWasFound(): TestAction
{
    return TestAction::make('recordWhatWasFound')->schemaComponent(ResolveUnaccountedPurchaseAction::CALLOUT_KEY, 'infolist');
}

it('explains an unfinished purchase on the package page, and offers a manager both steps', function (): void {
    $package = unshippedPackage();

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertDontSee('Unfinished label purchase');

    strandedDirectOffer($package, ['service_name' => 'USPS Ground Advantage', 'price' => 8.40]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSee('Unfinished label purchase')
        ->assertSee('PolyBag tried to buy a USPS Ground Advantage label for $8.40')
        ->assertSee('Ask USPS again first')
        ->assertActionVisible(askAgain())
        ->assertActionVisible(recordWhatWasFound());
});

it('tells a shipper a manager has to settle it, and offers them neither step', function (): void {
    $package = unshippedPackage();
    strandedDirectOffer($package);

    $this->actingAs(User::factory()->create(['role' => Role::User]));

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSee('A manager needs to settle this')
        ->assertActionHidden(askAgain())
        ->assertActionHidden(recordWhatWasFound());
});

it('says a confirmed sale was bought and never saved', function (): void {
    $package = unshippedPackage();
    ShippingOffer::factory()->direct()->consumed()->create(['package_id' => $package->id]);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSee('but PolyBag failed to save it');
});

it('keeps a confirmed label from the package page, prefilled with the tracking number the carrier reported', function (): void {
    $package = unshippedPackage();
    $offer = ShippingOffer::factory()->direct()->consumed()->create([
        'package_id' => $package->id,
        'purchase_reference' => '9200190380793700250067',
    ]);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->mountAction(recordWhatWasFound())
        ->assertSchemaStateSet(['tracking_number' => '9200190380793700250067', 'outcome' => 'void_label'])
        ->fillForm(['outcome' => 'label_exists'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Label kept')
        ->assertDontSee('Unfinished label purchase');

    expect($package->fresh()->tracking_number)->toBe('9200190380793700250067')
        ->and($offer->fresh()->purchase_reference)->toBe('9200190380793700250067');
});

it('records that there is no label, freeing the package', function (): void {
    $package = unshippedPackage();
    strandedDirectOffer($package);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction(recordWhatWasFound(), data: ['outcome' => 'nothing_bought'])
        ->assertNotified('Recorded: no label');

    expect(ShippingOffer::query()->unaccounted()->count())->toBe(0);
});

it('does not offer no label for a sale the source confirmed', function (): void {
    $package = unshippedPackage();
    ShippingOffer::factory()->direct()->consumed()->create(['package_id' => $package->id]);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction(recordWhatWasFound(), data: ['outcome' => 'nothing_bought'])
        ->assertHasActionErrors(['outcome']);

    expect(ShippingOffer::query()->unaccounted()->count())->toBe(1);
});

it('shows a refusal rather than recording it', function (): void {
    $package = unshippedPackage();
    // A channel offer whose connection is gone.
    ShippingOffer::factory()->awaitingConfirmation()->create(['package_id' => $package->id]);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction(recordWhatWasFound(), data: ['outcome' => 'label_exists', 'tracking_number' => '1Z999'])
        ->assertNotified('Not recorded');

    expect(ShippingOffer::query()->unaccounted()->count())->toBe(1)
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('leaves out an unanswered FedEx purchase, which the next attempt settles, but not a confirmed one', function (): void {
    $usps = strandedDirectOffer(unshippedPackage());
    $fedexPackage = unshippedPackage();
    strandedDirectOffer($fedexPackage, ['carrier' => 'FedEx', 'service_name' => 'FedEx Ground®']);
    $fedexConfirmed = ShippingOffer::factory()->direct()->consumed()->create(['carrier' => 'FedEx', 'service_name' => 'FedEx Ground®']);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $fedexPackage->id])
        ->assertDontSee('Unfinished label purchase');

    // FedEx cannot be asked, so a confirmed FedEx sale offers only the second step.
    Livewire::test(ViewPackage::class, ['record' => $fedexConfirmed->package_id])
        ->assertActionHidden(askAgain())
        ->assertActionVisible(recordWhatWasFound())
        ->assertSee('No other label can be bought until it is voided or kept.');

    expect(resolver()->needingAPerson()->pluck('id')->all())->toEqualCanonicalizing([$usps->id, $fedexConfirmed->id]);
});

it('asks the carrier again from the package page, passing this workstation\'s label format, and says what came back', function (): void {
    $usps = strandedDirectOffer(unshippedPackage());
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $workflow = Mockery::mock(PackageShippingWorkflow::class);
    $workflow->shouldReceive('checkEarlierPurchases')
        ->once()
        ->withArgs(fn (Package $package, string $format, ?int $dpi, ?int $userId): bool => $package->is($usps->package)
            && $format === 'zpl' && $dpi === 300 && $userId === $manager->id)
        ->andReturn(PackageShippingResult::offerUnavailable('Unfinished Label Purchase', 'USPS did not answer.'));
    app()->instance(PackageShippingWorkflow::class, $workflow);

    Livewire::test(ViewPackage::class, ['record' => $usps->package_id])
        ->callAction(askAgain(), data: ['label_format' => 'zpl', 'label_dpi' => '300'])
        ->assertNotified('Still no answer from USPS');
});

it('stops offering ask again once the source said it will never send the label, and says why', function (): void {
    $package = unshippedPackage();
    ShippingOffer::factory()->direct()->consumed()->create([
        'package_id' => $package->id,
        'purchase_context' => [OfferStore::UNRECOVERABLE_REASON => 'USPS will not send a label again after its mailing date of Sep 29, 2026.'],
    ]);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSee('USPS will not send a label again after its mailing date of Sep 29, 2026.')
        ->assertSee('until it is voided or kept')
        ->assertActionHidden(askAgain())
        ->assertActionVisible(recordWhatWasFound());
});

it('says so when asking again finds the source will never send the label', function (): void {
    $usps = strandedDirectOffer(unshippedPackage());
    $this->actingAs(User::factory()->manager()->create());

    $workflow = Mockery::mock(PackageShippingWorkflow::class);
    $workflow->shouldReceive('checkEarlierPurchases')->once()->andReturnUsing(function () use ($usps): PackageShippingResult {
        app(OfferStore::class)->recordUnrecoverableLabel($usps, 'USPS will not send a label again after its mailing date of Sep 29, 2026.');

        return PackageShippingResult::offerUnavailable(PackageShippingResult::UNFINISHED_PURCHASE, 'Refused.');
    });
    app()->instance(PackageShippingWorkflow::class, $workflow);

    Livewire::test(ViewPackage::class, ['record' => $usps->package_id])
        ->callAction(askAgain())
        ->assertNotified('USPS cannot send this label again');
});

it('tells the operator when asking again settled the purchase', function (): void {
    $usps = strandedDirectOffer(unshippedPackage());
    $this->actingAs(User::factory()->manager()->create());

    $workflow = Mockery::mock(PackageShippingWorkflow::class);
    $workflow->shouldReceive('checkEarlierPurchases')->once()->andReturnNull();
    app()->instance(PackageShippingWorkflow::class, $workflow);

    Livewire::test(ViewPackage::class, ['record' => $usps->package_id])
        ->callAction(askAgain())
        ->assertNotified('Nothing was bought');
});

// --- Finding them ------------------------------------------------------------------

it('filters the Packages list to packages with an unfinished purchase', function (): void {
    $blocked = unshippedPackage();
    strandedDirectOffer($blocked);
    $settled = unshippedPackage();
    ShippingOffer::factory()->direct()->declined()->create(['package_id' => $settled->id]);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ListPackages::class)
        ->filterTable('unresolved_purchase', true)
        ->assertCanSeeTableRecords([$blocked])
        ->assertCanNotSeeTableRecords([$settled]);
});

it('counts packages with an unfinished purchase on the Exceptions widget, linking to that filter', function (): void {
    Cache::flush();
    $package = unshippedPackage();
    strandedDirectOffer($package);
    strandedDirectOffer($package);

    Livewire::actingAs(User::factory()->manager()->create())
        ->test(ExceptionsWidget::class)
        ->assertSee('Unfinished Label Purchases')
        ->assertSeeHtml(e(PackageResource::unresolvedPurchasesUrl()));

    expect(resolver()->needingAPerson()->distinct()->count('package_id'))->toBe(1);
});

it('asks what to do with a confirmed sale, and what was found for one nobody heard back about', function (): void {
    $package = unshippedPackage();
    $confirmed = ShippingOffer::factory()->direct()->consumed()->create([
        'package_id' => $package->id,
        'service_name' => 'USPS Ground Advantage Machinable Single-piece',
        'price' => 8.40,
    ]);
    $awaiting = strandedDirectOffer($package);

    expect(array_keys(ResolveUnaccountedPurchaseAction::outcomes($confirmed)))->toBe(['void_label', 'label_exists', 'already_voided'])
        ->and(array_keys(ResolveUnaccountedPurchaseAction::outcomes($awaiting)))->toBe(['void_label', 'label_exists', 'already_voided', 'nothing_bought'])
        ->and(ResolveUnaccountedPurchaseAction::describe($confirmed))
        ->toStartWith('USPS Ground Advantage Machinable Single-piece, $8.40')
        ->and(ResolveUnaccountedPurchaseAction::describe($awaiting))->toStartWith('USPS Priority Mail, $8.40');
});

// --- Void it, or already voided ---------------------------------------------------

it('records a confirmed label and voids it through the source in one step', function (): void {
    $package = unshippedPackage();
    $offer = ShippingOffer::factory()->direct()->consumed()->create([
        'package_id' => $package->id,
        'purchase_reference' => '9200190380793700250067',
    ]);
    $manager = User::factory()->manager()->create();

    $labels = Mockery::mock(PackageLabelWorkflow::class);
    $labels->shouldReceive('voidLabel')
        ->once()
        ->withArgs(fn (Package $voided, User $user): bool => $voided->is($package)
            && $voided->status === PackageStatus::Shipped
            && $voided->tracking_number === '9200190380793700250067'
            && $user->is($manager))
        ->andReturn(LabelVoidResult::success('Label voided successfully.'));
    app()->instance(PackageLabelWorkflow::class, $labels);

    $result = resolver()->recordLabelAndVoid($offer, '9200190380793700250067', $manager);

    expect($result->success)->toBeTrue()
        ->and(ShippingOffer::query()->unaccounted()->count())->toBe(0)
        ->and(AuditLog::where('action', AuditAction::PurchaseResolvedByHand)->latest('id')->first()->new_values['outcome'])
        ->toBe('label_recorded_to_void');
});

it('records a label already voided at the source without asking it, freeing the package', function (): void {
    $package = unshippedPackage();
    $offer = ShippingOffer::factory()->direct()->consumed()->create([
        'package_id' => $package->id,
        'purchase_reference' => '9200190380793700250067',
    ]);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction(recordWhatWasFound(), data: ['outcome' => 'already_voided'])
        ->assertHasNoActionErrors()
        ->assertNotified('Label voided');

    expect($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($package->labels()->sole()->tracking_number)->toBe('9200190380793700250067')
        ->and($package->labels()->sole()->voided_at)->not->toBeNull()
        ->and(ShippingOffer::query()->unaccounted()->count())->toBe(0)
        ->and($offer->fresh()->purchase_reference)->toBe('9200190380793700250067');
});

it('says the label is recorded when voiding it fails', function (): void {
    $package = unshippedPackage();
    ShippingOffer::factory()->direct()->consumed()->create(['package_id' => $package->id]);

    $labels = Mockery::mock(PackageLabelWorkflow::class);
    $labels->shouldReceive('voidLabel')->once()->andReturn(LabelVoidResult::failure('Void failed', 'USPS returned status 400.'));
    app()->instance(PackageLabelWorkflow::class, $labels);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->callAction(recordWhatWasFound(), data: ['outcome' => 'void_label'])
        ->assertNotified('Recorded, not voided');

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and(ShippingOffer::query()->unaccounted()->count())->toBe(0);
});

// --- Review: lock, announcing, evidence, Amazon identifiers --------------------------

it('will not settle a purchase while the package\'s purchase lock is held', function (): void {
    $package = unshippedPackage();
    $offer = strandedDirectOffer($package);
    $lock = PurchaseLock::for($package);
    $lock->get();

    try {
        expect(fn () => resolver()->recordNothingBought($offer, User::factory()->manager()->create()))
            ->toThrow(PurchaseNotResolvableException::class, 'being bought or checked right now');
    } finally {
        $lock->release();
    }

    expect($offer->fresh()->purchase_failed_at)->toBeNull();
});

it('holds a label recorded to be voided from every export path, releasing it only if the void is refused', function (string $outcome): void {
    Event::fake([PackageShipped::class]);
    $package = unshippedPackage();
    $offer = ShippingOffer::factory()->direct()->consumed()->create(['package_id' => $package->id]);

    $labels = Mockery::mock(PackageLabelWorkflow::class);
    $labels->shouldReceive('voidLabel')->once()->andReturnUsing(function (Package $shipped) use ($outcome): LabelVoidResult {
        // While USPS is being asked: shipped, but held from the scheduled export.
        expect($shipped->fresh()->status)->toBe(PackageStatus::Shipped)
            ->and($shipped->fresh()->exported)->toBeTrue()
            ->and(app(PackageExportService::class)->previewUnexported()->pluck('id'))->not->toContain($shipped->id);

        return match ($outcome) {
            'voided' => tap(LabelVoidResult::success(), fn () => $shipped->clearShipping(VoidReason::Operator, null)),
            'voided, not recorded' => LabelVoidResult::voidedNotRecorded('The label was voided, but PolyBag could not record it.'),
            default => LabelVoidResult::failure('Void failed', 'USPS returned status 400.'),
        };
    });
    app()->instance(PackageLabelWorkflow::class, $labels);

    resolver()->recordLabelAndVoid($offer, (string) $offer->purchase_reference, User::factory()->manager()->create());

    if ($outcome === 'refused') {
        Event::assertDispatchedTimes(PackageShipped::class, 1);
        expect($package->fresh()->exported)->toBeFalse();
    } else {
        Event::assertNotDispatched(PackageShipped::class);
    }

    if ($outcome === 'voided, not recorded') {
        // Still reads as shipped, on a label that no longer exists: kept held.
        expect($package->fresh()->status)->toBe(PackageStatus::Shipped)
            ->and($package->fresh()->exported)->toBeTrue();
    }
})->with(['voided', 'voided, not recorded', 'refused']);

it('does not announce a label recorded as already voided', function (): void {
    Event::fake([PackageShipped::class]);
    $package = unshippedPackage();
    $offer = ShippingOffer::factory()->direct()->consumed()->create(['package_id' => $package->id]);

    resolver()->recordLabelAlreadyVoided($offer, (string) $offer->purchase_reference, User::factory()->manager()->create());

    expect($package->fresh()->status)->toBe(PackageStatus::Unshipped);
    Event::assertNotDispatched(PackageShipped::class);
});

it('treats a source saying the label exists as a sale, even with no reply recorded', function (): void {
    $package = unshippedPackage();
    $offer = strandedDirectOffer($package, [
        'purchase_context' => [OfferStore::UNRECOVERABLE_REASON => 'USPS will not send a label again after its mailing date of Sep 29, 2026.'],
    ]);

    expect($offer->isKnownSold())->toBeTrue()
        ->and(array_keys(ResolveUnaccountedPurchaseAction::outcomes($offer)))->toBe(['void_label', 'label_exists', 'already_voided'])
        ->and(fn () => resolver()->recordNothingBought($offer, User::factory()->manager()->create()))
        ->toThrow(PurchaseNotResolvableException::class);

    expect($offer->fresh()->isAwaitingPurchaseConfirmation())->toBeTrue();
});

it('keeps Amazon\'s shipment and carrier identifiers on a label recorded by hand, so it can be voided and tracked', function (): void {
    $package = unshippedPackage();
    $offer = ShippingOffer::factory()->consumed()->create([
        'package_id' => $package->id,
        'postage_data_source_id' => DataSource::factory()->amazon()->create()->id,
        'carrier' => 'USPS',
        'service_name' => 'USPS Ground Advantage',
        'purchase_reference' => 'amzn-shipment-123',
        'rate_metadata' => ['amazonCarrierId' => 'USPS', 'amazonServiceId' => 'std-us-swa-mfn'],
    ]);

    resolver()->recordLabel($offer, '9400111899223456789012', User::factory()->manager()->create());

    $package->refresh();

    expect(AmazonBuyShippingAdapter::shipmentIdFor($package))->toBe('amzn-shipment-123')
        ->and($package->metadata[AmazonBuyShippingAdapter::CARRIER_ID_KEY])->toBe('USPS')
        ->and($package->metadata[AmazonBuyShippingAdapter::SERVICE_ID_KEY])->toBe('std-us-swa-mfn')
        ->and($package->labels()->sole()->source_label_reference)->toBe('amzn-shipment-123');
});
