<?php

use App\Enums\Role;
use App\Http\Integrations\Amazon\Requests\GetMarketplaceParticipations;
use App\Jobs\SyncProductCatalogJob;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\User;
use App\Services\OAuthService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => str_repeat('ab', 32),
        'services.oauth.instance_id' => 'test-instance',
        'app.url' => 'https://test.polybag.app',
    ]);

    $this->dataSource = createShopifyDataSource();
});

/**
 * Receive a Shopify connect for the test connection, after which the
 * controller synchronizes the store's locations from `$locations`.
 *
 * @param  list<array{id: string, name: string}>  $locations
 */
function receiveShopifyConnect(mixed $test, array $locations): TestResponse
{
    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'shopify',
            'access_token' => 'shpat_live_token',
            'nonce' => 'valid-nonce-token',
            'extra' => ['scope' => 'read_orders,write_fulfillments'],
        ]),
    ]);

    Saloon::fake([
        shopifyAccessScopesResponse(),
        MockResponse::make(['data' => ['locations' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => array_map(fn (array $location): array => [...$location, 'isActive' => true, 'address' => null], $locations),
        ]]]),
    ]);

    return $test->actingAs(User::factory()->admin()->create())
        ->withSession([
            'oauth_state.shopify' => 'valid-nonce-token',
            'oauth_data_source_id.shopify' => $test->dataSource->id,
        ])
        ->get('/oauth/shopify/receive?transfer_code=abc123');
}

it('stores tokens on a data source on valid receive with transfer code', function (): void {
    $response = receiveShopifyConnect($this, [['id' => 'gid://shopify/Location/1', 'name' => 'Main']]);

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $this->dataSource->id));
    $response->assertSessionHas('oauth_notification.status', 'success');

    $this->dataSource->refresh();
    expect($this->dataSource->secret('oauth_access_token'))->toBe('shpat_live_token')
        ->and($this->dataSource->settings['auth_mode'])->toBe('authorization_code')
        ->and($this->dataSource->settings['oauth_connected_at'])->not->toBeNull();
});

it('offers a catalog sync once a store is connected, without starting one', function (): void {
    Queue::fake();

    $response = receiveShopifyConnect($this, [['id' => 'gid://shopify/Location/1', 'name' => 'Main']]);

    $response->assertSessionHas('offer_product_catalog_sync', $this->dataSource->id);
    Queue::assertNotPushed(SyncProductCatalogJob::class);
});

it('maps a store with one location to the only PolyBag location on connect', function (): void {
    $response = receiveShopifyConnect($this, [['id' => 'gid://shopify/Location/1', 'name' => 'Main']]);

    $default = Location::getDefault();
    $response->assertSessionHas('oauth_notification.title', "Shopify connected. Main was mapped to {$default->name} automatically.");
    expect($this->dataSource->locations()->sole()->location_id)->toBe($default->id);
});

it('names the locations left to map when a store has several', function (): void {
    $response = receiveShopifyConnect($this, [
        ['id' => 'gid://shopify/Location/1', 'name' => 'Edmonds'],
        ['id' => 'gid://shopify/Location/2', 'name' => 'London'],
    ]);

    $response->assertSessionHas('oauth_notification.status', 'warning');
    $response->assertSessionHas('oauth_notification.body', 'Not yet mapped or ignored: Edmonds, London.');
    expect($this->dataSource->locations()->whereNotNull('location_id')->exists())->toBeFalse();
});

it('keeps the connection when its locations cannot be read on connect', function (): void {
    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'shopify',
            'access_token' => 'shpat_live_token',
            'nonce' => 'valid-nonce-token',
        ]),
    ]);
    Saloon::fake([shopifyAccessScopesResponse(['read_orders'])]);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->withSession([
            'oauth_state.shopify' => 'valid-nonce-token',
            'oauth_data_source_id.shopify' => $this->dataSource->id,
        ])
        ->get('/oauth/shopify/receive?transfer_code=abc123');

    $response->assertSessionHas('oauth_notification.status', 'warning');
    $response->assertSessionHas('oauth_notification.title', 'Shopify connected, but its locations could not be retrieved.');
    expect($this->dataSource->refresh()->secret('oauth_access_token'))->toBe('shpat_live_token');
});

it('forbids non-admin users from receiving oauth callbacks', function (): void {
    $user = User::factory()->create(['role' => Role::Packer]);

    Http::fake();

    $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => 'nonce'])
        ->get('/oauth/shopify/receive?transfer_code=abc123')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('rejects receive with mismatched nonce', function (): void {
    $user = User::factory()->admin()->create();

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'shopify',
            'access_token' => 'shpat_token',
            'nonce' => 'broker-nonce',
            'extra' => [],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.shopify' => 'different-nonce',
            'oauth_data_source_id.shopify' => $this->dataSource->id,
        ])
        ->get('/oauth/shopify/receive?transfer_code=abc123');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $this->dataSource->id));
    $response->assertSessionHas('oauth_notification.status', 'danger');

    expect($this->dataSource->fresh()->secret('oauth_access_token'))->toBeNull();
});

it('handles error redirect from broker', function (): void {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->get('/oauth/shopify/receive?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
    $response->assertSessionHas('oauth_notification.title', 'Connection failed (access_denied). Details are in the log.');
});

it('handles missing transfer code', function (): void {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->get('/oauth/shopify/receive');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
});

it('handles broker claim failure', function (): void {
    $user = User::factory()->admin()->create();

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response('Transfer code not found', 404),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.shopify' => 'nonce',
            'oauth_data_source_id.shopify' => $this->dataSource->id,
        ])
        ->get('/oauth/shopify/receive?transfer_code=expired-code');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $this->dataSource->id));
    $response->assertSessionHas('oauth_notification.status', 'danger');
});

it('initiates a data source authorization via the broker', function (): void {
    $url = app(OAuthService::class)->initiateAuthorizationForDataSource('shopify', $this->dataSource);

    expect($url)->toStartWith('https://connect.polybag.app/oauth/shopify/authorize?')
        ->and($url)->toContain('instance_id=test-instance')
        ->and($url)->toContain(urlencode('https://test.polybag.app'))
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('shop=test-shop.myshopify.com');

    expect(session('oauth_state.shopify'))->not->toBeNull()
        ->and(strlen(session('oauth_state.shopify')))->toBe(40)
        ->and(session('oauth_data_source_id.shopify'))->toBe($this->dataSource->id);
});

it('refuses to send a shop domain outside myshopify.com to the broker', function (): void {
    $this->dataSource->update(['settings' => [...$this->dataSource->settings, 'shop_domain' => 'shop.example.com']]);

    app(OAuthService::class)->initiateAuthorizationForDataSource('shopify', $this->dataSource);
})->throws(RuntimeException::class, 'must be a *.myshopify.com host');

it('throws when broker is not configured', function (): void {
    config([
        'services.oauth.broker_url' => null,
        'services.oauth.broker_secret' => null,
        'services.oauth.instance_id' => null,
    ]);

    app(OAuthService::class)->initiateAuthorizationForDataSource('shopify', $this->dataSource);
})->throws(RuntimeException::class, 'Guided sign-in is not available');

it('reports broker configuration from the OAuth service', function (): void {
    expect(app(OAuthService::class)->isBrokerConfigured())->toBeTrue();

    config(['services.oauth.broker_secret' => null]);

    expect(app(OAuthService::class)->isBrokerConfigured())->toBeFalse();
});

it('uses the Amazon display name for carrier account callbacks', function (): void {
    $user = User::factory()->admin()->create();
    $account = CarrierAccount::factory()->create();
    $oauthService = Mockery::mock(OAuthService::class);
    $oauthService->shouldReceive('handleReceiveForAccount')
        ->once()
        ->with('sp-api', 'amazon-transfer', Mockery::on(fn (CarrierAccount $received): bool => $received->is($account)));
    app()->instance(OAuthService::class, $oauthService);

    $response = $this->actingAs($user)
        ->withSession(['oauth_account_id.sp-api' => $account->id])
        ->get('/oauth/sp-api/receive?transfer_code=amazon-transfer');

    $response->assertRedirect(route('filament.app.resources.carrier-accounts.edit', $account->id));
    $response->assertSessionHas('oauth_notification.title', 'Amazon connected successfully.');
});

it('disconnects and clears a data source OAuth connection', function (): void {
    $this->dataSource->mergeSecret('oauth_access_token', 'token-to-remove');
    $settings = $this->dataSource->settings;
    $settings['auth_mode'] = 'authorization_code';
    $settings['oauth_scopes'] = 'read_orders';
    $settings['oauth_connected_at'] = now()->toIso8601String();
    $this->dataSource->settings = $settings;
    $this->dataSource->save();

    $oauthService = app(OAuthService::class);
    expect($oauthService->isDataSourceConnected($this->dataSource))->toBeTrue();

    $oauthService->disconnectDataSource('shopify', $this->dataSource);

    $this->dataSource->refresh();
    expect($oauthService->isDataSourceConnected($this->dataSource))->toBeFalse()
        ->and($this->dataSource->secret('oauth_access_token'))->toBeNull()
        ->and($this->dataSource->settings['auth_mode'] ?? null)->toBeNull()
        ->and($this->dataSource->settings['oauth_connected_at'] ?? null)->toBeNull();
});

it('stores an Amazon refresh token and selling partner from the broker', function (): void {
    $user = User::factory()->admin()->create();
    $nonce = 'amazon-nonce';
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['marketplace_ids' => ['ATVPDKIKX0DER']],
    ]);

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'sp-api',
            'access_token' => 'temporary-amazon-access-token',
            'refresh_token' => 'amazon-refresh-token',
            'expires_in' => 3600,
            'nonce' => $nonce,
            'extra' => ['selling_partner_id' => 'SELLER123'],
        ]),
    ]);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [
                [
                    'marketplace' => [
                        'id' => 'ATVPDKIKX0DER',
                        'name' => 'Amazon.com',
                        'countryCode' => 'US',
                    ],
                    'participation' => [
                        'isParticipating' => true,
                        'hasSuspendedListings' => false,
                    ],
                ],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.sp-api' => $nonce,
            'oauth_data_source_id.sp-api' => $source->id,
        ])
        ->get('/oauth/sp-api/receive?transfer_code=amazon-transfer');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $source->id));
    $response->assertSessionHas('oauth_notification.title', 'Amazon connected. Amazon.com (US) was selected automatically.');
    // A newly connected seller account is offered a catalog sync once (`product-catalog-sync/04`).
    $response->assertSessionHas('offer_product_catalog_sync', $source->id);

    $source->refresh();
    expect($source->secret('refresh_token'))->toBe('amazon-refresh-token')
        ->and($source->secret('oauth_access_token'))->toBeNull()
        ->and($source->settings['auth_mode'])->toBe('authorization_code')
        ->and($source->settings['amazon_selling_partner_id'])->toBe('SELLER123')
        ->and($source->settings['marketplace_ids'])->toBe(['ATVPDKIKX0DER'])
        ->and($source->settings['amazon_marketplaces'])->toHaveCount(1)
        ->and(app(OAuthService::class)->isDataSourceConnected($source))->toBeTrue();
});

it('keeps Amazon connected when post-OAuth marketplace discovery fails', function (): void {
    $user = User::factory()->admin()->create();
    $nonce = 'amazon-discovery-failure-nonce';
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['marketplace_ids' => ['ATVPDKIKX0DER']],
    ]);

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'sp-api',
            'access_token' => 'temporary-amazon-access-token',
            'refresh_token' => 'amazon-refresh-token',
            'expires_in' => 3600,
            'nonce' => $nonce,
            'extra' => ['selling_partner_id' => 'SELLER123'],
        ]),
    ]);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'errors' => [['code' => 'Unauthorized']],
        ], 403),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.sp-api' => $nonce,
            'oauth_data_source_id.sp-api' => $source->id,
        ])
        ->get('/oauth/sp-api/receive?transfer_code=amazon-transfer');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $source->id));
    $response->assertSessionHas('oauth_notification.status', 'warning');
    $response->assertSessionHas('oauth_notification.title', 'Amazon connected, but marketplaces could not be retrieved.');

    $source->refresh();
    expect($source->secret('refresh_token'))->toBe('amazon-refresh-token')
        ->and($source->settings['auth_mode'])->toBe('authorization_code')
        ->and($source->settings['marketplace_ids'])->toBe(['ATVPDKIKX0DER'])
        ->and($source->settings['amazon_marketplaces_sync_error'])->toContain('403');
});

it('preserves an existing marketplace omitted during Amazon reconnection', function (): void {
    $user = User::factory()->admin()->create();
    $nonce = 'amazon-omitted-marketplace-nonce';
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['marketplace_ids' => ['A1F83G8C2ARO7P']],
    ]);

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'sp-api',
            'access_token' => 'temporary-amazon-access-token',
            'refresh_token' => 'amazon-refresh-token',
            'expires_in' => 3600,
            'nonce' => $nonce,
            'extra' => ['selling_partner_id' => 'SELLER123'],
        ]),
    ]);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [[
                'marketplace' => [
                    'id' => 'ATVPDKIKX0DER',
                    'name' => 'Amazon.com',
                    'countryCode' => 'US',
                ],
                'participation' => [
                    'isParticipating' => true,
                    'hasSuspendedListings' => false,
                ],
            ]],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.sp-api' => $nonce,
            'oauth_data_source_id.sp-api' => $source->id,
        ])
        ->get('/oauth/sp-api/receive?transfer_code=amazon-transfer');

    $response->assertSessionHas('oauth_notification.status', 'warning');
    $response->assertSessionHas('oauth_notification.title', 'Amazon connected, but a selected marketplace was not returned.');
    expect($source->fresh()->settings['marketplace_ids'])->toBe(['A1F83G8C2ARO7P']);
});

it('rejects an Amazon broker response without a selling partner ID', function (): void {
    $user = User::factory()->admin()->create();
    $nonce = 'amazon-nonce';
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['marketplace_ids' => ['ATVPDKIKX0DER']],
    ]);

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'sp-api',
            'refresh_token' => 'amazon-refresh-token',
            'nonce' => $nonce,
            'extra' => [],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.sp-api' => $nonce,
            'oauth_data_source_id.sp-api' => $source->id,
        ])
        ->get('/oauth/sp-api/receive?transfer_code=amazon-transfer');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $source->id));
    $response->assertSessionHas('oauth_notification.title', 'Connection failed: No Amazon selling partner ID received from broker.');

    expect($source->refresh()->secret('refresh_token'))->toBeNull();
});

it('returns an Amazon denial to the originating data source', function (): void {
    $user = User::factory()->admin()->create();
    $source = DataSource::factory()->create(['source_type' => AmazonSource::class]);

    $response = $this->actingAs($user)
        ->withSession(['oauth_data_source_id.sp-api' => $source->id])
        ->get('/oauth/sp-api/receive?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $source->id));
    $response->assertSessionHas('oauth_notification.title', 'Connection failed (access_denied). Details are in the log.');
});

it('disconnects an Amazon OAuth data source and removes its refresh token', function (): void {
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => [
            'auth_mode' => 'authorization_code',
            'oauth_connected_at' => now()->toIso8601String(),
            'amazon_selling_partner_id' => 'SELLER123',
            'marketplace_ids' => ['ATVPDKIKX0DER'],
            'amazon_marketplaces' => [[
                'id' => 'ATVPDKIKX0DER',
                'name' => 'Amazon.com',
                'country_code' => 'US',
                'is_participating' => true,
                'has_suspended_listings' => false,
            ]],
            'amazon_marketplaces_synced_at' => now()->toIso8601String(),
        ],
        'secret_settings' => ['refresh_token' => 'amazon-refresh-token'],
    ]);

    app(OAuthService::class)->disconnectDataSource('sp-api', $source);

    $source->refresh();
    expect($source->secret('refresh_token'))->toBeNull()
        ->and($source->settings['auth_mode'] ?? null)->toBeNull()
        ->and($source->settings['amazon_selling_partner_id'] ?? null)->toBeNull()
        ->and($source->settings['amazon_marketplaces'] ?? null)->toBeNull()
        ->and($source->settings['amazon_marketplaces_synced_at'] ?? null)->toBeNull()
        ->and($source->settings['marketplace_ids'])->toBe(['ATVPDKIKX0DER'])
        ->and(app(OAuthService::class)->isDataSourceConnected($source))->toBeFalse();
});
