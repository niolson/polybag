<?php

use App\Contracts\CarrierPolicy;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PostageSourceOperations;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\PostageSources\PostageSourceDispatcher;
use App\Services\PostageSources\ShopifyPostageSource;

/**
 * ADR-0002 decision 7 split `CarrierAdapterInterface` in two. These are the
 * assertions about the split itself: who implements which half, and that the
 * two manifest questions it separated no longer share an answer.
 */
it('lets a resale channel implement the offer seam without pretending to be a carrier', function (): void {
    $shopify = app(CarrierRegistry::class)->get(ShopifyAdapter::CARRIER_NAME);
    $usps = app(CarrierRegistry::class)->get('USPS');

    // A direct carrier is all three roles at once; Shopify is only the first.
    expect($usps)->toBeInstanceOf(DirectCarrierAdapter::class)
        ->and($usps)->toBeInstanceOf(CarrierPolicy::class)
        ->and($shopify)->not->toBeInstanceOf(CarrierPolicy::class)
        ->and(app(ShopifyPostageSource::class))->toBeInstanceOf(PostageSourceOperations::class);
});

it('gives the registry nothing to say about a carrier that is not one', function (): void {
    $registry = app(CarrierRegistry::class);

    // Registered for the offers it sells, and asked carrier questions by
    // EndOfDay — which must get null rather than a no-op answer standing in for
    // a carrier's policy.
    expect($registry->has(ShopifyAdapter::CARRIER_NAME))->toBeTrue()
        ->and($registry->policyFor(ShopifyAdapter::CARRIER_NAME))->toBeNull()
        ->and($registry->directAdapterFor(ShopifyAdapter::CARRIER_NAME))->toBeNull()
        ->and($registry->policyFor('USPS'))->not->toBeNull()
        ->and($registry->policyFor(null))->toBeNull()
        ->and($registry->policyFor('Nonexistent'))->toBeNull();
});

it('separates whether a carrier manifests at all from whether this package may go on ours', function (): void {
    $registry = app(CarrierRegistry::class);
    $dispatcher = app(PostageSourceDispatcher::class);

    $direct = Package::factory()->usps()->create();
    $shopifyBought = shippedShopifyPackage();

    // Both parcels are carried by USPS, which runs a SCAN form program. Only
    // one of them was tendered on an account of ours, and a SCAN form is a claim
    // that it was.
    expect($registry->policyFor('USPS')?->supportsCarrierManifest())->toBeTrue()
        ->and($shopifyBought->carrier)->toBe('USPS')
        ->and($dispatcher->supportsPackageManifest($direct))->toBeTrue()
        ->and($dispatcher->supportsPackageManifest($shopifyBought))->toBeFalse();
});

it('reports a carrier with no manifest program as ineligible even when we bought the label', function (): void {
    $package = Package::factory()->fedex()->create();

    expect(app(CarrierRegistry::class)->policyFor('FedEx')?->supportsCarrierManifest())->toBeFalse()
        ->and(app(PostageSourceDispatcher::class)->supportsPackageManifest($package))->toBeFalse();
});
