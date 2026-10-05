<?php

use App\Models\Product;

it('creates a product the Amazon catalog has never been asked about', function (): void {
    $product = Product::factory()->create();

    expect($product->identifiers_checked_at)->toBeNull();
});
