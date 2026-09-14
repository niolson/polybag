<?php

use App\Enums\PackageStatus;
use App\Models\Package;
use App\Models\PackageLabel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

it('passes when every package agrees with its label record', function (): void {
    Package::factory()->shipped()->create();
    Package::factory()->create();

    $this->artisan('app:verify-label-integrity')
        ->expectsOutputToContain('Every package agrees with its label record.')
        ->assertSuccessful();
});

it('reports a shipped package with no label, an unshipped package with an active label, and a drifted cost', function (): void {
    $missing = Package::factory()->shipped()->create();
    PackageLabel::query()->where('package_id', $missing->id)->delete();

    $orphaned = Package::factory()->shipped()->create();
    DB::table('packages')->where('id', $orphaned->id)->update(['status' => PackageStatus::Unshipped->value]);

    $drifted = Package::factory()->shipped()->create(['cost' => 10.00]);
    DB::table('package_labels')->where('package_id', $drifted->id)->update(['cost' => 12.00]);

    $this->artisan('app:verify-label-integrity')
        ->expectsOutputToContain("package {$missing->id} — tracking {$missing->tracking_number}")
        ->expectsOutputToContain("package {$orphaned->id} — status unshipped")
        ->expectsOutputToContain("package {$drifted->id} — differs on cost")
        ->expectsOutputToContain('3 package(s) disagree with their label record.')
        ->assertFailed();
});

it('names the renamed pair when the print timestamp drifts', function (): void {
    $package = Package::factory()->shipped()->create(['label_printed_at' => now()]);
    DB::table('package_labels')->where('package_id', $package->id)->update(['last_printed_at' => null]);

    $this->artisan('app:verify-label-integrity')
        ->expectsOutputToContain("package {$package->id} — differs on label_printed_at/last_printed_at")
        ->assertFailed();
});

it('repairs only the shipped package with no label, and leaves the rest reported', function (): void {
    $missing = Package::factory()->shipped()->create();
    PackageLabel::query()->where('package_id', $missing->id)->delete();

    $orphaned = Package::factory()->shipped()->create();
    DB::table('packages')->where('id', $orphaned->id)->update(['status' => PackageStatus::Unshipped->value]);

    $drifted = Package::factory()->shipped()->create(['cost' => 10.00]);
    DB::table('package_labels')->where('package_id', $drifted->id)->update(['cost' => 12.00]);

    $this->artisan('app:verify-label-integrity', ['--repair' => true])
        ->expectsOutputToContain('Inserted a label row for 1 shipped package(s) from their own columns.')
        ->expectsOutputToContain('2 package(s) disagree with their label record.')
        ->assertFailed();

    expect($missing->activeLabel)->not->toBeNull()
        ->and($missing->activeLabel->tracking_number)->toBe($missing->tracking_number)
        ->and($orphaned->labels()->active()->count())->toBe(1)
        ->and((float) $drifted->activeLabel->cost)->toBe(12.00);

    // The repaired package now agrees.
    $this->artisan('app:verify-label-integrity')
        ->expectsOutputToContain('2 package(s) disagree with their label record.');
});

it('is scheduled to run nightly', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'app:verify-label-integrity'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
