<?php

use App\Enums\ShipmentStatus;
use App\Filament\Resources\DataSources\Pages\CreateDataSource;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Http\Integrations\Amazon\Requests\GetMarketplaceParticipations;
use App\Models\Channel;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * `connection-identity/01`: no two active connections read the same Shopify
 * store or Amazon seller account, and one Amazon connection reads several
 * marketplaces.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->channel = Channel::factory()->create();
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
});

/**
 * An Amazon connection whose seller ID OAuth recorded.
 *
 * @param  array<string, mixed>  $attributes
 */
function amazonSellerConnection(string $name, ?string $sellerId, bool $active = true, array $attributes = []): DataSource
{
    return DataSource::factory()->amazon()->create([
        'name' => $name,
        'active' => $active,
        'settings' => array_filter([
            'channel_name' => test()->channel->id,
            'marketplace_ids' => ['ATVPDKIKX0DER'],
            'primary_marketplace_id' => 'ATVPDKIKX0DER',
            'auth_mode' => $sellerId === null ? 'manual' : 'authorization_code',
            'amazon_selling_partner_id' => $sellerId,
        ]),
        'secret_settings' => ['refresh_token' => 'refresh-'.$name],
        ...$attributes,
    ]);
}

// ── Shopify ───────────────────────────────────────────────────────────────────

it('refuses a new active Shopify connection to a store another active one reads, whatever its case', function (string $domain): void {
    createShopifyDataSource(['shop_domain' => 'acme-store.myshopify.com'])->update(['name' => 'Acme Main']);
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Acme Again',
            'source_type' => ShopifySource::class,
            'active' => true,
            'settings.shop_domain' => $domain,
            'settings.client_id' => 'client-id',
            'settings.client_secret' => 'client-secret',
            'settings.channel_name' => $this->channel->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['settings.shop_domain'])
        ->assertSee('Another active connection, Acme Main, already reads this store.');

    expect(DataSource::where('name', 'Acme Again')->exists())->toBeFalse();
})->with(['the same' => 'acme-store.myshopify.com', 'differing in case' => 'Acme-Store.MyShopify.com']);

it('lets an inactive Shopify connection share a store, and refuses activating it while the other is active', function (): void {
    $main = createShopifyDataSource(['shop_domain' => 'acme-store.myshopify.com']);
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Acme History',
            'source_type' => ShopifySource::class,
            'active' => false,
            'settings.shop_domain' => 'acme-store.myshopify.com',
            'settings.client_id' => 'client-id',
            'settings.client_secret' => 'client-secret',
            'settings.channel_name' => $this->channel->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $history = DataSource::where('name', 'Acme History')->sole();

    Livewire::test(EditDataSource::class, ['record' => $history->id])
        ->fillForm(['active' => true])
        ->call('save')
        ->assertHasFormErrors(['settings.shop_domain']);

    expect($history->fresh()->active)->toBeFalse();

    $main->update(['active' => false]);

    Livewire::test(EditDataSource::class, ['record' => $history->id])
        ->fillForm(['active' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($history->fresh()->active)->toBeTrue();
});

it('saves an active Shopify connection that is the only one reading its store', function (): void {
    $only = createShopifyDataSource(['shop_domain' => 'acme-store.myshopify.com', 'channel_name' => $this->channel->id]);
    createShopifyDataSource(['shop_domain' => 'other-store.myshopify.com']);
    $this->actingAs($this->admin);

    Livewire::test(EditDataSource::class, ['record' => $only->id])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($only->fresh()->name)->toBe('Renamed');
});

// ── Amazon on save ────────────────────────────────────────────────────────────

it('refuses activating an Amazon connection whose seller another active connection reads', function (): void {
    amazonSellerConnection('Seller Main', 'A1SELLER');
    $second = amazonSellerConnection('Seller Again', 'A1SELLER', active: false);
    $this->actingAs($this->admin);

    Livewire::test(EditDataSource::class, ['record' => $second->id])
        ->fillForm(['active' => true])
        ->call('save')
        ->assertHasFormErrors(['active'])
        ->assertSee('Another active connection, Seller Main, already reads this seller account. Add marketplaces to it instead.');

    expect($second->fresh()->active)->toBeFalse();
});

it('saves an inactive Amazon connection sharing a seller, and one reading another seller', function (): void {
    amazonSellerConnection('Seller Main', 'A1SELLER');
    $inactive = amazonSellerConnection('Seller History', 'A1SELLER', active: false);
    $other = amazonSellerConnection('Other Seller', 'A2SELLER');
    $this->actingAs($this->admin);

    Livewire::test(EditDataSource::class, ['record' => $inactive->id])
        ->fillForm(['name' => 'Seller History (old)'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditDataSource::class, ['record' => $other->id])
        ->fillForm(['name' => 'Other Seller (renamed)'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('does not check an Amazon connection with its own refresh token and no seller ID', function (): void {
    amazonSellerConnection('Own Token One', null);
    $second = amazonSellerConnection('Own Token Two', null, active: false);
    $this->actingAs($this->admin);

    expect($second->conflictingConnection())->toBeNull();

    Livewire::test(EditDataSource::class, ['record' => $second->id])
        ->fillForm(['active' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($second->fresh()->active)->toBeTrue();
});

// ── Amazon after OAuth ────────────────────────────────────────────────────────

/**
 * Return from Amazon's consent screen to `$source`, granted for `$sellerId`.
 */
function receiveAmazonGrant(mixed $test, DataSource $source, string $sellerId): TestResponse
{
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => str_repeat('ab', 32),
        'services.oauth.instance_id' => 'test-instance',
    ]);

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'sp-api',
            'access_token' => 'temporary-amazon-access-token',
            'refresh_token' => 'granted-refresh-token',
            'expires_in' => 3600,
            'nonce' => 'amazon-nonce',
            'extra' => ['selling_partner_id' => $sellerId],
        ]),
    ]);

    return $test->actingAs($test->admin)
        ->withSession([
            'oauth_state.sp-api' => 'amazon-nonce',
            'oauth_data_source_id.sp-api' => $source->id,
        ])
        ->get('/oauth/sp-api/receive?transfer_code=amazon-transfer');
}

it('keeps the grant but makes a new connection inactive when another active one reads the seller', function (): void {
    amazonSellerConnection('Seller Main', 'A1SELLER');
    $new = DataSource::factory()->amazon()->create(['name' => 'Seller Again', 'active' => true, 'settings' => ['channel_name' => $this->channel->id]]);
    Saloon::fake([]);

    $response = receiveAmazonGrant($this, $new, 'A1SELLER');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $new->id))
        ->assertSessionHas('oauth_notification.status', 'warning')
        ->assertSessionHas('oauth_notification.title', 'Amazon connected, but this connection was made inactive.')
        ->assertSessionHas('oauth_notification.body', 'Another active connection, Seller Main, already reads this seller account. Add marketplaces to it instead. Delete this connection, or deactivate the other one.')
        ->assertSessionMissing('offer_product_catalog_sync');

    $new->refresh();
    expect($new->active)->toBeFalse()
        ->and($new->secret('refresh_token'))->toBe('granted-refresh-token')
        ->and($new->settings['amazon_selling_partner_id'])->toBe('A1SELLER')
        ->and($new->settings)->not->toHaveKey('amazon_marketplaces');

    // Discovery is skipped.
    Saloon::assertNothingSent();
});

it('makes a connection reconnected to a seller another active connection reads inactive', function (): void {
    amazonSellerConnection('Seller Two Main', 'A2SELLER');
    $reconnected = amazonSellerConnection('Seller One', 'A1SELLER');
    Saloon::fake([]);

    receiveAmazonGrant($this, $reconnected, 'A2SELLER')
        ->assertSessionHas('oauth_notification.body', 'Another active connection, Seller Two Main, already reads this seller account. Add marketplaces to it instead. Delete this connection, or deactivate the other one.');

    expect($reconnected->fresh()->active)->toBeFalse()
        ->and($reconnected->fresh()->settings['amazon_selling_partner_id'])->toBe('A2SELLER');
    Saloon::assertNothingSent();
});

it('says a connection that was already inactive stays inactive', function (): void {
    amazonSellerConnection('Seller Main', 'A1SELLER');
    $inactive = amazonSellerConnection('Seller History', 'A2SELLER', active: false);
    Saloon::fake([]);

    receiveAmazonGrant($this, $inactive, 'A1SELLER')
        ->assertSessionHas('oauth_notification.title', 'Amazon connected, but this connection stays inactive.');

    expect($inactive->fresh()->active)->toBeFalse();
});

it('connects normally when only an inactive connection holds the seller', function (): void {
    amazonSellerConnection('Seller History', 'A1SELLER', active: false);
    $new = DataSource::factory()->amazon()->create(['name' => 'Seller New', 'active' => true, 'settings' => ['channel_name' => $this->channel->id]]);
    Saloon::fake([GetMarketplaceParticipations::class => MockResponse::make(['payload' => []])]);

    receiveAmazonGrant($this, $new, 'A1SELLER')
        ->assertSessionHas('oauth_notification.title', 'Amazon connected, but no supported marketplaces were found.');

    expect($new->fresh()->active)->toBeTrue();
    Saloon::assertSent(GetMarketplaceParticipations::class);
});

// ── Removing a marketplace ────────────────────────────────────────────────────

it('warns how many open shipments a removed marketplace leaves, and leaves them open', function (): void {
    $connection = amazonSellerConnection('Seller', 'A1SELLER', attributes: [
        'settings' => [
            'channel_name' => $this->channel->id,
            'marketplace_ids' => ['ATVPDKIKX0DER', 'A2EUQ1WTGCTBG2'],
            'primary_marketplace_id' => 'ATVPDKIKX0DER',
            'auth_mode' => 'authorization_code',
            'amazon_selling_partner_id' => 'A1SELLER',
        ],
    ]);
    $inMarketplace = fn (string $marketplaceId, ShipmentStatus $status = ShipmentStatus::Open): Shipment => Shipment::factory()->create([
        'data_source_id' => $connection->id,
        'status' => $status,
        'metadata' => ['amazon_sales_channel' => ['channelName' => 'Amazon', 'marketplaceId' => $marketplaceId]],
    ]);
    $openInCanada = collect([$inMarketplace('A2EUQ1WTGCTBG2'), $inMarketplace('A2EUQ1WTGCTBG2')]);
    $inMarketplace('A2EUQ1WTGCTBG2', ShipmentStatus::Shipped);
    $inMarketplace('ATVPDKIKX0DER');
    $this->actingAs($this->admin);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->fillForm(['settings.marketplace_ids' => ['ATVPDKIKX0DER']])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('2 open shipments from a removed marketplace');

    expect($connection->fresh()->amazonMarketplaceIds())->toBe(['ATVPDKIKX0DER'])
        ->and($openInCanada->every(fn (Shipment $shipment): bool => $shipment->fresh()->status === ShipmentStatus::Open))->toBeTrue();
});

it('does not warn when a marketplace is added', function (): void {
    $connection = amazonSellerConnection('Seller', 'A1SELLER');
    Shipment::factory()->create([
        'data_source_id' => $connection->id,
        'metadata' => ['amazon_sales_channel' => ['channelName' => 'Amazon', 'marketplaceId' => 'ATVPDKIKX0DER']],
    ]);
    $this->actingAs($this->admin);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->fillForm(['settings.marketplace_ids' => ['ATVPDKIKX0DER', 'A2EUQ1WTGCTBG2']])
        ->assertFormFieldVisible('settings.primary_marketplace_id')
        ->assertFormSet(['settings.primary_marketplace_id' => 'ATVPDKIKX0DER'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified('1 open shipment from a removed marketplace');

    expect($connection->fresh()->amazonMarketplaceIds())->toBe(['ATVPDKIKX0DER', 'A2EUQ1WTGCTBG2'])
        ->and($connection->fresh()->primaryAmazonMarketplaceId())->toBe('ATVPDKIKX0DER');
});

// ── Choosing marketplaces ─────────────────────────────────────────────────────

it('offers an OAuth connection its discovered marketplaces, flagging a selected one no longer reported', function (): void {
    $connection = amazonSellerConnection('Seller', 'A1SELLER', attributes: [
        'settings' => [
            'channel_name' => $this->channel->id,
            'marketplace_ids' => ['A2EUQ1WTGCTBG2'],
            'auth_mode' => 'authorization_code',
            'amazon_selling_partner_id' => 'A1SELLER',
            'amazon_marketplaces' => [
                ['id' => 'ATVPDKIKX0DER', 'name' => 'Amazon.com', 'country_code' => 'US', 'is_participating' => true, 'has_suspended_listings' => false],
                ['id' => 'A1AM78C64UM0Y8', 'name' => 'Amazon.com.mx', 'country_code' => 'MX', 'is_participating' => true, 'has_suspended_listings' => true],
            ],
        ],
    ]);
    $this->actingAs($this->admin);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->assertFormFieldHidden('settings.primary_marketplace_id')
        ->assertFormFieldExists('settings.marketplace_ids', fn (Select $field): bool => $field->isMultiple()
            && $field->getOptions() === [
                'ATVPDKIKX0DER' => 'Amazon.com (US)',
                'A1AM78C64UM0Y8' => 'Amazon.com.mx (MX) — Listings suspended',
                'A2EUQ1WTGCTBG2' => 'Amazon.ca (CA) — Not returned by Amazon',
            ]);
});

// ── Migration ─────────────────────────────────────────────────────────────────

it('converts an Amazon connection\'s marketplace_id to marketplace_ids and a primary', function (): void {
    $migration = require database_path('migrations/2026_10_30_230341_convert_amazon_marketplace_id_to_marketplace_ids_on_data_sources_table.php');
    $amazon = DataSource::factory()->amazon()->create();
    $unchosen = DataSource::factory()->amazon()->create();
    $shopify = createShopifyDataSource(['marketplace_id' => 'kept-as-is']);
    DB::table('data_sources')->where('id', $amazon->id)->update(['settings' => json_encode(['channel_name' => 'Amazon', 'marketplace_id' => 'A2EUQ1WTGCTBG2'])]);
    DB::table('data_sources')->where('id', $unchosen->id)->update(['settings' => json_encode(['channel_name' => 'Amazon'])]);

    $migration->up();

    expect($amazon->fresh()->settings)->toBe([
        'channel_name' => 'Amazon',
        'marketplace_ids' => ['A2EUQ1WTGCTBG2'],
        'primary_marketplace_id' => 'A2EUQ1WTGCTBG2',
    ])
        ->and($unchosen->fresh()->settings)->toBe(['channel_name' => 'Amazon'])
        ->and($shopify->fresh()->settings['marketplace_id'])->toBe('kept-as-is');

    $migration->down();

    expect($amazon->fresh()->settings)->toBe(['channel_name' => 'Amazon', 'marketplace_id' => 'A2EUQ1WTGCTBG2']);
});

it('rewrites an import account key that named the account and marketplace, so the import does not restart', function (): void {
    $migration = require database_path('migrations/2026_10_30_230341_convert_amazon_marketplace_id_to_marketplace_ids_on_data_sources_table.php');
    $oauth = DataSource::factory()->amazon()->create(['secret_settings' => ['refresh_token' => 'oauth-token']]);
    $ownToken = DataSource::factory()->amazon()->create(['secret_settings' => ['refresh_token' => 'own-token']]);
    $stale = DataSource::factory()->amazon()->create(['secret_settings' => ['refresh_token' => 'stale-token']]);
    $tokenAccount = 'token:'.hash_hmac('sha256', 'own-token', (string) config('app.key'));

    DB::table('data_sources')->where('id', $oauth->id)->update([
        'settings' => json_encode(['marketplace_id' => 'ATVPDKIKX0DER', 'auth_mode' => 'authorization_code', 'amazon_selling_partner_id' => 'A1SELLER']),
        'import_account_key' => hash('sha256', 'partner:A1SELLER|ATVPDKIKX0DER'),
    ]);
    DB::table('data_sources')->where('id', $ownToken->id)->update([
        'settings' => json_encode(['marketplace_id' => 'A2EUQ1WTGCTBG2', 'auth_mode' => 'manual']),
        'import_account_key' => hash('sha256', $tokenAccount.'|A2EUQ1WTGCTBG2'),
    ]);
    // Last read from another seller: still a restart.
    DB::table('data_sources')->where('id', $stale->id)->update([
        'settings' => json_encode(['marketplace_id' => 'ATVPDKIKX0DER', 'auth_mode' => 'authorization_code', 'amazon_selling_partner_id' => 'A1SELLER']),
        'import_account_key' => hash('sha256', 'partner:A2OTHER|ATVPDKIKX0DER'),
    ]);

    $migration->up();

    expect($oauth->fresh()->import_account_key)->toBe($oauth->fresh()->importAccountKey())
        ->and($ownToken->fresh()->import_account_key)->toBe($ownToken->fresh()->importAccountKey())
        ->and($stale->fresh()->import_account_key)->toBe(hash('sha256', 'partner:A2OTHER|ATVPDKIKX0DER'));

    $migration->down();

    expect($oauth->fresh()->import_account_key)->toBe(hash('sha256', 'partner:A1SELLER|ATVPDKIKX0DER'))
        ->and($ownToken->fresh()->import_account_key)->toBe(hash('sha256', $tokenAccount.'|A2EUQ1WTGCTBG2'));
});
