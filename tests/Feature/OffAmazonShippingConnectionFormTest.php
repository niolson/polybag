<?php

use App\Enums\OffAmazonShippingStatus;
use App\Filament\Resources\CarrierAccounts\Pages\CreateCarrierAccount;
use App\Filament\Resources\DataSources\Pages\CreateDataSource;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `amazon-shipping-external-orders/04`: the opt-in, its assignments and the
 * account check on an Amazon connection's form, and the Location form leaving
 * connection rows alone.
 */
beforeEach(function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
    Cache::put('amazon_sp_api_access_token_'.md5('form-refresh-token'), 'form-access-token', 3600);
    Location::factory()->create(['is_default' => true]);

    $this->actingAs(User::factory()->admin()->create());
});

function amazonConnection(array $attributes = [], bool $unassigned = true): DataSource
{
    $factory = DataSource::factory()->amazon()->importDisabled();

    return ($unassigned ? $factory->unassigned() : $factory)->create(array_merge([
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => ['refresh_token' => 'form-refresh-token'],
    ], $attributes));
}

function fakeAmazonAnswer(MockResponse|Closure $response): void
{
    Saloon::fake([GetShippingRates::class => $response]);
}

describe('turning the opt-in on', function (): void {
    it('saves, gives an unassigned connection the global row and records the check', function (): void {
        fakeAmazonAnswer(MockResponse::make(['payload' => ['rates' => []]], 200));
        $connection = amazonConnection();

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['offers_off_amazon_shipping' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Amazon Shipping is enabled');

        $connection->refresh();
        $scope = $connection->offAmazonShippingScopes()->sole();

        expect($connection->offers_off_amazon_shipping)->toBeTrue()
            ->and($connection->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Enabled)
            ->and($connection->off_amazon_shipping_checked_at)->not->toBeNull()
            ->and($scope->location_id)->toBeNull()
            ->and($scope->client_id)->toBeNull();
    });

    it('gives a client-assigned connection a row for its own client', function (): void {
        fakeAmazonAnswer(MockResponse::make(['payload' => ['rates' => []]], 200));
        $client = Client::factory()->create();
        $connection = amazonConnection(['client_id' => $client->id], unassigned: false);

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['offers_off_amazon_shipping' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $scope = $connection->offAmazonShippingScopes()->sole();

        expect($scope->location_id)->toBeNull()
            ->and($scope->client_id)->toBe($client->id);
    });

    it('saves the opt-in and warns when the default slot is taken', function (): void {
        fakeAmazonAnswer(MockResponse::make(['payload' => ['rates' => []]], 200));
        $holder = amazonConnection(['offers_off_amazon_shipping' => true]);
        CarrierAccountScope::create(['data_source_id' => $holder->id]);
        $connection = amazonConnection();

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['offers_off_amazon_shipping' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('No assignment for Amazon Shipping');

        expect($connection->refresh()->offers_off_amazon_shipping)->toBeTrue()
            ->and($connection->offAmazonShippingScopes()->count())->toBe(0);
    });

    it('saves the opt-in and warns about sign-up when Amazon answers A-101', function (): void {
        fakeAmazonAnswer(MockResponse::make(['errors' => [[
            'code' => 'Unauthorized',
            'message' => 'Access to requested resource is denied.',
            'details' => 'Access denied for this account. Please contact support. (A-101)',
        ]]], 403));
        $connection = amazonConnection();

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['offers_off_amazon_shipping' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Amazon Shipping is not set up on this account');

        expect($connection->refresh()->offers_off_amazon_shipping)->toBeTrue()
            ->and($connection->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::NotSetUp);
    });

    it('still saves when Amazon times out', function (): void {
        fakeAmazonAnswer(fn (PendingRequest $pending): MockResponse => MockResponse::make()
            ->throw(new FatalRequestException(new RuntimeException('Connection timed out'), $pending)));
        $connection = amazonConnection();

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['offers_off_amazon_shipping' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Amazon Shipping account not checked');

        expect($connection->refresh()->offers_off_amazon_shipping)->toBeTrue()
            ->and($connection->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Unknown);
    });

    it('checks only when the opt-in goes from off to on', function (): void {
        fakeAmazonAnswer(MockResponse::make(['payload' => ['rates' => []]], 200));
        $connection = amazonConnection(['offers_off_amazon_shipping' => true]);
        CarrierAccountScope::create(['data_source_id' => $connection->id]);

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        Saloon::assertNothingSent();
    });

    it('checks when an Amazon connection is created with the opt-in on', function (): void {
        fakeAmazonAnswer(MockResponse::make(['payload' => ['rates' => []]], 200));

        Livewire::test(CreateDataSource::class)
            ->fillForm([
                'name' => 'Amazon Shipping only',
                'source_type' => AmazonSource::class,
                'active' => true,
                'import_enabled' => false,
                'offers_off_amazon_shipping' => true,
                'settings.marketplace_id' => 'ATVPDKIKX0DER',
                'settings.refresh_token' => 'form-refresh-token',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Amazon Shipping is enabled');

        $connection = DataSource::where('name', 'Amazon Shipping only')->sole();

        expect($connection->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Enabled)
            ->and($connection->offAmazonShippingScopes()->count())->toBe(1);
    });
});

it('checks again from the header action', function (): void {
    fakeAmazonAnswer(MockResponse::make(['payload' => ['rates' => []]], 200));
    $connection = amazonConnection([
        'offers_off_amazon_shipping' => true,
        'off_amazon_shipping_status' => OffAmazonShippingStatus::NotSetUp,
    ]);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->assertActionVisible('check_off_amazon_shipping')
        ->callAction('check_off_amazon_shipping')
        ->assertNotified('Amazon Shipping is enabled');

    expect($connection->refresh()->off_amazon_shipping_status)->toBe(OffAmazonShippingStatus::Enabled);
});

it('hides the check action while the opt-in is off', function (): void {
    Livewire::test(EditDataSource::class, ['record' => amazonConnection()->id])
        ->assertActionHidden('check_off_amazon_shipping');
});

it('shows the check result on the form', function (): void {
    $connection = amazonConnection([
        'offers_off_amazon_shipping' => true,
        'off_amazon_shipping_status' => OffAmazonShippingStatus::NotSetUp,
        'off_amazon_shipping_checked_at' => now(),
    ]);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->assertSee('Not set up')
        ->assertSee('Seller Central');
});

describe('assignments', function (): void {
    beforeEach(function (): void {
        app(SettingsService::class)->set('multi_location_enabled', true, 'boolean');
        app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
        $this->undoRepeaterFake = Repeater::fake();
    });

    afterEach(function (): void {
        ($this->undoRepeaterFake)();
    });

    it('loads and replaces a connection\'s rows', function (): void {
        $connection = amazonConnection(['offers_off_amazon_shipping' => true]);
        CarrierAccountScope::create(['data_source_id' => $connection->id]);
        $location = Location::factory()->create();
        $client = Client::factory()->create();

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->assertFormSet(['off_amazon_shipping_scopes' => [['location_id' => null, 'client_id' => null]]])
            ->fillForm(['off_amazon_shipping_scopes' => [
                ['location_id' => $location->id, 'client_id' => null],
                ['location_id' => null, 'client_id' => $client->id],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $slots = $connection->offAmazonShippingScopes()->orderBy('id')->get(['location_id', 'client_id'])->toArray();

        expect($slots)->toBe([
            ['location_id' => $location->id, 'client_id' => null],
            ['location_id' => null, 'client_id' => $client->id],
        ]);
    });

    it('scopes every row of a client-assigned connection to its client', function (): void {
        $client = Client::factory()->create();
        $connection = amazonConnection(['client_id' => $client->id, 'offers_off_amazon_shipping' => true], unassigned: false);
        $location = Location::factory()->create();

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['off_amazon_shipping_scopes' => [['location_id' => $location->id, 'client_id' => null]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $scope = $connection->offAmazonShippingScopes()->sole();

        expect($scope->location_id)->toBe($location->id)
            ->and($scope->client_id)->toBe($client->id);
    });

    it('refuses a slot another connection holds', function (): void {
        $holder = amazonConnection(['offers_off_amazon_shipping' => true, 'name' => 'Warehouse account']);
        CarrierAccountScope::create(['data_source_id' => $holder->id]);
        $connection = amazonConnection(['offers_off_amazon_shipping' => true]);

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['off_amazon_shipping_scopes' => [['location_id' => null, 'client_id' => null]]])
            ->call('save')
            ->assertHasFormErrors(['off_amazon_shipping_scopes']);

        expect($connection->offAmazonShippingScopes()->count())->toBe(0);
    });

    it('refuses the same slot twice', function (): void {
        $connection = amazonConnection(['offers_off_amazon_shipping' => true]);
        $location = Location::factory()->create();

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['off_amazon_shipping_scopes' => [
                ['location_id' => $location->id, 'client_id' => null],
                ['location_id' => $location->id, 'client_id' => null],
            ]])
            ->call('save')
            ->assertHasFormErrors(['off_amazon_shipping_scopes']);
    });

    it('keeps the rows of a connection that is opted out', function (): void {
        $connection = amazonConnection(['offers_off_amazon_shipping' => true]);
        CarrierAccountScope::create(['data_source_id' => $connection->id]);

        Livewire::test(EditDataSource::class, ['record' => $connection->id])
            ->fillForm(['offers_off_amazon_shipping' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($connection->refresh()->offers_off_amazon_shipping)->toBeFalse()
            ->and($connection->offAmazonShippingScopes()->count())->toBe(1);
    });
});

it('keeps a connection row when a Location is saved', function (): void {
    app(SettingsService::class)->set('multi_location_enabled', true, 'boolean');
    $location = Location::factory()->create();
    $connection = amazonConnection(['offers_off_amazon_shipping' => true]);
    $connectionScope = CarrierAccountScope::create(['data_source_id' => $connection->id, 'location_id' => $location->id]);
    $account = CarrierAccount::factory()->create();
    $accountScope = CarrierAccountScope::create(['carrier_account_id' => $account->id, 'location_id' => $location->id]);

    Livewire::test(EditLocation::class, ['record' => $location->id])
        ->assertFormSet(function (array $state): array {
            expect($state['carrierAccountScopes'])->toHaveCount(1);

            return [];
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect(CarrierAccountScope::whereKey([$connectionScope->id, $accountScope->id])->count())->toBe(2);
});

describe('a connection shared across clients', function (): void {
    it('keeps a blank client on create and gets the global assignment', function (): void {
        app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
        fakeAmazonAnswer(MockResponse::make(['payload' => ['rates' => []]], 200));

        Livewire::test(CreateDataSource::class)
            ->fillForm([
                'name' => 'Warehouse Amazon Shipping',
                'client_id' => null,
                'source_type' => AmazonSource::class,
                'active' => true,
                'import_enabled' => false,
                'offers_off_amazon_shipping' => true,
                'settings.marketplace_id' => 'ATVPDKIKX0DER',
                'settings.refresh_token' => 'form-refresh-token',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $connection = DataSource::where('name', 'Warehouse Amazon Shipping')->sole();
        $scope = $connection->offAmazonShippingScopes()->sole();

        expect($connection->client_id)->toBeNull()
            ->and($scope->location_id)->toBeNull()
            ->and($scope->client_id)->toBeNull();
    });

    it('still gives a new connection the default client in single-client mode', function (): void {
        fakeAmazonAnswer(MockResponse::make(['payload' => ['rates' => []]], 200));

        Livewire::test(CreateDataSource::class)
            ->fillForm([
                'name' => 'Single-client Amazon',
                'source_type' => AmazonSource::class,
                'active' => true,
                'import_enabled' => false,
                'offers_off_amazon_shipping' => true,
                'settings.marketplace_id' => 'ATVPDKIKX0DER',
                'settings.refresh_token' => 'form-refresh-token',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $connection = DataSource::where('name', 'Single-client Amazon')->sole();
        $defaultClientId = Client::where('is_default', true)->value('id');

        expect($connection->client_id)->toBe($defaultClientId)
            ->and($connection->offAmazonShippingScopes()->sole()->client_id)->toBe($defaultClientId);
    });
});

describe('direct accounts on the Amazon row', function (): void {
    it('are not offered on the carrier account create form', function (): void {
        $amazon = Carrier::firstOrCreate(['name' => AmazonBuyShippingAdapter::SOURCE_NAME], ['active' => true]);
        $usps = Carrier::firstOrCreate(['name' => 'USPS'], ['active' => true]);
        $amazon->update(['active' => true]);
        $usps->update(['active' => true]);

        Livewire::test(CreateCarrierAccount::class)
            ->assertFormFieldExists('carrier_id', function (Select $field) use ($amazon, $usps): bool {
                $options = $field->getOptions();

                return ! array_key_exists($amazon->id, $options) && array_key_exists($usps->id, $options);
            });
    });

    it('have their scopes removed by the migration', function (): void {
        $amazon = Carrier::firstOrCreate(['name' => AmazonBuyShippingAdapter::SOURCE_NAME]);
        // A legacy row: the model now refuses an account on the Amazon carrier.
        $account = CarrierAccount::withoutEvents(fn () => CarrierAccount::factory()->create(['carrier_id' => $amazon->id]));
        $legacyId = DB::table('carrier_account_scopes')->insertGetId([
            'carrier_account_id' => $account->id,
            'carrier_id' => $amazon->id,
            'rate_shop' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kept = CarrierAccountScope::factory()->create();

        $migration = require database_path('migrations/2026_09_22_214300_add_off_amazon_shipping_to_data_sources_and_scopes.php');
        (fn () => $this->deleteCarrierAccountScopesOnAmazon())->call($migration);

        expect(DB::table('carrier_account_scopes')->where('id', $legacyId)->exists())->toBeFalse()
            ->and($kept->fresh())->not->toBeNull()
            ->and($account->fresh())->not->toBeNull();
    });
});
