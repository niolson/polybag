<?php

use App\Enums\Role;
use App\Filament\Pages\UnmappedChannelReferences;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('renders the page for managers', function (): void {
    Livewire::test(UnmappedChannelReferences::class)
        ->assertSuccessful();
});

it('denies access to regular users', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    Livewire::test(UnmappedChannelReferences::class)->assertForbidden();
});

it('shows unmapped channel references with counts', function (): void {
    Shipment::factory()->count(3)->create([
        'channel_reference' => 'Amazon',
        'channel_id' => null,
    ]);

    Shipment::factory()->count(2)->create([
        'channel_reference' => 'eBay',
        'channel_id' => null,
    ]);

    Livewire::test(UnmappedChannelReferences::class)
        ->assertCanSeeTableRecords(
            Shipment::query()
                ->selectRaw('MIN(id) as id, channel_reference')
                ->whereIn('channel_reference', ['Amazon', 'eBay'])
                ->whereNull('channel_id')
                ->groupBy('channel_reference')
                ->get()
        )
        ->assertCountTableRecords(2);
});

it('excludes references that already have ChannelAlias entries', function (): void {
    $channel = Channel::factory()->create();

    Shipment::factory()->create([
        'channel_reference' => 'Unmapped',
        'channel_id' => null,
    ]);

    ChannelAlias::factory()->create([
        'reference' => 'Already Mapped',
        'channel_id' => $channel->id,
    ]);
    Shipment::factory()->create([
        'channel_reference' => 'Already Mapped',
        'channel_id' => null,
    ]);

    Livewire::test(UnmappedChannelReferences::class)
        ->assertCountTableRecords(1);
});

it('excludes references where channel_id is already set', function (): void {
    $channel = Channel::factory()->create();

    Shipment::factory()->create([
        'channel_reference' => 'Resolved',
        'channel_id' => $channel->id,
    ]);

    Shipment::factory()->create([
        'channel_reference' => 'Unresolved',
        'channel_id' => null,
    ]);

    Livewire::test(UnmappedChannelReferences::class)
        ->assertCountTableRecords(1);
});

it('assign action creates ChannelAlias and backfills shipments', function (): void {
    $channel = Channel::factory()->create();

    $shipments = Shipment::factory()->count(3)->create([
        'channel_reference' => 'Bulk Channel',
        'channel_id' => null,
    ]);

    $record = Shipment::query()
        ->selectRaw('MIN(id) as id, channel_reference')
        ->where('channel_reference', 'Bulk Channel')
        ->whereNull('channel_id')
        ->groupBy('channel_reference')
        ->first();

    Livewire::test(UnmappedChannelReferences::class)
        ->callTableAction('assign', $record, [
            'channel_id' => $channel->id,
        ])
        ->assertNotified();

    // Alias was created
    expect(ChannelAlias::where('reference', 'Bulk Channel')->exists())->toBeTrue();
    $alias = ChannelAlias::where('reference', 'Bulk Channel')->first();
    expect($alias->channel_id)->toBe($channel->id);

    // All shipments were backfilled
    foreach ($shipments as $shipment) {
        expect($shipment->fresh()->channel_id)->toBe($channel->id);
    }
});
