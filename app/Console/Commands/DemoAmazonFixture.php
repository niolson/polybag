<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\DataSource;
use App\Services\Carriers\FakeAmazonSpApi;
use App\Services\ClientContext;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Database\Seeders\StoreChannelSeeder;
use Illuminate\Console\Command;
use JsonException;

/**
 * Loads made-up Amazon orders into a fixture connection, so a demo or a
 * screenshot can show Amazon's side of the app — Prime badges, Transparency
 * prompts, Buy Shipping offers and their OTDR protection — with no seller
 * account. The orders come from polybag-demo-data-tools
 * (`bin/generate-amazon-orders.php`).
 *
 * The connection is answered by {@see FakeAmazonSpApi}, which needs
 * `FAKE_CARRIERS=true`; it is never sent to Amazon.
 */
class DemoAmazonFixture extends Command
{
    public const CONNECTION_NAME = 'Amazon (demo fixture)';

    private const MARKETPLACE_ID = 'ATVPDKIKX0DER';

    protected $signature = 'demo:amazon-fixture
        {orders : Path to a JSON file holding a list of Orders v2026-01-01 orders}
        {--import : Import the orders once they are loaded}';

    protected $description = '[Internal] Load made-up Amazon orders into a demo fixture connection (needs FAKE_CARRIERS=true)';

    public function handle(): int
    {
        if (! config('app.fake_carriers')) {
            $this->components->error('FAKE_CARRIERS is off. A fixture connection answers only while it is on, so nothing was loaded.');

            return self::FAILURE;
        }

        $orders = $this->readOrders((string) $this->argument('orders'));

        if ($orders === null) {
            return self::FAILURE;
        }

        $connection = DataSource::query()
            ->where('source_type', AmazonSource::class)
            ->get()
            ->first(fn (DataSource $source): bool => FakeAmazonSpApi::isFixture($source->settings ?? []))
            ?? new DataSource(['source_type' => AmazonSource::class]);

        $connection->fill([
            'name' => self::CONNECTION_NAME,
            'client_id' => $connection->client_id ?? app(ClientContext::class)->default()->id,
            'active' => true,
            'import_enabled' => true,
            'global_export' => false,
            // Buy Shipping for its own orders, as a real seller connection sells.
            'sells_postage' => true,
            'offers_off_amazon_shipping' => false,
            'settings' => array_merge($connection->settings ?? [], [
                'channel_name' => Channel::query()->firstOrCreate(['name' => StoreChannelSeeder::AMAZON], ['active' => true])->id,
                'marketplace_ids' => [self::MARKETPLACE_ID],
                'primary_marketplace_id' => self::MARKETPLACE_ID,
                'auth_mode' => 'manual',
                'export_enabled' => false,
                FakeAmazonSpApi::SETTING => true,
            ]),
            // Never sent anywhere: the fixture's connector asks for no token.
            'secret_settings' => ['client_id' => 'fixture', 'client_secret' => 'fixture', 'refresh_token' => 'fixture'],
        ])->save();

        FakeAmazonSpApi::storeOrders($connection, $orders);

        $this->components->twoColumnDetail("Connection {$connection->id}", self::CONNECTION_NAME);
        $this->components->twoColumnDetail('Orders loaded', (string) count($orders));

        if ($this->option('import')) {
            return $this->call('shipments:import', ['--source-id' => $connection->id]);
        }

        $this->components->info("Import them with: php artisan shipments:import --source-id={$connection->id}");

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function readOrders(string $path): ?array
    {
        if (! is_file($path)) {
            $this->components->error("No file at {$path}.");

            return null;
        }

        try {
            $orders = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->components->error("{$path} is not valid JSON: {$e->getMessage()}");

            return null;
        }

        if (! is_array($orders) || ! array_is_list($orders) || collect($orders)->contains(fn (mixed $order): bool => ! is_array($order) || blank($order['orderId'] ?? null))) {
            $this->components->error("{$path} must hold a list of orders, each with an orderId.");

            return null;
        }

        return $orders;
    }
}
