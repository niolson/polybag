<?php

use App\DataTransferObjects\Shipping\ManifestResponse;

it('creates a success response', function (): void {
    $response = ManifestResponse::success(
        manifestNumber: 'MN123456',
        carrier: 'USPS',
        image: 'base64data',
    );

    expect($response->success)->toBeTrue()
        ->and($response->manifestNumber)->toBe('MN123456')
        ->and($response->carrier)->toBe('USPS')
        ->and($response->image)->toBe('base64data')
        ->and($response->errorMessage)->toBeNull();
});

it('creates a success response without image', function (): void {
    $response = ManifestResponse::success(
        manifestNumber: 'MN123456',
        carrier: 'USPS',
    );

    expect($response->success)->toBeTrue()
        ->and($response->image)->toBeNull();
});

it('creates a failure response', function (): void {
    $response = ManifestResponse::failure('Something went wrong');

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe('Something went wrong')
        ->and($response->manifestNumber)->toBeNull()
        ->and($response->carrier)->toBeNull()
        ->and($response->image)->toBeNull();
});
