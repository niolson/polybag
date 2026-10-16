<?php

use App\Enums\PostageSetting;
use App\Enums\PostageSource;
use App\Enums\Role;
use App\Filament\Pages\EndOfDay;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    // Freeze time to a Wednesday at 10 AM to avoid USPS 8 PM cutoff and weekend issues
    $this->travelTo(now()->next('Wednesday')->setTime(10, 0));
    $this->actingAs(User::factory()->admin()->create());
});

it('shows the carriers with an account, with ship dates', function (): void {
    CarrierAccount::factory()->create(['carrier_id' => Carrier::factory()->create(['name' => 'USPS', 'active' => true])->id]);
    CarrierAccount::factory()->create(['carrier_id' => Carrier::factory()->create(['name' => 'FedEx', 'active' => true])->id]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value): bool {
            $byCarrier = collect($value)->keyBy('carrier');

            return $byCarrier->has('USPS')
                && $byCarrier->has('FedEx')
                && ! empty($byCarrier['USPS']['ship_date'])
                && ! empty($byCarrier['FedEx']['ship_date']);
        });
});

it('shows unmanifested count for USPS packages shipped today', function (): void {
    Carrier::factory()->create(['name' => 'USPS', 'active' => true]);

    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111']);
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400222']);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value): bool {
            $usps = collect($value)->firstWhere('carrier', 'USPS');

            return $usps['unmanifested_count'] === 2;
        });
});

it('counts a USPS package bought through Shopify, but not as one to manifest', function (): void {
    Carrier::factory()->create(['name' => 'USPS', 'active' => true]);

    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => null,
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory(),
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value): bool {
            $usps = collect($value)->firstWhere('carrier', 'USPS');

            return $usps['package_count'] === 1
                && $usps['unmanifested_count'] === 0;
        });
});

it('excludes already-manifested USPS packages from count', function (): void {
    Carrier::factory()->create(['name' => 'USPS', 'active' => true]);

    $manifest = Manifest::factory()->create();
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'manifest_id' => $manifest->id,
    ]);
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400222']);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value): bool {
            $usps = collect($value)->firstWhere('carrier', 'USPS');

            return $usps['unmanifested_count'] === 1;
        });
});

it('shows zero unmanifested count for non-USPS carriers', function (): void {
    Carrier::factory()->create(['name' => 'FedEx', 'active' => true]);
    Package::factory()->shipped()->create(['carrier' => 'FedEx', 'tracking_number' => '7890001']);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value): bool {
            $fedex = collect($value)->firstWhere('carrier', 'FedEx');

            return $fedex['unmanifested_count'] === 0;
        });
});

it('does not show inactive carriers', function (): void {
    CarrierAccount::factory()->create(['carrier_id' => Carrier::factory()->create(['name' => 'USPS', 'active' => true])->id]);
    $dhl = Carrier::factory()->create(['name' => 'DHL', 'active' => false]);
    Package::factory()->shipped()->create(['carrier' => 'DHL', 'normalized_carrier_id' => $dhl->id]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value): bool {
            $carriers = collect($value)->pluck('carrier');

            return $carriers->contains('USPS') && ! $carriers->contains('DHL');
        });
});

it('displays manifests', function (): void {
    Manifest::factory()->create([
        'carrier' => 'USPS',
        'manifest_number' => 'MN999',
        'package_count' => 5,
        'manifest_date' => today(),
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSee('MN999');
});

it('shows manifests from all dates sorted newest first', function (): void {
    Manifest::factory()->create([
        'manifest_number' => 'MN001',
        'manifest_date' => today()->subDay(),
        'created_at' => now()->subDay(),
    ]);
    Manifest::factory()->create([
        'manifest_number' => 'MN002',
        'manifest_date' => today(),
        'created_at' => now(),
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSeeInOrder(['MN002', 'MN001']);
});

it('dispatches print-report event when reprinting manifest with image', function (): void {
    $manifest = Manifest::factory()->withImage()->create([
        'manifest_date' => today(),
    ]);

    Livewire::test(EndOfDay::class)
        ->call('reprintManifest', $manifest->id)
        ->assertDispatched('print-report');
});

it('shows error when reprinting manifest without image', function (): void {
    $manifest = Manifest::factory()->create([
        'image' => null,
        'manifest_date' => today(),
    ]);

    Livewire::test(EndOfDay::class)
        ->call('reprintManifest', $manifest->id)
        ->assertNotified();
});

it('hides a carrier with no account and no label today', function (): void {
    CarrierAccount::factory()->create(['carrier_id' => Carrier::factory()->create(['name' => 'USPS', 'active' => true])->id]);
    Carrier::factory()->create(['name' => 'DHL Express', 'active' => true]);
    Carrier::factory()->create(['name' => 'OnTrac', 'active' => true]);
    CarrierAccount::factory()->inactive()->create(['carrier_id' => Carrier::factory()->create(['name' => 'FedEx', 'active' => true])->id]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', fn (array $value): bool => collect($value)->pluck('carrier')->all() === ['USPS']);
});

it('shows a carrier with no account once it has a label today, and a zero count as 0', function (): void {
    CarrierAccount::factory()->create(['carrier_id' => Carrier::factory()->create(['name' => 'USPS', 'active' => true])->id]);
    $ontrac = Carrier::factory()->create(['name' => 'OnTrac', 'active' => true]);
    Package::factory()->shipped()->create(['carrier' => 'OnTrac', 'normalized_carrier_id' => $ontrac->id]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', fn (array $value): bool => collect($value)->pluck('package_count', 'carrier')->all() === ['OnTrac' => 1, 'USPS' => 0])
        ->assertDontSeeHtml('&mdash;')
        ->assertSee('Generate a manifest for today');
});

it('shows the carrier a Shopify connection that sells postage dates its labels by', function (): void {
    $usps = Carrier::factory()->create(['name' => 'USPS', 'active' => true]);
    $listed = fn (): array => collect(Livewire::test(EndOfDay::class)->get('carrierSummary'))->pluck('carrier')->all();
    $shopify = createShopifyDataSource();
    $shopify->update(['postage_setting' => PostageSetting::DoesNotSell]);

    expect($listed())->toBe([]);

    $shopify->update(['postage_setting' => PostageSetting::PackerOnly]);

    expect($listed())->toBe(['USPS'])
        ->and($shopify->shipDateCarrier()->is($usps))->toBeTrue();
});

it('ends a shipping day through a confirmation modal', function (): void {
    $fedex = Carrier::factory()->create(['name' => 'FedEx', 'active' => true]);
    CarrierAccount::factory()->create(['carrier_id' => $fedex->id]);

    $component = Livewire::test(EndOfDay::class);
    $summary = collect($component->get('carrierSummary'))->firstWhere('carrier', 'FedEx');

    $component->mountAction('endShippingDay', ['carrier' => $fedex->id])
        ->assertActionMounted('endShippingDay')
        ->assertMountedActionModalSee("New FedEx labels will be dated {$summary['next_ship_date']} instead of {$summary['ship_date']}.")
        ->callMountedAction()
        ->assertNotified();

    expect(collect($component->get('carrierSummary'))->firstWhere('carrier', 'FedEx')['ship_date'])->toBe($summary['next_ship_date']);
});

it('endShippingDay advances the ship date', function (): void {
    CarrierAccount::factory()->create(['carrier_id' => Carrier::factory()->create(['name' => 'FedEx', 'active' => true])->id]);

    $component = Livewire::test(EndOfDay::class);

    $initialDate = collect($component->get('carrierSummary'))->firstWhere('carrier', 'FedEx')['ship_date'];

    $component->call('endShippingDay', Carrier::where('name', 'FedEx')->value('id'))
        ->assertNotified();

    $newDate = collect($component->get('carrierSummary'))->firstWhere('carrier', 'FedEx')['ship_date'];

    expect($newDate)->not->toBe($initialDate);
});

it('denies access to users with User role', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    Livewire::test(EndOfDay::class)->assertForbidden();
});

it('allows access to managers', function (): void {
    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(EndOfDay::class)->assertSuccessful();
});

it('allows access to admins', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EndOfDay::class)->assertSuccessful();
});

it('still offers a SCAN form for labels dated today once the USPS cutoff has passed', function (): void {
    Carrier::factory()->usps()->create(['active' => true, 'pickup_cutoff_hour' => 20]);
    $today = now('America/New_York')->toDateString();
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111', 'ship_date' => $today]);

    // 8:30 PM at the default timezone: the ship date has moved to the next pickup.
    $this->travelTo(now('America/New_York')->setTime(20, 30));

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary.0.unmanifested_count', 1);
});

it('still offers a SCAN form for the day just ended, and warns that it is missing', function (): void {
    $usps = Carrier::factory()->usps()->create(['active' => true]);
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111', 'ship_date' => now('America/New_York')->toDateString()]);

    Livewire::test(EndOfDay::class)
        ->call('endShippingDay', $usps->id)
        ->assertNotified('Shipping Day Ended')
        ->assertSet('carrierSummary.0.unmanifested_count', 1);
});

it('leaves labels from an earlier day off the manifest', function (): void {
    Carrier::factory()->usps()->create(['active' => true]);
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111', 'ship_date' => now('America/New_York')->subDays(2)->toDateString()]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary.0.unmanifested_count', 0);
});
