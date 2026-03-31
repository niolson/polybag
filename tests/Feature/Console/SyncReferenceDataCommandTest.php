<?php

use App\Models\Carrier;

it('syncs carrier reference data', function (): void {
    Carrier::query()->delete();

    $this->artisan('app:sync-reference-data')
        ->expectsOutput('Syncing reference data...')
        ->expectsOutput('Reference data synced.')
        ->assertSuccessful();

    expect(Carrier::where('name', 'USPS')->exists())->toBeTrue()
        ->and(Carrier::where('name', 'FedEx')->exists())->toBeTrue()
        ->and(Carrier::where('name', 'UPS')->exists())->toBeTrue();
});
