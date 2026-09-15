<?php

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\VoidReason;
use App\Filament\Concerns\InteractsWithScoutSearch;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\User;
use Filament\Livewire\GlobalSearch;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

/**
 * A shipped package with its active label plus two voided ones, oldest first.
 *
 * The voided numbers share the prefix `94002`, which the active number does
 * not, so a search for it reaches the package only through voided labels.
 *
 * @return array{Package, PackageLabel, PackageLabel}
 */
function packageWithVoidedLabels(): array
{
    $package = Package::factory()->shipped()->create(['tracking_number' => '9400100000000000000001']);

    $first = PackageLabel::factory()->for($package)->create([
        'tracking_number' => '9400200000000000000002',
        'carrier' => 'USPS',
        'purchased_at' => now()->subDays(2),
        'voided_at' => now()->subDays(2)->addHour(),
        'void_reason' => VoidReason::Operator,
    ]);

    $second = PackageLabel::factory()->for($package)->create([
        'tracking_number' => '9400200000000000000003',
        'carrier' => 'FedEx',
        'purchased_at' => now()->subDay(),
        'voided_at' => now()->subDay()->addHour(),
        'void_reason' => VoidReason::VoidedUpstream,
    ]);

    return [$package, $first, $second];
}

// --- Labels on ViewPackage ---------------------------------------------------

it('lists every label on the package newest first with the active one distinguished', function (): void {
    [$package, $first, $second] = packageWithVoidedLabels();
    $active = $package->activeLabel;

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Labels',
            $active->tracking_number,
            $second->tracking_number,
            $first->tracking_number,
        ])
        // The active badge first, then a voided one per voided label.
        ->assertSeeInOrder(['Active', 'Voided', 'Voided'])
        ->assertSee($second->voidedBy->name)
        ->assertSee('Reported by postage source');
});

it('does not show the labels section for a package that never shipped', function (): void {
    $package = Package::factory()->create([
        'status' => PackageStatus::Unshipped,
        'tracking_number' => null,
    ]);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSuccessful()
        ->assertDontSee('Labels');
});

// --- Tracking lookup -------------------------------------------------------------

/**
 * Package ids the resource's global search returns, keyed by result title.
 *
 * @return array<string, int>
 */
function packageSearchTitles(string $search): array
{
    return PackageResource::getGlobalSearchResults($search)
        ->mapWithKeys(fn ($result): array => [
            (string) $result->title => (int) basename(parse_url($result->url, PHP_URL_PATH)),
        ])
        ->all();
}

it('finds a package by a voided label tracking number, titled as voided', function (): void {
    [$package, , $second] = packageWithVoidedLabels();

    $results = PackageResource::getGlobalSearchResults($second->tracking_number);

    expect($results)->toHaveCount(1)
        ->and((string) $results->first()->title)->toBe("Package #{$package->id} — label voided ".$second->voided_at->tz(Location::timezone())->format('M j, Y'))
        ->and($results->first()->url)->toBe(PackageResource::getUrl('view', ['record' => $package]))
        ->and($results->first()->details)->toMatchArray([
            'Voided label' => $second->tracking_number,
            'Carrier' => 'FedEx',
        ]);
});

it('would not find the voided label through the searchable-array constraints alone', function (): void {
    [, , $second] = packageWithVoidedLabels();

    // The concern the resource used before this: LIKE clauses from
    // Package::toSearchableArray() against the packages table only.
    $default = new class
    {
        use InteractsWithScoutSearch;

        public static function getModel(): string
        {
            return Package::class;
        }

        /** @return Builder<Package> */
        public static function query(string $search): Builder
        {
            $query = Package::query();
            self::applyGlobalSearchAttributeConstraints($query, $search);

            return $query;
        }
    };

    expect($default::query($second->tracking_number)->exists())->toBeFalse()
        ->and(PackageResource::getGlobalSearchResults($second->tracking_number))->toHaveCount(1);
});

it('keeps the active tracking number search unchanged', function (): void {
    [$package] = packageWithVoidedLabels();

    expect(packageSearchTitles($package->tracking_number))->toBe([
        $package->tracking_number => $package->id,
    ]);

    // Prefix rules as before: a leading fragment matches, an inner one does not.
    expect(packageSearchTitles(substr($package->tracking_number, 0, 12)))->toHaveKey($package->tracking_number)
        ->and(packageSearchTitles(substr($package->tracking_number, 4, 12)))->toBe([]);
});

it('lists a package once with the active title when its active number also appears on a voided label', function (): void {
    [$package] = packageWithVoidedLabels();

    // A tracking number reused across purchases: the earlier one was voided.
    PackageLabel::factory()->for($package)->create([
        'tracking_number' => $package->tracking_number,
        'purchased_at' => now()->subDays(3),
        'voided_at' => now()->subDays(3)->addHour(),
    ]);

    expect(packageSearchTitles($package->tracking_number))->toBe([
        $package->tracking_number => $package->id,
    ]);
});

it('titles from the newest voided label when several match and none is active', function (): void {
    [$package, , $second] = packageWithVoidedLabels();

    expect(packageSearchTitles('94002'))->toBe([
        "Package #{$package->id} — label voided ".$second->voided_at->tz(Location::timezone())->format('M j, Y') => $package->id,
    ]);
});

it('prefers the active title when a prefix matches the active and voided labels alike', function (): void {
    [$package] = packageWithVoidedLabels();

    expect(packageSearchTitles('9400'))->toBe([
        $package->tracking_number => $package->id,
    ]);
});

it('reaches the package through the global search component', function (): void {
    [$package, , $second] = packageWithVoidedLabels();

    Livewire::test(GlobalSearch::class)
        ->set('search', $second->tracking_number)
        ->assertSee("Package #{$package->id}")
        ->assertSee('label voided')
        ->assertSee(PackageResource::getUrl('view', ['record' => $package]));
});
