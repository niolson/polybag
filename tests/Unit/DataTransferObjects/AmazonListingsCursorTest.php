<?php

use App\DataTransferObjects\ShipmentImport\AmazonListingsCursor;
use App\Exceptions\CatalogReadMustStartOver;

it('round-trips through its string', function (): void {
    $cursor = new AmazonListingsCursor([100, 150, 200], 'synthetic-token', 40, ['SYN-A'], 149, ['SYN-B', 'SYN-C']);

    expect(AmazonListingsCursor::fromString($cursor->toString()))->toEqual($cursor);
});

it('halves the window in hand, sharing its middle second, and keeps what it skips', function (): void {
    $split = (new AmazonListingsCursor([100, 200, 300], skip: ['SYN-A']))->split();

    expect($split->bounds)->toBe([100, 150, 200, 300])
        ->and($split->skip)->toBe(['SYN-A'])
        ->and($split->pageToken)->toBeNull();
});

it('is splittable only when the window spans more than a second', function (array $bounds, bool $splittable): void {
    expect((new AmazonListingsCursor($bounds))->isSplittable())->toBe($splittable);
})->with([
    'two seconds' => [[100, 102], true],
    'one second' => [[100, 101], false],
]);

it('moves to the next window, skipping only SKUs created on the shared second', function (): void {
    $cursor = new AmazonListingsCursor([100, 150, 200], 'synthetic-token', 40);

    expect($cursor->nextWindow(150, ['SYN-EDGE'])->bounds)->toBe([150, 200])
        ->and($cursor->nextWindow(150, ['SYN-EDGE'])->skip)->toBe(['SYN-EDGE'])
        ->and($cursor->nextWindow(149, ['SYN-EARLIER'])->skip)->toBe([])
        ->and($cursor->nextWindow(150, [])->pageToken)->toBeNull()
        ->and((new AmazonListingsCursor([150, 200]))->nextWindow(200, ['SYN-LAST']))->toBeNull();
});

it('refuses a string it did not write, so the next sync starts over', function (string $cursor): void {
    expect(fn (): AmazonListingsCursor => AmazonListingsCursor::fromString($cursor))->toThrow(CatalogReadMustStartOver::class);
})->with([
    'not JSON' => ['cursor-page-2'],
    'one bound' => ['{"b":[100]}'],
    'not seconds' => ['{"b":["a","b"]}'],
]);
