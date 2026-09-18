<?php

use App\DataTransferObjects\PostageSources\OfferDraft;
use App\Enums\OfferRejection;
use App\Enums\PostageSource;
use App\Enums\SourceEnvironment;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Setting;
use App\Models\ShippingOffer;
use App\Services\PostageSources\OfferStore;
use App\Services\SettingsService;

function amazonOfferDraft(?DataSource $source = null): OfferDraft
{
    return new OfferDraft(
        carrier: 'OnTrac',
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: $source?->id,
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'OnTrac Ground',
        price: 5.79,
        currency: 'USD',
        rateMetadata: ['mailClass' => 'USPS_GROUND_ADVANTAGE'],
        purchaseContext: ['rateId' => 'RATE-76-CHARS', 'requestToken' => 'TOKEN'],
        expiresAt: now()->addMinutes(10),
        marketplace: 'ATVPDKIKX0DER',
    );
}

it('issues an offer with an opaque identifier bound to the package and source', function (): void {
    $package = Package::factory()->create();
    $source = createShopifyDataSource();

    $offer = app(OfferStore::class)->issue($package, amazonOfferDraft($source));

    expect($offer->public_id)->toHaveLength(26)
        ->and($offer->package_id)->toBe($package->id)
        ->and($offer->postage_data_source_id)->toBe($source->id)
        ->and($offer->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and($offer->purchase_context)->toBe(['rateId' => 'RATE-76-CHARS', 'requestToken' => 'TOKEN'])
        // Quote detail the adapter reads is stored beside the tokens, but is
        // not itself a secret: it is readable, where the context is not.
        ->and($offer->rate_metadata)->toBe(['mailClass' => 'USPS_GROUND_ADVANTAGE']);
});

it('stamps the environment at issue rather than reading it back later', function (): void {
    Setting::updateOrCreate(['key' => 'sandbox_mode'], ['value' => '1', 'type' => 'boolean', 'group' => 'system']);

    $offer = app(OfferStore::class)->issue(Package::factory()->create(), amazonOfferDraft());

    expect($offer->environment)->toBe(SourceEnvironment::Sandbox);

    // Flipping the shared toggle must not relabel an offer already issued: a
    // sandbox approval can never authorize production spending.
    Setting::updateOrCreate(['key' => 'sandbox_mode'], ['value' => '0', 'type' => 'boolean', 'group' => 'system']);
    app(SettingsService::class)->clearCache();

    expect($offer->fresh()->environment)->toBe(SourceEnvironment::Sandbox);
});

it('encrypts the purchase context at rest and hides it from array state', function (): void {
    $offer = app(OfferStore::class)->issue(Package::factory()->create(), amazonOfferDraft());

    $stored = DB::table('shipping_offers')->where('id', $offer->id)->value('purchase_context');

    expect($stored)->not->toContain('RATE-76-CHARS')
        ->and($offer->toArray())->not->toHaveKey('purchase_context')
        ->and(json_encode($offer))->not->toContain('RATE-76-CHARS');
});

it('redeems an offer once and refuses the second attempt', function (): void {
    $package = Package::factory()->create();
    $store = app(OfferStore::class);
    $offer = $store->issue($package, amazonOfferDraft());

    $first = $store->redeem($package, $offer->public_id);
    $second = $store->redeem($package, $offer->public_id);

    expect($first->wasRejected())->toBeFalse()
        ->and($first->offer->consumed_at)->not->toBeNull()
        ->and($second->wasRejected())->toBeTrue()
        ->and($second->rejection)->toBe(OfferRejection::AlreadyConsumed);
});

it('fails closed on an expired offer without consuming it', function (): void {
    $package = Package::factory()->create();
    $offer = ShippingOffer::factory()->expired()->for($package)->create();

    $redemption = app(OfferStore::class)->redeem($package, $offer->public_id);

    expect($redemption->wasRejected())->toBeTrue()
        ->and($redemption->rejection)->toBe(OfferRejection::Expired)
        ->and($redemption->message())->toContain('Get rates again')
        ->and($offer->fresh()->consumed_at)->toBeNull();
});

it('treats an offer with no published window as unexpired', function (): void {
    $package = Package::factory()->create();
    $offer = ShippingOffer::factory()->priceless()->for($package)->create();

    expect(app(OfferStore::class)->redeem($package, $offer->public_id)->wasRejected())->toBeFalse();
});

it('refuses an offer quoted for a different package', function (): void {
    $offer = ShippingOffer::factory()->create();
    $otherPackage = Package::factory()->create();

    $redemption = app(OfferStore::class)->redeem($otherPackage, $offer->public_id);

    expect($redemption->rejection)->toBe(OfferRejection::WrongPackage)
        ->and($offer->fresh()->consumed_at)->toBeNull();
});

it('refuses an identifier it never issued', function (): void {
    $redemption = app(OfferStore::class)->redeem(Package::factory()->create(), 'not-an-offer');

    expect($redemption->rejection)->toBe(OfferRejection::NotFound)
        ->and($redemption->offer)->toBeNull();
});

it('flags a spent offer with no confirmed purchase as needing recovery', function (): void {
    $package = Package::factory()->create();
    $offer = ShippingOffer::factory()->awaitingConfirmation()->for($package)->create();

    $redemption = app(OfferStore::class)->redeem($package, $offer->public_id);

    expect($redemption->requiresPurchaseRecovery())->toBeTrue()
        ->and(app(OfferStore::class)->awaitingPurchaseConfirmation($package))->toHaveCount(1);
});

it('does not flag recovery once the purchase is confirmed', function (): void {
    $package = Package::factory()->create();
    $offer = ShippingOffer::factory()->consumed()->for($package)->create();

    $redemption = app(OfferStore::class)->redeem($package, $offer->public_id);

    expect($redemption->rejection)->toBe(OfferRejection::AlreadyConsumed)
        ->and($redemption->requiresPurchaseRecovery())->toBeFalse()
        ->and(app(OfferStore::class)->awaitingPurchaseConfirmation($package))->toBeEmpty();
});

it('never overwrites a purchase reference the source already confirmed', function (): void {
    $offer = ShippingOffer::factory()->awaitingConfirmation()->create();
    $store = app(OfferStore::class);

    $store->recordPurchase($offer, 'AMZN-SHIPMENT-1');
    $store->recordPurchase($offer, '9400111899223197428490');

    expect($offer->fresh()->purchase_reference)->toBe('AMZN-SHIPMENT-1');
});

it('inspects an offer without spending it', function (): void {
    $package = Package::factory()->create();
    $store = app(OfferStore::class);
    $offer = $store->issue($package, amazonOfferDraft());

    $inspection = $store->inspect($package, $offer->public_id);

    // The whole point: a caller can find out an offer is good, run its own
    // validation, and still have the offer to spend afterwards.
    expect($inspection->wasRejected())->toBeFalse()
        ->and($offer->fresh()->consumed_at)->toBeNull()
        ->and($store->redeem($package, $offer->public_id)->wasRejected())->toBeFalse();
});

it('reports the same refusals when inspecting as when redeeming', function (OfferRejection $expected, callable $make): void {
    $package = Package::factory()->create();
    $offer = $make($package);

    expect(app(OfferStore::class)->inspect($package, $offer->public_id)->rejection)->toBe($expected)
        ->and($offer->fresh()->consumed_at?->toString())->toBe($offer->consumed_at?->toString());
})->with([
    'expired' => [OfferRejection::Expired, fn (Package $p) => ShippingOffer::factory()->expired()->for($p)->create()],
    'consumed' => [OfferRejection::AlreadyConsumed, fn (Package $p) => ShippingOffer::factory()->consumed()->for($p)->create()],
    'wrong package' => [OfferRejection::WrongPackage, fn (Package $p) => ShippingOffer::factory()->create()],
]);

it('settles an offer the source declined so nothing stays unresolved', function (): void {
    $package = Package::factory()->create();
    $offer = ShippingOffer::factory()->awaitingConfirmation()->for($package)->create();
    $store = app(OfferStore::class);

    $store->recordFailure($offer, 'The carrier rejected the shipment.');

    // The source answered, so nothing was bought and nothing needs recovering.
    expect($offer->fresh()->isAwaitingPurchaseConfirmation())->toBeFalse()
        ->and($offer->fresh()->isResolved())->toBeTrue()
        ->and($offer->fresh()->purchase_failure_reason)->toBe('The carrier rejected the shipment.')
        ->and($store->awaitingPurchaseConfirmation($package))->toBeEmpty();
});

it('never records a failure over a confirmed purchase', function (): void {
    $offer = ShippingOffer::factory()->consumed()->create();

    app(OfferStore::class)->recordFailure($offer, 'A late error we should not believe.');

    expect($offer->fresh()->purchase_failed_at)->toBeNull()
        ->and($offer->fresh()->purchase_reference)->not->toBeNull();
});

it('refuses an offer from the other environment, and does not consume it', function (): void {
    $package = Package::factory()->create();
    $store = app(OfferStore::class);
    $offer = $store->issue($package, amazonOfferDraft());

    Setting::updateOrCreate(['key' => 'sandbox_mode'], ['value' => '1', 'type' => 'boolean', 'group' => 'system']);
    app(SettingsService::class)->clearCache();

    // Sandbox and production service identifiers differ, and so do the hosts
    // that honour the tokens. An offer quoted in one world is a record in the
    // other, never authority.
    expect($store->inspect($package, $offer->public_id)->rejection)->toBe(OfferRejection::EnvironmentChanged)
        ->and($store->redeem($package, $offer->public_id)->rejection)->toBe(OfferRejection::EnvironmentChanged)
        ->and($offer->fresh()->consumed_at)->toBeNull();
});

it('reports an environment mismatch even when the offer has also expired', function (): void {
    $package = Package::factory()->create();
    $offer = ShippingOffer::factory()->expired()->for($package)->create([
        'environment' => SourceEnvironment::Sandbox,
    ]);

    // "The toggle moved" is the actionable half; a re-quote fixes both.
    expect(app(OfferStore::class)->inspect($package, $offer->public_id)->rejection)
        ->toBe(OfferRejection::EnvironmentChanged);
});

it('records the package as it stood and refuses the offer once it has moved', function (): void {
    $package = Package::factory()->create();
    $store = app(OfferStore::class);

    $offer = $store->issue($package, new OfferDraft(
        carrier: 'USPS',
        postageSource: PostageSource::CarrierAccount,
        serviceCode: 'PRIORITY_MAIL',
        serviceName: 'Priority Mail',
        price: 9.65,
        currency: 'USD',
        expiresAt: now()->endOfDay(),
        rateQuoteId: null,
        packageUpdatedAt: $package->updated_at,
        shipmentUpdatedAt: $package->shipment?->updated_at,
    ));

    expect($offer->package_updated_at->format('Y-m-d H:i:s'))->toBe($package->updated_at->format('Y-m-d H:i:s'))
        ->and($store->inspect($package, $offer->public_id)->wasRejected())->toBeFalse();

    // Timestamps carry whole seconds, so the edit has to land in a later one.
    $this->travel(1)->minutes();
    $package->update(['weight' => 3.0]);

    $inspection = $store->inspect($package, $offer->public_id);
    $claim = $store->redeem($package, $offer->public_id);

    // Refused, and left unconsumed: nothing was spent on it, and a re-quote
    // supersedes it rather than a recovery.
    expect($inspection->rejection)->toBe(OfferRejection::PackageChanged)
        ->and($inspection->requiresRequote())->toBeTrue()
        ->and($claim->rejection)->toBe(OfferRejection::PackageChanged)
        ->and($offer->fresh()->consumed_at)->toBeNull();
});

it('does not judge an offer that recorded no package version', function (): void {
    // Issued before the check existed, or hand-built: nothing to compare, so
    // nothing to refuse on.
    $package = Package::factory()->create();
    $store = app(OfferStore::class);
    $offer = $store->issue($package, amazonOfferDraft());

    $this->travel(1)->minutes();
    $package->update(['weight' => 3.0]);

    expect($offer->package_updated_at)->toBeNull()
        ->and($store->redeem($package, $offer->public_id)->wasRejected())->toBeFalse();
});
