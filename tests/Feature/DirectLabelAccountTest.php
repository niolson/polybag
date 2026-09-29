<?php

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Http\Integrations\Fedex\Requests\CancelShipment;
use App\Http\Integrations\Fedex\Requests\TrackShipment as FedexTrackShipment;
use App\Http\Integrations\Ups\Requests\TrackShipment as UpsTrackShipment;
use App\Http\Integrations\Ups\Requests\VoidShipment;
use App\Http\Integrations\USPS\Requests\CancelLabel;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Http\Integrations\USPS\Requests\TrackShipment as UspsTrackShipment;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Services\Carriers\CarrierRegistry;
use App\Services\PostageSources\PostageSourceDispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * A direct Label is voided and tracked on the account that bought it, not on
 * whichever account the scopes resolve to today — `project-review/06`.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    Cache::flush();
    CarrierAccount::query()->delete();

    $this->client = Client::factory()->create();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * An account routed by a scope — globally, or to one client.
 *
 * @param  array<string, mixed>  $credentials
 * @param  array<string, mixed>  $secrets
 */
function scopedAccount(string $carrier, array $credentials, array $secrets, ?Client $client = null): CarrierAccount
{
    $account = CarrierAccount::factory()->create([
        'carrier_id' => Carrier::firstOrCreate(['name' => $carrier])->id,
        'credentials' => $credentials,
        'secret_credentials' => $secrets,
    ]);

    $scope = CarrierAccountScope::factory()->forAccount($account);
    ($client ? $scope->clientScoped($client) : $scope->global())->create();

    return $account;
}

function directLabel(string $carrier, Client $client, ?CarrierAccount $boughtOn, string $trackingNumber): Package
{
    return Package::factory()->shipped()->for(Shipment::factory()->create(['client_id' => $client->id, 'country' => 'US']))->create([
        'carrier' => $carrier,
        'tracking_number' => $trackingNumber,
        'postage_source' => PostageSource::CarrierAccount,
        'carrier_account_id' => $boughtOn?->id,
    ]);
}

function fedexAccount(string $accountNumber, string $apiKey, ?Client $client = null): CarrierAccount
{
    return scopedAccount(Carrier::FEDEX, ['account_number' => $accountNumber], ['api_key' => $apiKey, 'api_secret' => 's'], $client);
}

function uspsAccount(string $crid, string $clientId, ?Client $client = null): CarrierAccount
{
    return scopedAccount(Carrier::USPS, ['crid' => $crid, 'mid' => "{$crid}_mid"], ['client_id' => $clientId, 'client_secret' => 's'], $client);
}

/**
 * Fakes for FedEx that record the account number each cancel names.
 *
 * @param  array<int, string|null>  $sent
 */
function fedexCancelFakes(array &$sent): array
{
    return [
        '*oauth*' => MockResponse::make(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CancelShipment::class => function (PendingRequest $pending) use (&$sent): MockResponse {
            $sent[] = data_get($pending->body()->all(), 'accountNumber.value');

            return MockResponse::make(['output' => ['cancelledShipment' => true]]);
        },
    ];
}

it('voids a FedEx label on the account that bought it after a client account is added', function (): void {
    $sent = [];
    Saloon::fake(fedexCancelFakes($sent));

    $globalAccount = fedexAccount('global_account', 'k1');
    $package = directLabel(Carrier::FEDEX, $this->client, $globalAccount, '794600000001');

    // Later that day the client gets its own FedEx account.
    fedexAccount('client_account', 'k2', $this->client);

    $response = app(PostageSourceDispatcher::class)->voidLabel($package);

    expect($response->success)->toBeTrue()
        ->and($sent)->toBe(['global_account']);
});

it('voids a USPS label with the payment token of the account that bought it', function (): void {
    $crids = [];
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => function (PendingRequest $pending) use (&$crids): MockResponse {
            $crids[] = data_get($pending->body()->all(), 'roles.0.CRID');

            return MockResponse::make(['paymentAuthorizationToken' => 'token']);
        },
        CancelLabel::class => MockResponse::make([], 200),
    ]);

    $globalAccount = uspsAccount('global_crid', 'global_client');
    $package = directLabel(Carrier::USPS, $this->client, $globalAccount, '9400111899223456789012');
    uspsAccount('client_crid', 'client_client', $this->client);

    $response = app(PostageSourceDispatcher::class)->voidLabel($package);

    expect($response->success)->toBeTrue()
        ->and($crids)->toBe(['global_crid']);
});

it('tracks a USPS label on the account that bought it', function (): void {
    $clientIds = [];
    Saloon::fake([
        '*oauth*' => function (PendingRequest $pending) use (&$clientIds): MockResponse {
            $clientIds[] = data_get($pending->body()->all(), 'client_id');

            return MockResponse::make(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        },
        UspsTrackShipment::class => MockResponse::make(['trackingNumber' => '9400111899223456789012', 'statusCategory' => 'In Transit', 'trackingEvents' => []]),
    ]);

    $globalAccount = uspsAccount('global_crid', 'global_client');
    $package = directLabel(Carrier::USPS, $this->client, $globalAccount, '9400111899223456789012');
    uspsAccount('client_crid', 'client_client', $this->client);

    app(PostageSourceDispatcher::class)->trackShipment($package);

    expect($clientIds)->toBe(['global_client']);
});

it('tracks a FedEx label on the account that bought it', function (): void {
    config(['services.oauth.broker_url' => null]);
    $apiKeys = [];
    Saloon::fake([
        '*oauth*' => function (PendingRequest $pending) use (&$apiKeys): MockResponse {
            $apiKeys[] = data_get($pending->body()->all(), 'client_id');

            return MockResponse::make(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        },
        FedexTrackShipment::class => MockResponse::make(['output' => ['completeTrackResults' => [['trackResults' => [[]]]]]]),
    ]);

    $globalAccount = fedexAccount('global_account', 'k1');
    $package = directLabel(Carrier::FEDEX, $this->client, $globalAccount, '794600000001');
    fedexAccount('client_account', 'k2', $this->client);

    app(PostageSourceDispatcher::class)->trackShipment($package);

    expect($apiKeys)->toBe(['k1']);
});

function upsAccount(string $accountNumber, string $clientId, ?Client $client = null): CarrierAccount
{
    return scopedAccount(Carrier::UPS, ['account_number' => $accountNumber], ['client_id' => $clientId, 'client_secret' => 's'], $client);
}

/**
 * The client id each OAuth token request authenticated as, from its body or
 * its Basic credentials — which account a request was sent on.
 *
 * @param  array<int, string|null>  $clientIds
 */
function recordingOauth(array &$clientIds): Closure
{
    return function (PendingRequest $pending) use (&$clientIds): MockResponse {
        $basic = (string) $pending->headers()->get('Authorization');
        $clientIds[] = data_get($pending->body()?->all() ?? [], 'client_id')
            ?? (str_starts_with($basic, 'Basic ') ? strtok((string) base64_decode(substr($basic, 6)), ':') : null);

        return MockResponse::make(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600]);
    };
}

it('voids and tracks a UPS label on the account that bought it', function (): void {
    $clientIds = [];
    Saloon::fake([
        '*oauth*' => recordingOauth($clientIds),
        VoidShipment::class => MockResponse::make(['VoidShipmentResponse' => ['SummaryResult' => ['Status' => ['Description' => 'Voided']]]]),
        UpsTrackShipment::class => MockResponse::make(['trackResponse' => ['shipment' => [['package' => [[]]]]]]),
    ]);

    $globalAccount = upsAccount('GLOBAL', 'global_client');
    $package = directLabel(Carrier::UPS, $this->client, $globalAccount, '1ZGLOBAL0000000001');
    upsAccount('CLIENT', 'client_client', $this->client);

    app(PostageSourceDispatcher::class)->trackShipment($package);
    $void = app(PostageSourceDispatcher::class)->voidLabel($package);

    expect($void->success)->toBeTrue()
        ->and(array_unique($clientIds))->toBe(['global_client']);
});

it('voids on the recorded account after its billing identity is edited, and logs it', function (): void {
    $sent = [];
    Saloon::fake(fedexCancelFakes($sent));
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$warnings): void {
        if ($logged->level === 'warning') {
            $warnings[] = $logged->message;
        }
    });

    $globalAccount = fedexAccount('global_account', 'k1');
    $package = directLabel(Carrier::FEDEX, $this->client, $globalAccount, '794600000001');
    $globalAccount->update(['credentials' => ['account_number' => 'edited_account']]);

    $response = app(PostageSourceDispatcher::class)->voidLabel($package);

    // Logged, not refused: refusing would leave a live label nobody can void from here.
    expect($response->success)->toBeTrue()
        ->and($sent)->toBe(['edited_account'])
        ->and(collect($warnings)->filter(fn (string $message): bool => str_contains($message, 'bills as someone else now')))->toHaveCount(1);
});

it('voids on the account that bought the label after that account is deactivated', function (): void {
    $sent = [];
    Saloon::fake(fedexCancelFakes($sent));

    $globalAccount = fedexAccount('global_account', 'k1');
    $package = directLabel(Carrier::FEDEX, $this->client, $globalAccount, '794600000001');

    // The label it bought exists either way; a deactivated account can still void it.
    $globalAccount->update(['active' => false]);
    fedexAccount('client_account', 'k2', $this->client);

    $response = app(PostageSourceDispatcher::class)->voidLabel($package->fresh());

    expect($response->success)->toBeTrue()
        ->and($sent)->toBe(['global_account']);
});

it('refuses to void a label whose account has been deleted rather than asking another', function (): void {
    $sent = [];
    Saloon::fake(fedexCancelFakes($sent));

    $globalAccount = fedexAccount('global_account', 'k1');
    $package = directLabel(Carrier::FEDEX, $this->client, $globalAccount, '794600000001');
    fedexAccount('client_account', 'k2', $this->client);

    $globalAccount->delete();
    $package->refresh();

    expect($package->carrier_account_id)->toBeNull();

    $response = app(PostageSourceDispatcher::class)->voidLabel($package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toContain('carrier account that bought this label has been deleted')
        ->and($sent)->toBe([]);
});

it('refuses to track a label whose account has been deleted', function (): void {
    config(['services.oauth.broker_url' => null]);
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        FedexTrackShipment::class => MockResponse::make(['output' => ['completeTrackResults' => [['trackResults' => [[]]]]]]),
    ]);

    $globalAccount = fedexAccount('global_account', 'k1');
    $package = directLabel(Carrier::FEDEX, $this->client, $globalAccount, '794600000001');
    fedexAccount('client_account', 'k2', $this->client);
    $globalAccount->delete();

    $response = app(PostageSourceDispatcher::class)->trackShipment($package->refresh());

    expect($response->success)->toBeFalse()
        ->and($response->message)->toContain('has been deleted');

    Saloon::assertNotSent(FedexTrackShipment::class);
});

it('resolves through scopes for a label that never recorded an account', function (): void {
    $sent = [];
    Saloon::fake(fedexCancelFakes($sent));

    fedexAccount('global_account', 'k1');
    $package = directLabel(Carrier::FEDEX, $this->client, null, '794600000001');

    $response = app(PostageSourceDispatcher::class)->voidLabel($package);

    expect($response->success)->toBeTrue()
        ->and($sent)->toBe(['global_account']);
});

it('records the buying account fingerprint on the Label when a package ships', function (): void {
    $account = fedexAccount('global_account', 'k1');
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);

    $package->markShipped(ShipResponse::success(
        trackingNumber: '794600000009',
        cost: 12.75,
        carrier: Carrier::FEDEX,
        service: 'FedEx Ground',
        labelData: 'x',
        carrierAccountId: $account->id,
    ), PostageSource::CarrierAccount);

    expect(PackageLabel::query()->where('package_id', $package->id)->value('carrier_account_fingerprint'))
        ->toBe($account->fingerprint());
});

it('backfills existing Labels with the same fingerprint the model computes', function (): void {
    $account = fedexAccount('global_account', 'k1');
    $package = directLabel(Carrier::FEDEX, $this->client, $account, '794600000001');
    $migration = require database_path('migrations/2026_09_29_004045_add_carrier_account_fingerprint_to_package_labels_table.php');

    $migration->down();
    $migration->up();

    expect(PackageLabel::query()->where('package_id', $package->id)->value('carrier_account_fingerprint'))
        ->toBe($account->fingerprint());
});
