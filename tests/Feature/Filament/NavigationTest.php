<?php

use App\Filament\Pages\DeviceSettings;
use App\Filament\Pages\GeneratePickBatch;
use App\Filament\Pages\UnmappedChannelReferences;
use App\Filament\Pages\UnmappedShippingReferences;
use App\Filament\Resources\PickBatches\Pages\ListPickBatches;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SettingsService;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    app(SettingsService::class)->set('picking_enabled', true, 'boolean');
    config()->set('features.multi_client_enabled', true);
    config()->set('features.multi_location_enabled', true);

    Filament::setCurrentPanel('app');
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

/**
 * @return array<string, list<string>>
 */
function sidebar(): array
{
    return collect(Filament::getNavigation())
        ->mapWithKeys(fn (NavigationGroup $group): array => [
            (string) $group->getLabel() => collect($group->getItems())
                ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
                ->values()
                ->all(),
        ])
        ->all();
}

it('groups the sidebar by task, daily work first', function (): void {
    expect(sidebar())->toBe([
        '' => ['Dashboard'],
        'Ship' => ['Scan & Pack', 'Manual Ship', 'End of Day'],
        'Fulfillment' => ['Shipments', 'Packages', 'Label Batches'],
        'Picking' => ['Pick Batches', 'Print Pack Slips'],
        'Reports' => ['Rate Comparison', 'Volume', 'Packages by User', 'Client Billing', 'Prepaid Duties', 'Packing Validation'],
        'Shipping Setup' => ['Shipping Methods', 'Box Sizes', 'Products'],
        'Carriers' => ['Carrier Accounts', 'Carriers', 'Carrier Services', 'Special Services'],
        'Integrations' => ['Connections', 'Channels', 'Map Shipping Refs', 'Map Channel Refs'],
        'Admin' => ['Users', 'Locations', 'Clients', 'App Settings', 'Audit Log', 'Dev Settings'],
    ]);
});

it('gives every sidebar item its own icon', function (): void {
    $icons = collect(Filament::getNavigation())
        ->flatMap(fn (NavigationGroup $group): iterable => $group->getItems())
        // Some resources name the icon by Heroicon enum, others by string.
        ->map(fn (NavigationItem $item): string => ($icon = $item->getIcon()) instanceof Heroicon
            ? 'heroicon-'.$icon->value
            : (string) $icon);

    expect($icons->duplicates()->all())->toBe([]);
});

it('reaches Generate Pick Batch from the Pick Batches list, which stays lit', function (): void {
    Livewire::test(ListPickBatches::class)
        ->assertActionExists('generate')
        ->assertActionHasUrl('generate', GeneratePickBatch::getUrl());

    expect(PickBatchResource::getNavigationItemActiveRoutePattern())
        ->toContain(GeneratePickBatch::getRouteName());
});

it('offers Workstation Settings in the user menu', function (): void {
    $item = collect(Filament::getCurrentOrDefaultPanel()->getUserMenuItems())
        ->first(fn ($action): bool => $action->getName() === 'workstationSettings');

    expect($item)->not->toBeNull()
        ->and($item->getLabel())->toBe('Workstation Settings')
        ->and($item->getUrl())->toBe(DeviceSettings::getUrl());

    $this->get(DeviceSettings::getUrl())->assertOk()->assertSee('Workstation Settings');
});

it('badges the reference mapping pages with how many references need mapping', function (): void {
    Cache::flush();

    expect(UnmappedShippingReferences::getNavigationBadge())->toBeNull()
        ->and(UnmappedChannelReferences::getNavigationBadge())->toBeNull();

    Cache::flush();
    Shipment::factory()->count(2)->create([
        'shipping_method_reference' => 'Two Day Rush',
        'shipping_method_id' => null,
        'channel_reference' => 'Etsy',
        'channel_id' => null,
    ]);
    Shipment::factory()->create([
        'shipping_method_reference' => 'Overnight-ish',
        'shipping_method_id' => null,
    ]);

    expect(UnmappedShippingReferences::getNavigationBadge())->toBe('2')
        ->and(UnmappedChannelReferences::getNavigationBadge())->toBe('1');
});
