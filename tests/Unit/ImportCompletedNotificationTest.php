<?php

use App\Notifications\ImportCompleted;

it('includes the first error message in the database notification body', function (): void {
    $notification = new ImportCompleted(
        stats: ['shipments_created' => 2, 'shipments_updated' => 0],
        sourceName: 'Amazon',
        errors: ['Validation errors for shipment ORD-1: Missing city'],
    );

    $data = $notification->toDatabase(new stdClass);

    expect($data['body'])
        ->toContain('Missing city')
        ->and($data['title'])->toBe('Import completed with errors (Amazon)');
});

it('mentions how many additional errors were logged when there is more than one', function (): void {
    $notification = new ImportCompleted(
        stats: ['shipments_created' => 0, 'shipments_updated' => 0],
        sourceName: 'Amazon',
        errors: [
            'Validation errors for shipment ORD-1: Missing city',
            'Validation errors for shipment ORD-2: Missing address line 1',
        ],
    );

    $data = $notification->toDatabase(new stdClass);

    expect($data['body'])
        ->toContain('Missing city')
        ->toContain('+1 more; ask your administrator to check the import log');
});

it('omits error detail entirely when the import had none', function (): void {
    $notification = new ImportCompleted(
        stats: ['shipments_created' => 3, 'shipments_updated' => 1],
        sourceName: 'Amazon',
        errors: [],
    );

    $data = $notification->toDatabase(new stdClass);

    expect($data['body'])->toBe('3 created, 1 updated')
        ->and($data['title'])->toBe('Import completed (Amazon)');
});
