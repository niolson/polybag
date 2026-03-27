<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateTestData extends Command
{
    protected $signature = 'app:generate-test-data
                            {--count=75000 : Number of shipments to generate}
                            {--chunk=1000 : Batch insert size}
                            {--cleanup : Remove all previously generated test data (TD- prefix)}';

    protected $description = 'Generate bulk realistic test data for stress-testing (shipments, packages, items, rate quotes)';

    // Reference data loaded once
    private array $channelIds;

    private array $shippingMethodIds;

    private array $boxSizeIds;

    private array $productIds;

    private array $userIds;

    // Carrier config: name => [weight, services => [[code, name, weight, costMin, costMax]]]
    private array $carrierConfig;

    // Weighted item/package counts
    private array $itemCountWeights = [1 => 40, 2 => 30, 3 => 20, 4 => 10];

    private array $packageCountWeights = [1 => 70, 2 => 20, 3 => 10];

    // US states for addresses
    private array $states = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
        'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
        'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
        'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
        'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
    ];

    private array $cities = [
        'Springfield', 'Portland', 'Franklin', 'Clinton', 'Greenville',
        'Bristol', 'Fairview', 'Salem', 'Madison', 'Georgetown',
        'Arlington', 'Ashland', 'Burlington', 'Chester', 'Dayton',
        'Dover', 'Hudson', 'Kingston', 'Lexington', 'Manchester',
        'Milton', 'Newport', 'Oakland', 'Oxford', 'Plymouth',
        'Richmond', 'Riverside', 'Shelby', 'Troy', 'Winchester',
    ];

    private array $streetNames = [
        'Main St', 'Oak Ave', 'Cedar Ln', 'Maple Dr', 'Elm St',
        'Pine Rd', 'Washington Blvd', 'Lake Ave', 'Hill Rd', 'Park Pl',
        'Church St', 'High St', 'Broad St', 'Walnut St', 'Center St',
        'River Rd', 'Spring St', 'Market St', 'Union St', 'Court St',
    ];

    private array $firstNames = [
        'James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda',
        'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica',
        'Thomas', 'Sarah', 'Christopher', 'Karen', 'Charles', 'Lisa', 'Daniel', 'Nancy',
        'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra',
    ];

    private array $lastNames = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
        'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson',
        'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson',
        'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson',
    ];

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        // Use UTC for this session to avoid DST gap rejections in MySQL
        DB::statement("SET time_zone = '+00:00'");

        $count = (int) $this->option('count');
        $chunkSize = (int) $this->option('chunk');

        if ($count < 1) {
            $this->error('Count must be at least 1.');

            return Command::FAILURE;
        }

        $this->info("Generating {$count} shipments with related data...");
        $this->newLine();

        if (! $this->loadReferenceData()) {
            return Command::FAILURE;
        }

        $this->buildCarrierConfig();

        $totalPackages = 0;
        $totalItems = 0;
        $totalRateQuotes = 0;
        $chunks = (int) ceil($count / $chunkSize);
        $startTime = microtime(true);

        // Find the highest existing TD- reference to avoid collisions
        $maxRef = Shipment::where('shipment_reference', 'like', 'TD-%')
            ->max('shipment_reference');
        $startOffset = $maxRef ? (int) str_replace('TD-', '', $maxRef) : 0;

        $bar = $this->output->createProgressBar($chunks);
        $bar->setFormat(' %current%/%max% chunks [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $batchCount = min($chunkSize, $count - ($chunk * $chunkSize));
            $stats = $this->generateChunk($batchCount, $startOffset + ($chunk * $chunkSize));

            $totalPackages += $stats['packages'];
            $totalItems += $stats['items'];
            $totalRateQuotes += $stats['rate_quotes'];

            $processed = ($chunk + 1) * $chunkSize;
            $processed = min($processed, $count);
            $bar->setMessage("{$processed} shipments, {$totalPackages} pkgs, {$totalItems} items");
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Done in {$elapsed}s!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Shipments', number_format($count)],
                ['Packages', number_format($totalPackages)],
                ['Shipment Items', number_format($totalItems)],
                ['Package Items', number_format($totalItems)],
                ['Rate Quotes', number_format($totalRateQuotes)],
            ]
        );

        return Command::SUCCESS;
    }

    private function cleanup(): int
    {
        $shipmentIds = DB::table('shipments')
            ->where('shipment_reference', 'like', 'TD-%')
            ->pluck('id');

        if ($shipmentIds->isEmpty()) {
            $this->info('No test data found (TD- prefix).');

            return Command::SUCCESS;
        }

        $count = $shipmentIds->count();
        if (! $this->confirm("Delete {$count} test shipments and all related data?")) {
            return Command::SUCCESS;
        }

        $this->info('Cleaning up test data...');
        $bar = $this->output->createProgressBar(5);

        // Delete in FK order (children first)
        foreach ($shipmentIds->chunk(5000) as $chunk) {
            $packageIds = DB::table('packages')->whereIn('shipment_id', $chunk)->pluck('id');

            DB::table('rate_quotes')->whereIn('package_id', $packageIds)->delete();
            $bar->advance();

            DB::table('package_items')->whereIn('package_id', $packageIds)->delete();
            $bar->advance();

            DB::table('packages')->whereIn('id', $packageIds)->delete();
            $bar->advance();

            DB::table('shipment_items')->whereIn('shipment_id', $chunk)->delete();
            $bar->advance();

            DB::table('shipments')->whereIn('id', $chunk)->delete();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Removed {$count} test shipments and all related data.");

        return Command::SUCCESS;
    }

    private function loadReferenceData(): bool
    {
        $this->channelIds = DB::table('channels')->where('active', true)->pluck('id')->all();
        $this->shippingMethodIds = DB::table('shipping_methods')->where('active', true)->pluck('id')->all();
        $this->boxSizeIds = DB::table('box_sizes')->pluck('id')->all();
        $this->userIds = DB::table('users')->pluck('id')->all();

        if (empty($this->channelIds) || empty($this->shippingMethodIds) || empty($this->boxSizeIds) || empty($this->userIds)) {
            $this->error('Missing reference data. Run `php artisan db:seed` first to seed carriers, channels, box sizes, etc.');

            return false;
        }

        // Ensure at least 200 products exist
        $this->productIds = DB::table('products')->pluck('id')->all();
        if (count($this->productIds) < 200) {
            $this->info('Creating products to reach 200...');
            $this->seedProducts(200 - count($this->productIds));
            $this->productIds = DB::table('products')->pluck('id')->all();
        }

        return true;
    }

    private function seedProducts(int $needed): void
    {
        $now = now();
        $rows = [];
        $existingCount = count($this->productIds);

        for ($i = 0; $i < $needed; $i++) {
            $idx = $existingCount + $i + 1;
            $rows[] = [
                'sku' => 'TST'.str_pad($idx, 5, '0', STR_PAD_LEFT),
                'barcode' => (string) mt_rand(1000000000000, 9999999999999),
                'name' => 'Test Product '.$idx,
                'description' => 'Auto-generated test product',
                'weight' => round(mt_rand(10, 5000) / 100, 2),
                'hs_tariff_number' => null,
                'country_of_origin' => 'US',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('products')->insert($chunk);
        }
    }

    private function buildCarrierConfig(): void
    {
        // carrier name => [weight (out of 100), services => [[code, name, weight, costMin, costMax]]]
        $this->carrierConfig = [
            'USPS' => [
                'weight' => 65,
                'services' => [
                    ['code' => 'USPS_GROUND_ADVANTAGE', 'name' => 'Ground Advantage', 'weight' => 60, 'costMin' => 4.00, 'costMax' => 12.00],
                    ['code' => 'PRIORITY_MAIL', 'name' => 'Priority Mail', 'weight' => 30, 'costMin' => 8.00, 'costMax' => 22.00],
                    ['code' => 'PRIORITY_MAIL_EXPRESS', 'name' => 'Priority Mail Express', 'weight' => 10, 'costMin' => 22.00, 'costMax' => 45.00],
                ],
            ],
            'FedEx' => [
                'weight' => 25,
                'services' => [
                    ['code' => 'GROUND_HOME_DELIVERY', 'name' => 'FedEx Ground Home Delivery', 'weight' => 50, 'costMin' => 7.00, 'costMax' => 18.00],
                    ['code' => 'FEDEX_GROUND', 'name' => 'FedEx Ground', 'weight' => 30, 'costMin' => 8.00, 'costMax' => 20.00],
                    ['code' => 'FEDEX_EXPRESS_SAVER', 'name' => 'FedEx Express Saver', 'weight' => 20, 'costMin' => 15.00, 'costMax' => 35.00],
                ],
            ],
            'UPS' => [
                'weight' => 10,
                'services' => [
                    ['code' => '03', 'name' => 'UPS Ground', 'weight' => 60, 'costMin' => 8.00, 'costMax' => 20.00],
                    ['code' => '02', 'name' => 'UPS 2nd Day Air', 'weight' => 25, 'costMin' => 15.00, 'costMax' => 30.00],
                    ['code' => '13', 'name' => 'UPS Next Day Air Saver', 'weight' => 15, 'costMin' => 25.00, 'costMax' => 45.00],
                ],
            ],
        ];
    }

    private function generateChunk(int $batchCount, int $offset): array
    {
        $now = now()->utc();
        $sixMonthsAgo = $now->copy()->subMonths(6);

        // --- Build shipment rows ---
        $shipmentRows = [];
        $shipmentMeta = []; // per-shipment metadata for downstream tables

        for ($i = 0; $i < $batchCount; $i++) {
            $globalIdx = $offset + $i;

            // Date weighted toward recent: use sqrt distribution
            $daysRange = $sixMonthsAgo->diffInDays($now);
            $rand = mt_rand(0, 10000) / 10000;
            $createdAt = $sixMonthsAgo->copy()->addDays((int) ($daysRange * sqrt($rand)));
            $createdAtStr = $createdAt->format('Y-m-d H:i:s');

            // Status: 90% shipped, 5% partial, 5% unshipped
            $statusRoll = mt_rand(1, 100);
            $status = $statusRoll <= 90 ? 'shipped' : ($statusRoll <= 95 ? 'partial' : 'unshipped');

            $state = $this->states[array_rand($this->states)];
            $channelId = $this->channelIds[array_rand($this->channelIds)];
            $shippingMethodId = $this->shippingMethodIds[array_rand($this->shippingMethodIds)];
            $itemCount = $this->weightedRandom($this->itemCountWeights);
            $packageCount = $this->weightedRandom($this->packageCountWeights);
            $value = round(mt_rand(500, 25000) / 100, 2);

            // Pick carrier/service for this shipment
            $carrier = $this->pickCarrier();
            $service = $this->pickService($carrier);

            $shipmentRows[] = [
                'shipment_reference' => 'TD-'.str_pad($globalIdx + 1, 8, '0', STR_PAD_LEFT),
                'first_name' => $this->firstNames[array_rand($this->firstNames)],
                'last_name' => $this->lastNames[array_rand($this->lastNames)],
                'company' => mt_rand(1, 5) === 1 ? 'Test Company '.mt_rand(1, 999) : null,
                'address1' => mt_rand(100, 9999).' '.$this->streetNames[array_rand($this->streetNames)],
                'address2' => mt_rand(1, 10) === 1 ? 'Apt '.mt_rand(1, 500) : null,
                'city' => $this->cities[array_rand($this->cities)],
                'state_or_province' => $state,
                'postal_code' => str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT),
                'country' => 'US',
                'residential' => mt_rand(1, 5) !== 1,
                'phone' => mt_rand(200, 999).'-'.mt_rand(200, 999).'-'.str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT),
                'phone_extension' => null,
                'email' => null,
                'value' => $value,
                'checked' => true,
                'deliverability' => 'yes',
                'validation_message' => null,
                'validated_address1' => null,
                'validated_city' => null,
                'validated_state_or_province' => null,
                'validated_postal_code' => null,
                'validated_country' => null,
                'validated_residential' => null,
                'shipping_method_id' => $shippingMethodId,
                'shipping_method_reference' => null,
                'channel_id' => $channelId,
                'channel_reference' => null,
                'status' => $status === 'shipped' ? 'shipped' : 'open',
                'metadata' => null,
                'deliver_by' => null,
                'created_at' => $createdAtStr,
                'updated_at' => $createdAtStr,
            ];

            $shipmentMeta[] = [
                'status' => $status,
                'itemCount' => $itemCount,
                'packageCount' => $packageCount,
                'carrier' => $carrier,
                'service' => $service,
                'createdAt' => $createdAt,
                'value' => $value,
            ];
        }

        // Insert shipments and get IDs
        DB::table('shipments')->insert($shipmentRows);
        $firstShipmentId = DB::table('shipments')
            ->where('shipment_reference', $shipmentRows[0]['shipment_reference'])
            ->value('id');

        // Build shipment ID array (contiguous from bulk insert)
        $shipmentIds = range($firstShipmentId, $firstShipmentId + $batchCount - 1);
        unset($shipmentRows); // free memory

        // --- Build shipment items ---
        $shipmentItemRows = [];
        $shipmentItemMeta = []; // [shipmentIdx => [[productId, qty], ...]]

        foreach ($shipmentMeta as $idx => $meta) {
            $items = [];
            for ($j = 0; $j < $meta['itemCount']; $j++) {
                $productId = $this->productIds[array_rand($this->productIds)];
                $qty = mt_rand(1, 3);
                $itemValue = round($meta['value'] / $meta['itemCount'], 2);

                $shipmentItemRows[] = [
                    'shipment_id' => $shipmentIds[$idx],
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'value' => $itemValue,
                    'transparency' => false,
                    'created_at' => $meta['createdAt']->format('Y-m-d H:i:s'),
                    'updated_at' => $meta['createdAt']->format('Y-m-d H:i:s'),
                ];

                $items[] = ['productId' => $productId, 'qty' => $qty];
            }
            $shipmentItemMeta[$idx] = $items;
        }

        // Insert in sub-chunks to avoid packet limits
        $totalItems = count($shipmentItemRows);
        foreach (array_chunk($shipmentItemRows, 2000) as $subChunk) {
            DB::table('shipment_items')->insert($subChunk);
        }

        // Get shipment item IDs
        $firstItemId = DB::table('shipment_items')
            ->where('shipment_id', $shipmentIds[0])
            ->orderBy('id')
            ->value('id');
        $shipmentItemIds = range($firstItemId, $firstItemId + $totalItems - 1);
        unset($shipmentItemRows);

        // Build a map: shipmentIdx => [itemId, itemId, ...]
        $itemIdCursor = 0;
        $shipmentItemIdMap = [];
        foreach ($shipmentMeta as $idx => $meta) {
            $ids = [];
            for ($j = 0; $j < $meta['itemCount']; $j++) {
                $ids[] = $shipmentItemIds[$itemIdCursor];
                $itemIdCursor++;
            }
            $shipmentItemIdMap[$idx] = $ids;
        }
        unset($shipmentItemIds);

        // --- Build packages ---
        $packageRows = [];
        $packageMeta = []; // flat array: [shipmentIdx, packageIdx, shipped, ...]

        foreach ($shipmentMeta as $idx => $meta) {
            $isShipped = $meta['status'] === 'shipped';
            $isPartial = $meta['status'] === 'partial';

            for ($p = 0; $p < $meta['packageCount']; $p++) {
                // For partial: first package shipped, rest unshipped
                $pkgShipped = $isShipped || ($isPartial && $p === 0);
                $shippedAt = $pkgShipped
                    ? $meta['createdAt']->copy()->addHours(mt_rand(1, 48))->format('Y-m-d H:i:s')
                    : null;

                $carrier = $meta['carrier'];
                $service = $meta['service'];

                $packageRows[] = [
                    'shipment_id' => $shipmentIds[$idx],
                    'box_size_id' => $this->boxSizeIds[array_rand($this->boxSizeIds)],
                    'tracking_number' => $pkgShipped ? $this->generateTrackingNumber($carrier) : null,
                    'carrier' => $pkgShipped ? $carrier : null,
                    'service' => $pkgShipped ? $service['code'] : null,
                    'metadata' => null,
                    'label_data' => null,
                    'label_orientation' => 'portrait',
                    'label_format' => 'pdf',
                    'label_dpi' => 203,
                    'weight' => round(mt_rand(50, 5000) / 100, 2),
                    'height' => round(mt_rand(200, 2000) / 100, 2),
                    'width' => round(mt_rand(200, 2000) / 100, 2),
                    'length' => round(mt_rand(200, 2000) / 100, 2),
                    'cost' => $pkgShipped ? round(mt_rand((int) ($service['costMin'] * 100), (int) ($service['costMax'] * 100)) / 100, 2) : null,
                    'status' => $pkgShipped ? 'shipped' : 'unshipped',
                    'ship_date' => $pkgShipped ? substr($shippedAt, 0, 10) : null,
                    'shipped_at' => $shippedAt,
                    'shipped_by_user_id' => $pkgShipped ? $this->userIds[array_rand($this->userIds)] : null,
                    'exported' => $pkgShipped && mt_rand(1, 2) === 1,
                    'manifest_id' => null,
                    'created_at' => $meta['createdAt']->format('Y-m-d H:i:s'),
                    'updated_at' => $shippedAt ?? $meta['createdAt']->format('Y-m-d H:i:s'),
                ];

                $packageMeta[] = [
                    'shipmentIdx' => $idx,
                    'pkgIdx' => $p,
                    'isShipped' => $pkgShipped,
                    'carrier' => $carrier,
                    'service' => $service,
                    'createdAt' => $meta['createdAt'],
                ];
            }
        }

        $totalPackages = count($packageRows);
        foreach (array_chunk($packageRows, 2000) as $subChunk) {
            DB::table('packages')->insert($subChunk);
        }

        // Get package IDs
        $firstPkgId = DB::table('packages')
            ->where('shipment_id', $shipmentIds[0])
            ->orderBy('id')
            ->value('id');
        $packageIds = range($firstPkgId, $firstPkgId + $totalPackages - 1);
        unset($packageRows);

        // --- Build package items ---
        $packageItemRows = [];
        $pkgIdCursor = 0;

        foreach ($shipmentMeta as $idx => $meta) {
            $itemIds = $shipmentItemIdMap[$idx];
            $items = $shipmentItemMeta[$idx];

            // Distribute items across packages round-robin
            for ($p = 0; $p < $meta['packageCount']; $p++) {
                $pkgId = $packageIds[$pkgIdCursor + $p];

                // Each package gets at least one item
                $startItem = $p === 0 ? 0 : (int) round(count($items) * $p / $meta['packageCount']);
                $endItem = $p === $meta['packageCount'] - 1
                    ? count($items)
                    : (int) round(count($items) * ($p + 1) / $meta['packageCount']);

                // Ensure at least one item per package
                if ($startItem >= $endItem) {
                    $startItem = min($p, count($items) - 1);
                    $endItem = $startItem + 1;
                }

                for ($j = $startItem; $j < $endItem && $j < count($items); $j++) {
                    $packageItemRows[] = [
                        'package_id' => $pkgId,
                        'shipment_item_id' => $itemIds[$j],
                        'product_id' => $items[$j]['productId'],
                        'quantity' => $items[$j]['qty'],
                        'transparency_codes' => null,
                        'created_at' => $meta['createdAt']->format('Y-m-d H:i:s'),
                        'updated_at' => $meta['createdAt']->format('Y-m-d H:i:s'),
                    ];
                }
            }

            $pkgIdCursor += $meta['packageCount'];
        }

        foreach (array_chunk($packageItemRows, 2000) as $subChunk) {
            DB::table('package_items')->insert($subChunk);
        }
        $totalPackageItems = count($packageItemRows);
        unset($packageItemRows, $shipmentItemMeta, $shipmentItemIdMap);

        // --- Build rate quotes (for shipped packages only) ---
        $rateQuoteRows = [];

        foreach ($packageMeta as $pkgMetaIdx => $pm) {
            if (! $pm['isShipped']) {
                continue;
            }

            $pkgId = $packageIds[$pkgMetaIdx];
            $quoteCount = mt_rand(2, 4);

            // The selected quote matches the package's carrier/service
            $rateQuoteRows[] = [
                'package_id' => $pkgId,
                'carrier' => $pm['carrier'],
                'service_code' => $pm['service']['code'],
                'service_name' => $pm['service']['name'],
                'quoted_price' => round(mt_rand((int) ($pm['service']['costMin'] * 100), (int) ($pm['service']['costMax'] * 100)) / 100, 2),
                'quoted_delivery_date' => $pm['createdAt']->copy()->addDays(mt_rand(2, 7))->format('Y-m-d'),
                'transit_time' => mt_rand(1, 5).' days',
                'selected' => true,
                'created_at' => $pm['createdAt']->format('Y-m-d H:i:s'),
            ];

            // Add non-selected alternative quotes from other carriers/services
            for ($q = 1; $q < $quoteCount; $q++) {
                $altCarrier = $this->pickCarrier();
                $altService = $this->pickService($altCarrier);

                $rateQuoteRows[] = [
                    'package_id' => $pkgId,
                    'carrier' => $altCarrier,
                    'service_code' => $altService['code'],
                    'service_name' => $altService['name'],
                    'quoted_price' => round(mt_rand((int) ($altService['costMin'] * 100), (int) ($altService['costMax'] * 100)) / 100, 2),
                    'quoted_delivery_date' => $pm['createdAt']->copy()->addDays(mt_rand(2, 7))->format('Y-m-d'),
                    'transit_time' => mt_rand(1, 5).' days',
                    'selected' => false,
                    'created_at' => $pm['createdAt']->format('Y-m-d H:i:s'),
                ];
            }
        }

        $totalRateQuotes = count($rateQuoteRows);
        foreach (array_chunk($rateQuoteRows, 2000) as $subChunk) {
            DB::table('rate_quotes')->insert($subChunk);
        }
        unset($rateQuoteRows, $packageMeta, $packageIds);

        return [
            'packages' => $totalPackages,
            'items' => $totalItems,
            'package_items' => $totalPackageItems,
            'rate_quotes' => $totalRateQuotes,
        ];
    }

    private function pickCarrier(): string
    {
        $roll = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($this->carrierConfig as $name => $config) {
            $cumulative += $config['weight'];
            if ($roll <= $cumulative) {
                return $name;
            }
        }

        return 'USPS';
    }

    private function pickService(string $carrier): array
    {
        $services = $this->carrierConfig[$carrier]['services'];
        $roll = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($services as $svc) {
            $cumulative += $svc['weight'];
            if ($roll <= $cumulative) {
                return $svc;
            }
        }

        return $services[0];
    }

    private function weightedRandom(array $weights): int
    {
        $roll = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($weights as $value => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    private function generateTrackingNumber(string $carrier): string
    {
        return match ($carrier) {
            'USPS' => '94'.$this->randomDigits(20),
            'FedEx' => $this->randomDigits(12),
            'UPS' => '1Z'.$this->randomAlphanumeric(6).$this->randomDigits(10),
            default => $this->randomDigits(20),
        };
    }

    private function randomDigits(int $length): string
    {
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= mt_rand(0, 9);
        }

        return $result;
    }

    private function randomAlphanumeric(int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[mt_rand(0, 35)];
        }

        return $result;
    }
}
