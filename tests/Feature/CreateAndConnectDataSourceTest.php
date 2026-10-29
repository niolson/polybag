<?php

use App\Filament\Resources\DataSources\DataSourceResource;
use App\Filament\Resources\DataSources\Pages\CreateDataSource;
use App\Models\Channel;
use App\Models\DataSource;
use App\Models\User;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    app(SettingsService::class)->clearCache();

    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
});

function createFormAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('form-actions', 'content');
}

function withoutOAuthBroker(): void
{
    config(['services.oauth.broker_url' => null]);
}

/** @return array<string, mixed> */
function newShopifyConnection(array $overrides = []): array
{
    return [
        'name' => 'Shopify Store',
        'source_type' => ShopifySource::class,
        'import_enabled' => false,
        'settings.shop_domain' => 'example-store.myshopify.com',
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function newAmazonConnection(array $overrides = []): array
{
    return [
        'name' => 'Amazon Seller',
        'source_type' => AmazonSource::class,
        'active' => false,
        'settings.channel_name' => Channel::factory()->create()->id,
        ...$overrides,
    ];
}

it('sends a new Shopify connection into OAuth', function (): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm(newShopifyConnection())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirectContains('https://connect.polybag.app/oauth/shopify/authorize?')
        ->assertRedirectContains('shop=example-store.myshopify.com');

    $source = DataSource::where('name', 'Shopify Store')->firstOrFail();

    expect(session('oauth_data_source_id.shopify'))->toBe($source->id);
});

it('sends a new Amazon connection into OAuth', function (): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm(newAmazonConnection())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirectContains('https://connect.polybag.app/oauth/sp-api/authorize?');

    $source = DataSource::where('name', 'Amazon Seller')->firstOrFail();

    expect(session('oauth_data_source_id.sp-api'))->toBe($source->id);
});

it('keeps the connection and lands on its edit page when OAuth cannot start', function (): void {
    $this->partialMock(OAuthService::class)
        ->shouldReceive('initiateAuthorizationForDataSource')
        ->andThrow(new RuntimeException('The connection\'s Shopify shop domain must be a *.myshopify.com host.'));

    Livewire::test(CreateDataSource::class)
        ->fillForm(newShopifyConnection())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Cannot connect Shopify')
        ->assertRedirect(DataSourceResource::getUrl('edit', [
            'record' => DataSource::where('name', 'Shopify Store')->firstOrFail(),
        ]));
});

it('goes to the edit page without the broker', function (): void {
    withoutOAuthBroker();

    Livewire::test(CreateDataSource::class)
        ->fillForm(newShopifyConnection([
            'settings.client_id' => 'own-client-id',
            'settings.client_secret' => 'own-client-secret',
        ]))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(DataSourceResource::getUrl('edit', [
            'record' => DataSource::where('name', 'Shopify Store')->firstOrFail(),
        ]));

    expect(session('oauth_data_source_id.shopify'))->toBeNull();
});

it('goes to the edit page when Dev enters the connection\'s own credentials', function (array $connection, string $provider): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm($connection)
        ->assertActionHasLabel(createFormAction('create'), 'Create')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(DataSourceResource::getUrl('edit', ['record' => DataSource::latest('id')->firstOrFail()]));

    expect(session("oauth_data_source_id.{$provider}"))->toBeNull();
})->with([
    'Shopify app Client ID' => fn (): array => [newShopifyConnection([
        'settings.client_id' => 'own-client-id',
        'settings.client_secret' => 'own-client-secret',
    ]), 'shopify'],
    'Amazon refresh token' => fn (): array => [newAmazonConnection([
        'settings.refresh_token' => 'Atzr|own-token',
        'settings.marketplace_id' => 'ATVPDKIKX0DER',
    ]), 'sp-api'],
]);

it('offers no create another', function (): void {
    Livewire::test(CreateDataSource::class)
        ->assertActionExists(createFormAction('create'))
        ->assertActionDoesNotExist(createFormAction('createAnother'));
});

it('does not redirect an Amazon create that MFA halts', function (): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm(newAmazonConnection(['active' => true]))
        ->call('create')
        ->assertHasErrors('data.active')
        ->assertNoRedirect();

    expect(DataSource::where('name', 'Amazon Seller')->exists())->toBeFalse();
});

it('says creating leads into OAuth, on the notice and the button', function (array $connection, string $providerName): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm($connection)
        ->assertSee("After you create this connection you will be sent to {$providerName} to authorize it, and brought back here.")
        ->assertActionHasLabel(createFormAction('create'), "Create & connect {$providerName}");
})->with([
    'Shopify' => fn (): array => [newShopifyConnection(), 'Shopify'],
    'Amazon' => fn (): array => [newAmazonConnection(), 'Amazon'],
]);

it('drops the notice and button wording once Dev enters its own credentials', function (): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm(newShopifyConnection(['settings.client_id' => 'own-client-id']))
        ->assertDontSee('you will be sent to Shopify')
        ->assertActionHasLabel(createFormAction('create'), 'Create');
});

it('says which credentials to enter without the broker', function (): void {
    withoutOAuthBroker();

    Livewire::test(CreateDataSource::class)
        ->fillForm(newShopifyConnection())
        ->assertSee('Guided OAuth needs the hosted OAuth broker, which is not configured.')
        ->assertSee('Enter the store&#039;s own Shopify app Client ID and Client Secret below.', escape: false)
        ->assertDontSee('you will be sent to Shopify')
        ->assertActionHasLabel(createFormAction('create'), 'Create');
});

it('leaves the Amazon marketplace to OAuth discovery', function (): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm(newAmazonConnection())
        ->assertFormFieldHidden('settings.marketplace_id')
        ->assertSee('Its marketplace is set from the ones Amazon reports for the seller.')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirectContains('/oauth/sp-api/authorize?');

    $source = DataSource::where('name', 'Amazon Seller')->firstOrFail();

    expect($source->settings['marketplace_id'] ?? null)->toBeNull();
});

it('asks for the Amazon marketplace when Dev enters its own refresh token', function (): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm(newAmazonConnection(['settings.refresh_token' => 'Atzr|own-token']))
        ->assertFormFieldVisible('settings.marketplace_id')
        ->call('create')
        ->assertHasFormErrors(['settings.marketplace_id' => 'required']);
});

it('asks for the Amazon marketplace without the broker', function (): void {
    withoutOAuthBroker();

    Livewire::test(CreateDataSource::class)
        ->fillForm(newAmazonConnection(['settings.refresh_token' => 'Atzr|own-token']))
        ->call('create')
        ->assertHasFormErrors(['settings.marketplace_id' => 'required']);
});

it('returns to the new connection when the create page opens again before OAuth finishes', function (): void {
    Livewire::test(CreateDataSource::class)
        ->fillForm(newShopifyConnection())
        ->call('create')
        ->assertRedirectContains('/oauth/shopify/authorize?');

    Livewire::test(CreateDataSource::class)
        ->assertNotified('Shopify Store is not connected to Shopify yet.')
        ->assertRedirect(DataSourceResource::getUrl('edit', [
            'record' => DataSource::where('name', 'Shopify Store')->firstOrFail(),
        ]));

    Livewire::test(CreateDataSource::class)->assertNoRedirect();
});
