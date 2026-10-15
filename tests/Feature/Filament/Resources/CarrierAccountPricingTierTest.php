<?php

use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\User;
use App\Services\Carriers\UspsAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $this->account = CarrierAccount::factory()->usps()->create([
        'carrier_id' => Carrier::firstOrCreate(['name' => 'USPS'])->id,
        'secret_credentials' => ['client_id' => 'test_client_id', 'client_secret' => 'test_client_secret'],
    ]);
    CarrierAccountScope::create([
        'carrier_account_id' => $this->account->id,
        'location_id' => null,
        'client_id' => null,
        'rate_shop' => false,
    ]);
});

it('reports CONTRACT pricing when the account has negotiated rates', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['pricingOptions' => [[
            'shippingOptions' => [['rateOptions' => [['rates' => [['priceType' => 'CONTRACT']]]]]],
        ]]]),
    ]);

    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
        ->callAction('usps_test_connection')
        ->assertNotified('USPS Connected — Contract Pricing');
});

it('probes with the unsaved EPS account from the form and does not cache the result', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['error' => 'forbidden'], 403),
    ]);

    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
        ->set('data.credentials.eps_account', 'UNSAVED123')
        ->callAction('usps_test_connection')
        ->assertNotified('USPS Connected — Retail Pricing');

    Saloon::assertSent(fn ($request): bool => $request instanceof ShippingOptions
        && ($request->body()->all()['pricingOptions'][0]['paymentAccount']['accountNumber'] ?? null) === 'UNSAVED123');
    expect(app(UspsAdapter::class)->cachedPricingType($this->account))->toBeNull();
});

it('reports RETAIL pricing when the account lacks contract access', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['error' => 'forbidden'], 403),
    ]);

    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
        ->callAction('usps_test_connection')
        ->assertNotified('USPS Connected — Retail Pricing');
});

it('reports a failure when authentication is rejected', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['error' => 'invalid_client'], 401),
    ]);

    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
        ->callAction('usps_test_connection')
        ->assertNotified('USPS connection failed');
});
