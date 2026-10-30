<?php

namespace App\Console\Commands;

use App\Enums\Deliverability;
use App\Enums\Role;
use App\Models\Channel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\ClientContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the packers and orders for `scripts/load-test/packers.mjs`
 * (`capacity-audit/01`), and prints the plan the driver reads.
 *
 * Built without factories, because the image ships without Faker. Refuses to
 * run unless the carriers are fake: a load test against real carriers would
 * buy real postage.
 */
class SeedLoadTest extends Command
{
    protected $signature = 'app:seed-load-test
                            {--packers=20 : How many packers to create}
                            {--shipments=50 : Open Shipments for each packer}
                            {--items=2 : Products on each Shipment}
                            {--password=loadtest-password : Password for every load-test user}
                            {--method=Standard Ground : Shipping method for the Shipments}';

    protected $description = 'Seed load-test packers and Shipments, and print the plan as JSON';

    public const string USERNAME_PREFIX = 'loadtest-';

    public const string REFERENCE_PREFIX = 'LT';

    public function handle(ClientContext $clients): int
    {
        if (! config('app.fake_carriers')) {
            $this->error('Refusing to seed a load test: FAKE_CARRIERS is off, so the test would buy real postage.');

            return self::FAILURE;
        }

        $packers = max(1, (int) $this->option('packers'));
        $shipmentsEach = max(1, (int) $this->option('shipments'));
        $itemsEach = max(1, (int) $this->option('items'));

        $method = ShippingMethod::where('name', $this->option('method'))->first();

        if (! $method) {
            $this->error("No shipping method is named '{$this->option('method')}'.");

            return self::FAILURE;
        }

        $client = $clients->default();
        $channel = Channel::firstOrCreate(['name' => 'Load Test'], ['active' => true]);
        $products = $this->products($client->id, max($itemsEach, 10));

        // A run tag keeps each run's references apart, so a run never meets a
        // Shipment an earlier one shipped.
        $run = strtoupper(Str::random(4));
        $plan = ['run' => $run, 'password' => $this->option('password'), 'packers' => []];

        DB::transaction(function () use (&$plan, $packers, $shipmentsEach, $itemsEach, $method, $client, $channel, $products, $run): void {
            for ($p = 1; $p <= $packers; $p++) {
                $username = self::USERNAME_PREFIX.$p;

                $user = User::firstOrNew(['username' => $username]);
                $user->fill([
                    'name' => "Load Test Packer {$p}",
                    'password' => $this->option('password'),
                    'role' => Role::Packer,
                    'auto_ship_enabled' => false,
                    'active' => true,
                ])->save();

                $references = [];

                for ($s = 1; $s <= $shipmentsEach; $s++) {
                    $reference = sprintf('%s%s-%02d-%04d', self::REFERENCE_PREFIX, $run, $p, $s);
                    $this->shipment($reference, $client->id, $method->id, $channel->id)
                        ->shipmentItems()
                        ->createMany(collect($products)->shuffle()->take($itemsEach)->map(fn (Product $product): array => [
                            'product_id' => $product->id,
                            'quantity' => random_int(1, 2),
                            'value' => 19.99,
                        ])->all());

                    $references[] = $reference;
                }

                $plan['packers'][] = ['username' => $username, 'shipments' => $references];
            }
        });

        $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @return list<Product>
     */
    private function products(int $clientId, int $count): array
    {
        $products = [];

        for ($i = 1; $i <= $count; $i++) {
            $sku = sprintf('LOADTEST-%02d', $i);

            $products[] = Product::firstOrCreate(
                ['client_id' => $clientId, 'sku' => $sku],
                [
                    'name' => "Load Test Product {$i}",
                    'barcode' => sprintf('LT%010d', $i),
                    'weight' => 0.5,
                    'country_of_origin' => 'US',
                    'active' => true,
                ],
            );
        }

        return $products;
    }

    private function shipment(string $reference, int $clientId, int $methodId, int $channelId): Shipment
    {
        $address = [
            'address1' => '1600 Pennsylvania Avenue NW',
            'city' => 'Washington',
            'state_or_province' => 'DC',
            'postal_code' => '20500',
            'country' => 'US',
        ];

        return Shipment::create([
            ...$address,
            'client_id' => $clientId,
            'location_id' => Location::getDefault()?->id,
            'shipment_reference' => $reference,
            'first_name' => 'Load',
            'last_name' => 'Test',
            'residential' => false,
            'value' => 39.98,
            'shipping_method_id' => $methodId,
            'channel_id' => $channelId,
            'status' => 'open',
            'checked' => true,
            'deliverability' => Deliverability::Yes,
            'validation_message' => 'Load test address',
            ...collect($address)->mapWithKeys(fn (string $value, string $key): array => ["validated_{$key}" => $value])->all(),
        ]);
    }
}
