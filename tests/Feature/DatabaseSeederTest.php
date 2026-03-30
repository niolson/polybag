<?php

use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;

it('marks setup as complete when the database is seeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Setting::find('setup_complete')?->value)->toBeTrue()
        ->and(Setting::find('setup_wizard_step')?->value)->toBe(1);
});
