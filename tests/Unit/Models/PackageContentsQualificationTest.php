<?php

use App\DataTransferObjects\Shipping\PackageData;
use App\Enums\ContentClass;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Collection;

/**
 * ADR-0006 decision 11: a Package qualifies for a content class only when it
 * has at least one item and every item is a product the seller declared as it.
 */
function packageHolding(Product ...$products): Package
{
    $package = Package::factory()->for(Shipment::factory())->create();

    foreach ($products as $product) {
        $package->packageItems()->create(['product_id' => $product->id, 'quantity' => 1]);
    }

    return $package;
}

it('qualifies a package whose every item is a product marked as media', function (): void {
    $package = packageHolding(Product::factory()->media()->create(), Product::factory()->media()->create());

    expect($package->qualifiesFor(ContentClass::Media))->toBeTrue()
        ->and($package->qualifyingContents())->toBe([ContentClass::Media]);
});

it('does not qualify a package with one item that is not media', function (): void {
    $package = packageHolding(Product::factory()->media()->create(), Product::factory()->create());

    expect($package->qualifiesFor(ContentClass::Media))->toBeFalse()
        ->and($package->qualifyingContents())->toBe([]);
});

it('does not qualify a package holding an item with no product', function (): void {
    // `package_items.product_id` is required today, so this is built in memory:
    // the rule must not depend on the schema to refuse what nobody vouched for.
    $media = new PackageItem(['quantity' => 1]);
    $media->setRelation('product', Product::factory()->media()->make());
    $unknown = new PackageItem(['quantity' => 1]);
    $unknown->setRelation('product', null);

    $package = Package::factory()->for(Shipment::factory())->create();
    $package->setRelation('packageItems', new Collection([$media, $unknown]));

    expect($package->qualifiesFor(ContentClass::Media))->toBeFalse();
});

it('does not qualify a package with no items, such as one from Manual Ship', function (): void {
    expect(packageHolding()->qualifiesFor(ContentClass::Media))->toBeFalse();
});

it('treats a product as not media until the seller says it is', function (): void {
    expect(Product::factory()->create()->fresh()->is_media)->toBeFalse();
});

it('carries what the package qualifies for into the rate request, and so into its fingerprint', function (): void {
    $product = Product::factory()->media()->create();
    $package = packageHolding($product);

    $before = PackageData::fromPackage($package);
    $product->update(['is_media' => false]);
    $after = PackageData::fromPackage($package->fresh());

    expect($before->qualifyingContents)->toBe([ContentClass::Media])
        ->and($after->qualifyingContents)->toBe([])
        ->and(json_encode($before))->not->toBe(json_encode($after));
});
