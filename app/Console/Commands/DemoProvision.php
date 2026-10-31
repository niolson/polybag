<?php

namespace App\Console\Commands;

use App\Enums\AppMode;
use App\Enums\Role;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Database\Seeders\ClientSeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\OperationalDefaultsSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\StoreChannelSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Internal tooling for our sales demo, not a self-host feature
 * (`live-store-demo/04`). Sets up a fresh demo tenant so that the only thing
 * left to do in the call is connect the prospect's store: sandbox carrier
 * accounts from `config/demo.php`, the starter catalog, an Amazon Shipping
 * connection for a prospect with no seller account, and two users who must
 * set up MFA. Safe to run again: what exists is kept, and a password is only
 * ever printed when its user is created.
 */
class DemoProvision extends Command
{
    /**
     * The `settings` key marking the Amazon Shipping connection made from our
     * own sandbox app. Every demo reuses those credentials, so
     * `demo:disconnect` leaves this connection alone.
     */
    public const DEMO_CONNECTION_SETTING = 'demo_provisioned';

    /** What the Amazon Shipping service is listed under. */
    public const AMAZON_SHIPPING_METHOD = 'Standard Ground';

    private const AMAZON_SHIPPING_GROUND_CODE = 'std-us-swa-mfn';

    protected $signature = 'demo:provision
        {--client-name= : Name the demo Client after the prospect}';

    protected $description = '[Internal] Set up a demo tenant: sandbox carrier accounts, starter catalog, Amazon Shipping and MFA-required users';

    /**
     * Whether this connection is the one made from our own sandbox app,
     * rather than one the prospect connected.
     */
    public static function isDemoConnection(DataSource $source): bool
    {
        return (($source->settings ?? [])[self::DEMO_CONNECTION_SETTING] ?? false) === true;
    }

    public function handle(SettingsService $settings): int
    {
        if (AppMode::current() !== AppMode::Demo) {
            $this->error('demo:provision only runs when APP_ENV is demo.');

            return self::FAILURE;
        }

        $this->seedStarterData($settings);
        $this->nameClient();
        $this->provisionCarrierAccounts();
        $this->provisionAmazonShipping();
        $this->provisionUsers();

        return self::SUCCESS;
    }

    private function seedStarterData(SettingsService $settings): void
    {
        $this->callSilent('app:sync-reference-data');

        // SettingsSeeder overwrites its keys, so it runs only on a tenant that
        // has never been set up: a second run keeps what the presenter changed.
        if (! Setting::query()->whereKey('setup_complete')->exists()) {
            $this->callSilent('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);
        }

        foreach ([ClientSeeder::class, LocationSeeder::class, StoreChannelSeeder::class, OperationalDefaultsSeeder::class] as $seeder) {
            $this->callSilent('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $settings->set('setup_complete', true, 'boolean', group: 'system');
        $settings->set('require_mfa', true, 'boolean', group: 'general');

        $this->components->twoColumnDetail('Starter data', 'reference data, client, location, channels, box sizes, shipping methods');
        $this->components->twoColumnDetail('MFA', 'required');
    }

    private function nameClient(): void
    {
        $name = trim((string) $this->option('client-name'));

        if ($name === '') {
            return;
        }

        Client::query()->where('is_default', true)->update(['name' => $name]);
        $this->components->twoColumnDetail('Client', $name);
    }

    private function provisionCarrierAccounts(): void
    {
        $this->provisionCarrierAccount(Carrier::USPS, 'usps',
            credentials: ['crid', 'mid'],
            secrets: ['client_id', 'client_secret'],
            optionalCredentials: ['eps_account'],
        );
        $this->provisionCarrierAccount(Carrier::UPS, 'ups',
            credentials: ['account_number'],
            secrets: ['client_id', 'client_secret'],
        );
        // FedEx keeps sandbox and production keys side by side; Demo is always sandbox.
        $this->provisionCarrierAccount(Carrier::FEDEX, 'fedex',
            credentials: ['account_number' => 'sandbox_account_number'],
            secrets: ['api_key' => 'sandbox_api_key', 'api_secret' => 'sandbox_api_secret'],
        );
    }

    /**
     * @param  array<int|string, string>  $credentials  config key => credentials key, or one key for both
     * @param  array<int|string, string>  $secrets  config key => secret_credentials key, or one key for both
     * @param  list<string>  $optionalCredentials
     */
    private function provisionCarrierAccount(string $carrierName, string $configKey, array $credentials, array $secrets, array $optionalCredentials = []): void
    {
        $values = (array) config("demo.carriers.{$configKey}", []);
        $label = Carrier::labelForName($carrierName);

        $missing = collect([...array_keys(self::keyMap($credentials)), ...array_keys(self::keyMap($secrets))])
            ->filter(fn (string $key): bool => blank($values[$key] ?? null));

        if ($missing->isNotEmpty()) {
            $this->components->warn("{$label}: skipped, no ".$missing->map(fn (string $key): string => 'DEMO_'.strtoupper($configKey.'_'.$key))->implode(', '));

            return;
        }

        $carrier = Carrier::query()->where('name', $carrierName)->firstOrFail();

        $account = CarrierAccount::query()->firstOrNew([
            'carrier_id' => $carrier->id,
            'name' => "{$label} (demo sandbox)",
        ]);
        $account->active = true;

        foreach (self::mapped($credentials, $values) as $key => $value) {
            $account->mergeCredential($key, $value);
        }

        foreach ($optionalCredentials as $key) {
            if (filled($values[$key] ?? null)) {
                $account->mergeCredential($key, (string) $values[$key]);
            }
        }

        foreach (self::mapped($secrets, $values) as $key => $value) {
            $account->mergeSecret($key, $value);
        }

        $account->save();

        $globalHolder = CarrierAccountScope::query()
            ->where('carrier_id', $carrier->id)
            ->whereNull('location_id')
            ->whereNull('client_id')
            ->value('carrier_account_id');

        if ($globalHolder === null) {
            $account->scopes()->create(['location_id' => null, 'client_id' => null, 'rate_shop' => false]);
        } elseif ($globalHolder !== $account->id) {
            $this->components->warn("{$label}: another account is the global default, so the demo account is not assigned anywhere");

            return;
        }

        $this->components->twoColumnDetail($label, 'sandbox account ready');
    }

    /**
     * Amazon Shipping on orders from other channels, on our own sandbox app,
     * for a prospect who has no Amazon seller account to connect.
     */
    private function provisionAmazonShipping(): void
    {
        $values = (array) config('demo.amazon_shipping', []);
        $missing = collect(['client_id', 'client_secret', 'refresh_token', 'marketplace_id'])
            ->filter(fn (string $key): bool => blank($values[$key] ?? null));

        if ($missing->isNotEmpty()) {
            $this->components->warn('Amazon Shipping: skipped, no '.$missing->map(fn (string $key): string => 'DEMO_AMAZON_'.strtoupper($key))->implode(', '));

            return;
        }

        $connection = DataSource::query()
            ->where('source_type', AmazonSource::class)
            ->get()
            ->first(fn (DataSource $source): bool => self::isDemoConnection($source))
            ?? new DataSource(['source_type' => AmazonSource::class]);

        $connection->fill([
            'name' => 'Amazon Shipping (demo sandbox)',
            'active' => true,
            // It exists to sell postage. Our sandbox app has no orders to read,
            // and nothing it ships is told back to a store.
            'import_enabled' => false,
            'global_export' => false,
            // What lets automation buy Amazon Shipping for another channel's
            // order is this flag, the scope, and the method's `direct` row:
            // those rates are direct, and `sells_postage` governs only Buy
            // Shipping for the connection's own Amazon orders. Set so that, if
            // the presenter turns imports on, automation can buy those too.
            'offers_off_amazon_shipping' => true,
            'sells_postage' => true,
            'settings' => array_merge($connection->settings ?? [], [
                // A channel ID, as the connection form stores it.
                'channel_name' => Channel::query()->where('name', StoreChannelSeeder::AMAZON)->value('id'),
                'marketplace_ids' => [$values['marketplace_id']],
                'primary_marketplace_id' => $values['marketplace_id'],
                'auth_mode' => 'manual',
                'export_enabled' => false,
                self::DEMO_CONNECTION_SETTING => true,
            ]),
            'secret_settings' => array_merge($connection->secret_settings ?? [], [
                'client_id' => $values['client_id'],
                'client_secret' => $values['client_secret'],
                'refresh_token' => $values['refresh_token'],
            ]),
        ])->save();

        if (! $connection->ensureDefaultOffAmazonShippingScope()) {
            $this->components->warn('Amazon Shipping: another connection already sells it here, so the demo connection is not assigned anywhere');

            return;
        }

        $method = ShippingMethod::query()->where('name', self::AMAZON_SHIPPING_METHOD)->first();
        $service = CarrierService::query()->where('service_code', self::AMAZON_SHIPPING_GROUND_CODE)->first();

        if (! $method || ! $service) {
            $this->components->warn('Amazon Shipping: no '.self::AMAZON_SHIPPING_METHOD.' method to list Amazon Shipping Ground on');

            return;
        }

        $method->carrierServices()->syncWithoutDetaching([$service->id]);

        $this->components->twoColumnDetail('Amazon Shipping', 'sandbox connection ready, listed on '.self::AMAZON_SHIPPING_METHOD);
    }

    private function provisionUsers(): void
    {
        $created = [];

        foreach (['presenter' => 'Presenter', 'prospect' => 'Prospect'] as $username => $name) {
            $user = User::query()->firstOrNew(['username' => $username]);

            if ($user->exists) {
                $this->components->twoColumnDetail("User {$username}", 'exists, password unchanged');

                continue;
            }

            $password = Str::password(20, symbols: false);

            // Admin: connecting the prospect's store is an Admin's job.
            $user->fill([
                'name' => $name,
                'password' => $password,
                'role' => Role::Admin,
                'active' => true,
            ])->save();

            $created[] = [$username, $password];
        }

        if ($created !== []) {
            $this->newLine();
            $this->line('New sign-ins. Shown once; each must set up MFA on first sign-in.');
            $this->table(['Username', 'Password'], $created);
        }
    }

    /**
     * @param  array<int|string, string>  $keys
     * @return array<string, string>
     */
    private static function keyMap(array $keys): array
    {
        $map = [];

        foreach ($keys as $from => $to) {
            $map[is_int($from) ? $to : $from] = $to;
        }

        return $map;
    }

    /**
     * @param  array<int|string, string>  $keys
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private static function mapped(array $keys, array $values): array
    {
        $mapped = [];

        foreach (self::keyMap($keys) as $from => $to) {
            $mapped[$to] = (string) $values[$from];
        }

        return $mapped;
    }
}
