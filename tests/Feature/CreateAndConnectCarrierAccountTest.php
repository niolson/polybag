<?php

use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use App\Filament\Resources\CarrierAccounts\Pages\CreateCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\OAuthService;
use App\Services\SettingsService;
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

function carrierCreateAction(): TestAction
{
    return TestAction::make('create')->schemaComponent('form-actions', 'content');
}

function seededCarrier(string $name): Carrier
{
    return Carrier::factory()->system()->create(['name' => $name]);
}

/** @return array<string, mixed> */
function newCarrierAccount(Carrier $carrier, array $overrides = []): array
{
    return ['carrier_id' => $carrier->id, 'name' => "{$carrier->name} Account", ...$overrides];
}

function editPageFor(string $name): string
{
    return CarrierAccountResource::getUrl('edit', ['record' => CarrierAccount::where('name', $name)->firstOrFail()]);
}

dataset('oauth carriers', [
    'USPS' => [Carrier::USPS, 'usps'],
    'UPS' => [Carrier::UPS, 'ups'],
]);

it('sends a new account into OAuth', function (string $carrierName, string $provider): void {
    $carrier = seededCarrier($carrierName);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->assertSee("After you create this account you will be sent to {$carrierName} to authorize it, and brought back here.")
        ->assertActionHasLabel(carrierCreateAction(), "Create & connect {$carrierName}")
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirectContains("https://connect.polybag.app/oauth/{$provider}/authorize?");

    $account = CarrierAccount::where('name', "{$carrierName} Account")->firstOrFail();

    expect(session("oauth_account_id.{$provider}"))->toBe($account->id);
})->with('oauth carriers');

it('goes to the edit page when Dev enters the account\'s own client credentials', function (string $carrierName, string $provider): void {
    $carrier = seededCarrier($carrierName);
    $field = strtolower($carrierName);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier, [
            "{$field}_adv_client_id" => 'own-client-id',
            "{$field}_adv_client_secret" => 'own-client-secret',
        ]))
        ->assertSee('This account will sign in with the client ID and secret entered under Advanced / API App Credentials.')
        ->assertActionHasLabel(carrierCreateAction(), 'Create')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(editPageFor("{$carrierName} Account"));

    expect(session("oauth_account_id.{$provider}"))->toBeNull();
})->with('oauth carriers');

it('goes to the edit page without the broker', function (string $carrierName, string $provider): void {
    config(['services.oauth.broker_url' => null]);
    $carrier = seededCarrier($carrierName);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->assertSee('Guided OAuth needs the hosted OAuth broker, which is not configured.')
        ->assertActionHasLabel(carrierCreateAction(), 'Create')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(editPageFor("{$carrierName} Account"));

    expect(session("oauth_account_id.{$provider}"))->toBeNull();
})->with('oauth carriers');

it('never sends a new account into OAuth in Demo, which forces sandbox', function (string $carrierName): void {
    // Livewire's test hooks run only in the testing environment, so Demo is
    // checked at the rule the page asks; Dev with sandbox on covers the page.
    $this->app['env'] = 'demo';

    expect(app(OAuthService::class)->providerForNewCarrierAccount($carrierName, null))->toBeNull();
})->with([Carrier::USPS, Carrier::UPS]);

it('goes to the edit page in Dev with sandbox on, and says why', function (string $carrierName): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();
    $carrier = seededCarrier($carrierName);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->assertSee('The hosted OAuth connection issues production credentials only')
        ->assertActionHasLabel(carrierCreateAction(), 'Create')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(editPageFor("{$carrierName} Account"));
})->with([Carrier::USPS, Carrier::UPS]);

it('opens the Connect FedEx Account wizard on a new FedEx account', function (): void {
    $fedex = seededCarrier(Carrier::FEDEX);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($fedex))
        ->assertSee('After you create this account the Connect FedEx Account wizard opens')
        ->assertActionHasLabel(carrierCreateAction(), 'Create & connect FedEx')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(editPageFor('FedEx Account').'?action=fedex_register');

    $account = CarrierAccount::where('name', 'FedEx Account')->firstOrFail();

    // The page mounts the URL's action from the browser, through wire:init.
    Livewire::withQueryParams(['action' => 'fedex_register'])
        ->test(EditCarrierAccount::class, ['record' => $account->id])
        ->assertSet('defaultAction', 'fedex_register')
        ->call('mountAction', 'fedex_register')
        ->assertActionMounted('fedex_register')
        // Closing it drops the action from the URL, so a reload does not reopen it.
        ->call('closeFedexRegistrationModal')
        ->assertSet('defaultAction', []);
});

it('goes to the FedEx account\'s edit page without the broker', function (array $ownCredentials): void {
    config(['services.oauth.broker_url' => null]);
    $fedex = seededCarrier(Carrier::FEDEX);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($fedex, $ownCredentials))
        ->assertDontSee('After you create this account the Connect FedEx Account wizard opens')
        ->assertActionHasLabel(carrierCreateAction(), 'Create')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(editPageFor('FedEx Account'));
})->with([
    'without its own key' => [[]],
    'with its own key' => [['fedex_adv_api_key' => 'own-key', 'fedex_adv_api_secret' => 'own-secret']],
]);

it('keeps the account and lands on its edit page when OAuth cannot start', function (): void {
    $carrier = seededCarrier(Carrier::UPS);
    $this->partialMock(OAuthService::class)
        ->shouldReceive('initiateAuthorization')
        ->andThrow(new RuntimeException('Provider unavailable.'));

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Cannot connect UPS')
        ->assertRedirect(editPageFor('UPS Account'));
});

it('keeps the no-global-scope warning for the edit page the callback returns to', function (): void {
    $carrier = seededCarrier(Carrier::USPS);
    CarrierAccount::factory()->create(['carrier_id' => $carrier->id, 'name' => 'Existing USPS'])
        ->scopes()->create(['location_id' => null, 'client_id' => null, 'rate_shop' => false]);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirectContains('/oauth/usps/authorize?');

    // A redirecting response leaves sent notifications in the session; the next
    // full page, the edit page the callback redirects to, shows them.
    expect(collect(session('filament.notifications'))->pluck('title'))->toContain('No global scope assigned');
});

it('offers no create another', function (): void {
    Livewire::test(CreateCarrierAccount::class)
        ->assertActionExists(carrierCreateAction())
        ->assertActionDoesNotExist(TestAction::make('createAnother')->schemaComponent('form-actions', 'content'));
});

it('returns to the new account when the create page opens again before OAuth finishes', function (): void {
    $carrier = seededCarrier(Carrier::UPS);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->call('create')
        ->assertRedirectContains('/oauth/ups/authorize?');

    // Back from the consent screen reloads the create page.
    Livewire::test(CreateCarrierAccount::class)
        ->assertNotified('UPS Account is not connected to UPS yet.')
        ->assertRedirect(editPageFor('UPS Account'));

    // Once: creating another account afterwards is not bounced.
    Livewire::test(CreateCarrierAccount::class)->assertNoRedirect();
});

it('stays on the create page once the new account is connected', function (): void {
    $carrier = seededCarrier(Carrier::UPS);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->call('create');

    $account = CarrierAccount::where('name', 'UPS Account')->firstOrFail();
    $account->mergeSecret('oauth_token', 'connected-token');
    $account->save();

    Livewire::test(CreateCarrierAccount::class)->assertNoRedirect();
});

it('stays on the create page after the OAuth callback', function (): void {
    $carrier = seededCarrier(Carrier::UPS);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->call('create');

    $this->get('/oauth/ups/receive?error=access_denied');

    Livewire::test(CreateCarrierAccount::class)->assertNoRedirect();
});

it('answers Back with a redirect that leaves the create toasts for the edit page', function (): void {
    $carrier = seededCarrier(Carrier::USPS);
    CarrierAccount::factory()->create(['carrier_id' => $carrier->id, 'name' => 'Existing USPS'])
        ->scopes()->create(['location_id' => null, 'client_id' => null, 'rate_shop' => false]);

    Livewire::test(CreateCarrierAccount::class)
        ->fillForm(newCarrierAccount($carrier))
        ->call('create');

    $this->get(CarrierAccountResource::getUrl('create'))
        ->assertRedirect(editPageFor('USPS Account'));

    expect(collect(session('filament.notifications'))->pluck('title'))
        ->toContain('Created', 'No global scope assigned', 'USPS Account is not connected to USPS yet.');
});
