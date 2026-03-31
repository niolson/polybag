<?php

use App\Models\Setting;
use App\Models\Carrier;
use Database\Seeders\DatabaseSeeder;

it('marks setup as complete when the database is seeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Setting::find('setup_complete')?->value)->toBeTrue()
        ->and(Setting::find('setup_wizard_step')?->value)->toBe(1);
});

it('includes tenant reference data when the database is seeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Carrier::where('name', 'USPS')->exists())->toBeTrue()
        ->and(Carrier::where('name', 'FedEx')->exists())->toBeTrue()
        ->and(Carrier::where('name', 'UPS')->exists())->toBeTrue();
});
