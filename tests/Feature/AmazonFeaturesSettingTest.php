<?php

use App\Enums\Role;
use App\Filament\Pages\Settings;
use App\Filament\Pages\UnmappedObservedServices;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\ShippingRulesRelationManager;
use App\Models\DataSource;
use App\Models\ObservedService;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

function setAmazonFeatures(bool $enabled): void
{
    app(SettingsService::class)->set('amazon_features_enabled', $enabled, 'boolean');
}

it('is off by default', function (): void {
    expect(app(SettingsService::class)->amazonFeaturesEnabled())->toBeFalse();
});

it('is on when the setting is on', function (): void {
    setAmazonFeatures(true);

    expect(app(SettingsService::class)->amazonFeaturesEnabled())->toBeTrue();
});

it('is on while an Amazon connection is active, without writing the setting', function (): void {
    $connection = DataSource::factory()->amazon()->create(['active' => true]);

    expect(app(SettingsService::class)->amazonFeaturesEnabled())->toBeTrue()
        ->and(app(SettingsService::class)->get('amazon_features_enabled'))->toBeNull();

    $connection->update(['active' => false]);

    expect(app(SettingsService::class)->amazonFeaturesEnabled())->toBeFalse();
});

it('ignores an inactive Amazon connection and a non-Amazon one', function (): void {
    DataSource::factory()->amazon()->create(['active' => false]);
    DataSource::factory()->shopify()->create(['active' => true]);

    expect(app(SettingsService::class)->amazonFeaturesEnabled())->toBeFalse();
});

describe('Map Amazon Services', function (): void {
    it('is hidden with the feature off and no observations', function (): void {
        expect(UnmappedObservedServices::canAccess())->toBeFalse();
    });

    it('is shown with the setting on', function (): void {
        setAmazonFeatures(true);

        expect(UnmappedObservedServices::canAccess())->toBeTrue();
    });

    it('is shown with an active Amazon connection', function (): void {
        DataSource::factory()->amazon()->create(['active' => true]);

        expect(UnmappedObservedServices::canAccess())->toBeTrue();
    });

    it('stays reachable when observations already exist', function (): void {
        ObservedService::factory()->create();

        expect(UnmappedObservedServices::canAccess())->toBeTrue();
    });

    it('stays Admin only', function (): void {
        setAmazonFeatures(true);
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));

        expect(UnmappedObservedServices::canAccess())->toBeFalse();
    });
});

describe('Shipments', function (): void {
    it('hides the Amazon column with the feature off', function (): void {
        Livewire::test(ListShipments::class)->assertTableColumnHidden('amazon');
    });

    it('shows the Amazon column with the feature on', function (): void {
        setAmazonFeatures(true);

        Livewire::test(ListShipments::class)->assertTableColumnVisible('amazon');
    });

    it('hides the Amazon program on a Shipment with the feature off', function (): void {
        $shipment = Shipment::factory()->create(['metadata' => ['amazon_programs' => ['PRIME']]]);

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertSuccessful()
            ->assertDontSee('Amazon Program');
    });

    it('shows the Amazon program on a Shipment with the feature on', function (): void {
        setAmazonFeatures(true);
        $shipment = Shipment::factory()->create(['metadata' => ['amazon_programs' => ['PRIME']]]);

        Livewire::test(ViewShipment::class, ['record' => $shipment->id])
            ->assertSee('Amazon Program');
    });
});

describe('Shipping rules', function (): void {
    /**
     * Whether the block can still be added: only `maxItems(0)` keeps it out of
     * the picker while a rule that already holds it keeps rendering and saving.
     */
    function amazonProgramPickerOffered(): bool
    {
        $block = (new ReflectionMethod(ShippingRulesRelationManager::class, 'amazonProgramBlock'))->invoke(null);

        return $block->getMaxItems() !== 0;
    }

    it('keeps the Amazon program condition out of the picker with the feature off', function (): void {
        expect(amazonProgramPickerOffered())->toBeFalse();
    });

    it('offers the Amazon program condition with the feature on', function (): void {
        setAmazonFeatures(true);

        expect(amazonProgramPickerOffered())->toBeTrue();
    });
});

describe('Settings page', function (): void {
    it('shows the toggle off and hides the Transparency toggle by default', function (): void {
        Livewire::test(Settings::class)
            ->assertSet('data.amazon_features_enabled', false)
            ->assertFormFieldIsHidden('transparency_enabled');
    });

    it('saves the toggle and then offers the Transparency toggle', function (): void {
        Livewire::test(Settings::class)
            ->fillForm(['amazon_features_enabled' => true])
            ->assertFormFieldIsVisible('transparency_enabled')
            ->call('save')
            ->assertNotified();

        expect(app(SettingsService::class)->get('amazon_features_enabled'))->toBeTrue();
    });

    it('does not lose the Transparency setting while it is hidden', function (): void {
        app(SettingsService::class)->set('transparency_enabled', false, 'boolean');

        Livewire::test(Settings::class)->call('save')->assertNotified();

        expect(app(SettingsService::class)->get('transparency_enabled'))->toBeFalse();
    });

    it('shows the toggle on and locked while a connection is active, and does not latch it', function (): void {
        DataSource::factory()->amazon()->create(['active' => true]);

        Livewire::test(Settings::class)
            ->assertSet('data.amazon_features_enabled', true)
            ->assertFormFieldIsDisabled('amazon_features_enabled')
            ->call('save')
            ->assertNotified();

        expect(app(SettingsService::class)->get('amazon_features_enabled'))->toBeFalsy();
    });
});
