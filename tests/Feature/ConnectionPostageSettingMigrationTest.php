<?php

use App\Enums\PostageSetting;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| carrier-catalog-reset/10 — the postage setting migration
|--------------------------------------------------------------------------
|
| Existing connections are set from what they did before, so nothing an
| install buys changes on deploy. New connections get the driver's default.
|
*/

function postageSettingMigration(): object
{
    return require database_path('migrations/2026_09_26_012300_add_postage_setting_to_data_sources_table.php');
}

/**
 * Put the schema back as it was before the migration, run the setup against
 * it, then migrate forward again.
 */
function migratePostageSettingFrom(Closure $before): void
{
    $migration = postageSettingMigration();
    $migration->down();

    $before();

    $migration->up();
}

it('carries every install forward without changing what it buys', function (): void {
    $optedIn = Client::factory()->create();
    $notOptedIn = Client::factory()->create();

    $amazon = DataSource::factory()->amazon()->create();
    $optedInShop = DataSource::factory()->shopify()->create(['client_id' => $optedIn->id]);
    $otherShop = DataSource::factory()->shopify()->create(['client_id' => $notOptedIn->id]);
    $sharedShop = DataSource::factory()->shopify()->unassigned()->create();
    $database = DataSource::factory()->create();

    migratePostageSettingFrom(function () use ($optedIn): void {
        DB::table('clients')->where('id', $optedIn->id)->update(['blind_purchase_enabled' => true]);
    });

    expect($amazon->refresh()->postage_setting)->toBe(PostageSetting::PackerAndAutomation)
        ->and($optedInShop->refresh()->postage_setting)->toBe(PostageSetting::PackerAndAutomation)
        ->and($otherShop->refresh()->postage_setting)->toBe(PostageSetting::DoesNotSell)
        ->and($sharedShop->refresh()->postage_setting)->toBe(PostageSetting::DoesNotSell)
        ->and($database->refresh()->postage_setting)->toBeNull()
        ->and(Schema::hasColumn('clients', 'blind_purchase_enabled'))->toBeFalse();
});

it('carries a shared Shopify connection forward only when every client it ships for opted in', function (): void {
    $optedIn = Client::factory()->create();
    $alsoOptedIn = Client::factory()->create();
    $notOptedIn = Client::factory()->create();

    $consenting = DataSource::factory()->shopify()->unassigned()->create();
    Shipment::factory()->create(['data_source_id' => $consenting->id, 'client_id' => $optedIn->id]);
    Shipment::factory()->create(['data_source_id' => $consenting->id, 'client_id' => $alsoOptedIn->id]);

    $mixed = DataSource::factory()->shopify()->unassigned()->create(['name' => 'Mixed Shop']);
    Shipment::factory()->create(['data_source_id' => $mixed->id, 'client_id' => $optedIn->id]);
    Shipment::factory()->create(['data_source_id' => $mixed->id, 'client_id' => $notOptedIn->id]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['data_source'] === 'Mixed Shop'
            && $context['opted_in_client_ids'] === [$optedIn->id]);

    migratePostageSettingFrom(function () use ($optedIn, $alsoOptedIn): void {
        DB::table('clients')->whereIn('id', [$optedIn->id, $alsoOptedIn->id])->update(['blind_purchase_enabled' => true]);
    });

    expect($consenting->refresh()->postage_setting)->toBe(PostageSetting::PackerAndAutomation)
        ->and($mixed->refresh()->postage_setting)->toBe(PostageSetting::DoesNotSell);
});

it('gives a new connection its driver\'s default', function (): void {
    expect(DataSource::factory()->shopify()->create()->postage_setting)->toBe(PostageSetting::DoesNotSell)
        ->and(DataSource::factory()->amazon()->create()->postage_setting)->toBe(PostageSetting::PackerOnly)
        ->and(DataSource::factory()->create()->postage_setting)->toBeNull()
        ->and(DataSource::factory()->create()->postageSetting())->toBe(PostageSetting::DoesNotSell);
});

it('opts a client back in when rolled back', function (): void {
    $client = Client::factory()->create();
    DataSource::factory()->shopify()->sellingPostage()->create(['client_id' => $client->id]);

    postageSettingMigration()->down();

    expect((bool) DB::table('clients')->where('id', $client->id)->value('blind_purchase_enabled'))->toBeTrue()
        ->and(Schema::hasColumn('data_sources', 'postage_setting'))->toBeFalse();

    postageSettingMigration()->up();
});
