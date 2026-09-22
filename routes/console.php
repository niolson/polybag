<?php

use App\Models\DataSource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

try {
    DataSource::importing()
        ->whereNotNull('schedule_interval')
        ->get()
        ->each(function (DataSource $source): void {
            Schedule::command('shipments:import', ['--source-id' => $source->id])
                ->cron($source->schedule_interval->toCron())
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/import-'.now()->format('Y-m-d').'.log'));
        });
} catch (Throwable) {
    // DB may not be available during bootstrapping (e.g. first deploy)
}

Schedule::command('shipments:validate')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/validate-'.now()->format('Y-m-d').'.log'));

Schedule::command('packages:export --scheduled')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/export-'.now()->format('Y-m-d').'.log'));

// A Shopify Shipping label can only be voided in the Shopify admin, and a
// Shopify-bought parcel can only be tracked through Shopify, so the only way
// PolyBag learns either is by asking. One poll answers both. Fifteen minutes
// keeps a dead label from sitting in the shipped queue for long without polling
// Shopify hard enough to matter.
Schedule::command('packages:sync-shopify-fulfillments')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Detection, not prevention: the writers keep each package and its label
// record consistent by construction, and this names anything that slipped
// past them. Report only — the repair is a person's decision (ADR-0004).
Schedule::command('app:verify-label-integrity')
    ->daily()
    ->withoutOverlapping();

// appendOutputTo has no built-in rotation, so prune dated import-*.log /
// validate-*.log files after 14 days, matching the other log channels.
Schedule::call(function (): void {
    collect(File::glob(storage_path('logs/{import,validate,export}-*.log'), GLOB_BRACE))
        ->filter(fn (string $path): bool => File::lastModified($path) < now()->subDays(14)->timestamp)
        ->each(fn (string $path) => File::delete($path));
})->daily();
