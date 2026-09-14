<?php

namespace App\Console\Commands;

use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\ShipmentStatus;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Models\User;
use App\Services\ClientContext;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Internal tooling for our sales demo environment, not a self-host feature. It
 * re-seeds from whichever active Database data source it is pointed at, and the
 * wrapper that fills our demo import database to the current date lives in a
 * private repository. See docs/self-hosting.md.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset
        {--source= : DataSource ID to seed from (defaults to the only active database source)}
        {--days=30 : Days of shipment history to import from the demo database}
        {--open-hours=8 : Shipments newer than this many hours stay open for the live demo}
        {--skip-export : Do not write fabricated tracking data back to the import database}';

    protected $description = '[Internal] Reset demo data: wipe transactional tables, re-seed history from a Database data source, fabricate shipped packages, and rebuild stats';

    private const TRANSACTIONAL_TABLES = [
        // Before `packages`: the truncate runs with foreign-key checks off, so
        // nothing cascades, and an orphaned active label would collide on the
        // unique index once refabricated packages reuse their IDs.
        'package_labels',
        'package_exports',
        'package_items',
        'package_special_services',
        'rate_quotes',
        'shipping_offers',
        'packages',
        'manifests',
        'label_batch_items',
        'label_batches',
        'pick_batch_shipments',
        'pick_batches',
        'shipment_items',
        'shipments',
        'daily_shipping_stats',
        'audit_logs',
        'notifications',
    ];

    /**
     * Carrier name => [weight (out of 100), services => [[code, name, weight, costMin, costMax]]].
     * Mirrors app:generate-test-data so demo charts show a believable carrier mix.
     */
    private const CARRIER_CONFIG = [
        'USPS' => [
            'weight' => 60,
            'services' => [
                ['code' => 'USPS_GROUND_ADVANTAGE', 'name' => 'Ground Advantage', 'weight' => 60, 'costMin' => 4.00, 'costMax' => 12.00],
                ['code' => 'PRIORITY_MAIL', 'name' => 'Priority Mail', 'weight' => 30, 'costMin' => 8.00, 'costMax' => 22.00],
                ['code' => 'PRIORITY_MAIL_EXPRESS', 'name' => 'Priority Mail Express', 'weight' => 10, 'costMin' => 22.00, 'costMax' => 45.00],
            ],
        ],
        'FedEx' => [
            'weight' => 20,
            'services' => [
                ['code' => 'GROUND_HOME_DELIVERY', 'name' => 'FedEx Ground Home Delivery', 'weight' => 60, 'costMin' => 7.00, 'costMax' => 18.00],
                ['code' => 'FEDEX_GROUND', 'name' => 'FedEx Ground', 'weight' => 40, 'costMin' => 8.00, 'costMax' => 20.00],
            ],
        ],
        'UPS' => [
            'weight' => 20,
            'services' => [
                ['code' => '03', 'name' => 'UPS Ground', 'weight' => 70, 'costMin' => 8.00, 'costMax' => 20.00],
                ['code' => '02', 'name' => 'UPS 2nd Day Air', 'weight' => 30, 'costMin' => 15.00, 'costMax' => 30.00],
            ],
        ],
    ];

    public function handle(): int
    {
        if (! app()->environment('demo', 'local', 'testing')) {
            $this->error('demo:reset can only run when APP_ENV is demo, local, or testing.');

            return self::FAILURE;
        }

        $record = $this->resolveDataSource();

        if (! $record) {
            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $openHours = max(0, (int) $this->option('open-hours'));
        $from = now('UTC')->subDays($days);

        $this->components->info("Resetting demo data from source '{$record->name}' ({$days} days of history, last {$openHours}h left open).");

        // Build the history-window source first: this registers the import DB
        // connection so the pre-import checks below can query it.
        $source = $this->makeHistorySource($record, $from);
        $mapping = $source->getFieldMapping()['shipment'] ?? [];

        try {
            $source->validateConfiguration();
        } catch (\Exception $e) {
            $this->error("Cannot reach the demo import database: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->truncateTransactionalTables();
        $this->ensureChannelAliases($record, $from, $mapping);
        $this->warnAboutUnmappedShippingMethods($record, $from, $mapping);

        $result = ShipmentImportService::forSource($source, $record)->import();
        $this->components->twoColumnDetail('Shipments imported', (string) $result->shipmentsCreated);

        if ($result->errors !== []) {
            $this->warn(count($result->errors).' import error(s); first: '.$result->errors[0]);
        }

        $this->backdateShipments($record, $from, $mapping);

        $cutoff = now()->subHours($openHours);
        $shippedCount = $this->fabricateShippedHistory($record, $cutoff);
        $this->components->twoColumnDetail('Historical packages fabricated', (string) $shippedCount);

        if ($this->option('skip-export')) {
            // Mark fabricated packages exported so a scheduled export run does not
            // try to push thousands of historical rows later.
            DB::table('packages')->where('status', PackageStatus::Shipped->value)->update(['exported' => true]);
            $this->components->twoColumnDetail('Export write-back', 'skipped (packages marked exported)');
        } else {
            $exported = $this->exportFabricatedPackages();
            $this->components->twoColumnDetail('Packages exported back to import DB', (string) $exported);
        }

        $this->call('stats:aggregate', [
            '--from' => $from->toDateString(),
            '--to' => now()->toDateString(),
        ]);

        $this->components->twoColumnDetail('Open shipments ready to pack', (string) Shipment::where('status', ShipmentStatus::Open)->count());
        $this->components->info('Demo reset complete.');

        return self::SUCCESS;
    }

    private function resolveDataSource(): ?DataSource
    {
        if ($sourceId = $this->option('source')) {
            $record = DataSource::find($sourceId);

            if (! $record || $record->source_type !== DatabaseSource::class) {
                $this->error("DataSource {$sourceId} not found or is not a database source.");

                return null;
            }

            return $record;
        }

        $candidates = DataSource::where('source_type', DatabaseSource::class)->where('active', true)->get();

        if ($candidates->count() !== 1) {
            $this->error($candidates->isEmpty()
                ? 'No active database data source found. Configure one under Integrations first.'
                : 'Multiple active database data sources found. Pass --source=<id> to pick one.');

            return null;
        }

        return $candidates->first();
    }

    /**
     * Build a DatabaseSource whose shipments query covers the whole history window,
     * regardless of how the data source's live import query is configured.
     * The settings mutation is in-memory only and never persisted.
     */
    private function makeHistorySource(DataSource $record, Carbon $from): DatabaseSource
    {
        $settings = $record->settings ?? [];
        $table = $settings['shipments_table'] ?? 'shipments';

        $settings['shipments_query'] = sprintf(
            "select * from %s where created_at >= '%s' order by created_at",
            $table,
            $from->toDateTimeString(),
        );

        $record->settings = $settings;

        $source = app(DataSourceFactory::class)->make($record);

        if (! $source instanceof DatabaseSource) {
            throw new \RuntimeException('Expected a DatabaseSource driver.');
        }

        return $source;
    }

    private function importConnection(DataSource $record): string
    {
        return 'import_'.$record->id;
    }

    private function truncateTransactionalTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TRANSACTIONAL_TABLES as $table) {
            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();

        $this->components->twoColumnDetail('Transactional tables truncated', (string) count(self::TRANSACTIONAL_TABLES));
    }

    /**
     * Channels are not auto-created on import, so make sure every channel value in
     * the history window resolves via a ChannelAlias for the source's client.
     *
     * @param  array<string, string>  $mapping
     */
    private function ensureChannelAliases(DataSource $record, Carbon $from, array $mapping): void
    {
        $channelColumn = array_search('channel_id', $mapping, true) ?: 'channel';
        $table = ($record->settings ?? [])['shipments_table'] ?? 'shipments';
        $client = $record->client ?? app(ClientContext::class)->default();

        $values = DB::connection($this->importConnection($record))
            ->table($table)
            ->where('created_at', '>=', $from)
            ->whereNotNull($channelColumn)
            ->distinct()
            ->pluck($channelColumn);

        foreach ($values as $name) {
            $channel = Channel::firstOrCreate(['name' => $name], ['active' => true]);

            ChannelAlias::firstOrCreate(
                ['client_id' => $client->id, 'reference' => $name],
                ['channel_id' => $channel->id],
            );
        }
    }

    /**
     * @param  array<string, string>  $mapping
     */
    private function warnAboutUnmappedShippingMethods(DataSource $record, Carbon $from, array $mapping): void
    {
        $column = array_search('shipping_method_id', $mapping, true) ?: 'shipping_method';
        $table = ($record->settings ?? [])['shipments_table'] ?? 'shipments';
        $client = $record->client ?? app(ClientContext::class)->default();

        $values = DB::connection($this->importConnection($record))
            ->table($table)
            ->where('created_at', '>=', $from)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->map(fn ($value): string => (string) $value);

        $known = ShippingMethod::pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->merge(ShippingMethodAlias::where('client_id', $client->id)->pluck('reference'));

        $unmapped = $values->diff($known);

        if ($unmapped->isNotEmpty()) {
            $this->warn('Shipping method references with no match (will land in Unmapped Shipping References): '.$unmapped->implode(', '));
        }
    }

    /**
     * The importer stamps created_at with the import time; rewrite it from the
     * source rows so history is spread across the window for reports and widgets.
     *
     * @param  array<string, string>  $mapping
     */
    private function backdateShipments(DataSource $record, Carbon $from, array $mapping): void
    {
        $referenceColumn = array_search('shipment_reference', $mapping, true) ?: 'id';
        $table = ($record->settings ?? [])['shipments_table'] ?? 'shipments';

        $sourceTimestamps = DB::connection($this->importConnection($record))
            ->table($table)
            ->where('created_at', '>=', $from)
            ->pluck('created_at', $referenceColumn);

        $shipmentIds = Shipment::where('data_source_id', $record->id)->pluck('id', 'source_record_id');

        $shipmentIds->chunk(500)->each(function ($chunk) use ($sourceTimestamps): void {
            DB::transaction(function () use ($chunk, $sourceTimestamps): void {
                foreach ($chunk as $sourceRecordId => $shipmentId) {
                    $createdAt = $sourceTimestamps[$sourceRecordId] ?? null;

                    if ($createdAt === null) {
                        continue;
                    }

                    DB::table('shipments')
                        ->where('id', $shipmentId)
                        ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

                    DB::table('shipment_items')
                        ->where('shipment_id', $shipmentId)
                        ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
                }
            });
        });
    }

    /**
     * Create realistic shipped packages (tracking, cost, carrier mix, manifests)
     * for every imported shipment older than the cutoff, and mark them shipped.
     * Recent shipments stay open so they can be packed live during the demo.
     */
    private function fabricateShippedHistory(DataSource $record, Carbon $cutoff): int
    {
        $locationId = Location::getDefault()?->id;
        $boxSizeIds = BoxSize::pluck('id')->all();
        $userIds = User::pluck('id')->all();
        $timezone = Location::timezone();

        /** @var array<string, array<string, int>> $manifests carrier => [date => manifest_id] */
        $manifests = [];
        $shipped = 0;

        Shipment::query()
            ->where('data_source_id', $record->id)
            ->where('status', ShipmentStatus::Open)
            ->where('created_at', '<', $cutoff)
            ->with('shipmentItems.product')
            ->chunkById(200, function ($shipments) use (&$manifests, &$shipped, $locationId, $boxSizeIds, $userIds, $timezone): void {
                DB::transaction(function () use ($shipments, &$manifests, &$shipped, $locationId, $boxSizeIds, $userIds, $timezone): void {
                    foreach ($shipments as $shipment) {
                        $this->fabricatePackage($shipment, $manifests, $locationId, $boxSizeIds, $userIds, $timezone);
                        $shipped++;
                    }

                    DB::table('shipments')->whereIn('id', $shipments->pluck('id'))->update([
                        'status' => ShipmentStatus::Shipped->value,
                        'checked' => true,
                        'deliverability' => 'yes',
                    ]);
                });
            });

        // Stamp package counts on the fabricated manifests.
        Manifest::query()->get()->each(fn (Manifest $manifest) => $manifest->update([
            'package_count' => $manifest->packages()->count(),
        ]));

        return $shipped;
    }

    /**
     * @param  array<string, array<string, int>>  $manifests
     * @param  array<int, int>  $boxSizeIds
     * @param  array<int, int>  $userIds
     */
    private function fabricatePackage(Shipment $shipment, array &$manifests, ?int $locationId, array $boxSizeIds, array $userIds, string $timezone): void
    {
        $carrier = $this->pickCarrier();
        $service = $this->pickService($carrier);

        $createdAt = $shipment->created_at;
        $shippedAt = $createdAt->copy()->addHours(mt_rand(1, 36));

        if ($shippedAt->isFuture()) {
            $shippedAt = now()->subMinutes(mt_rand(15, 180));
        }

        $shipDate = $shippedAt->copy()->setTimezone($timezone)->toDateString();

        $weight = round($shipment->shipmentItems->sum(
            fn ($item): float => $item->quantity * (float) ($item->product?->weight ?? 0)
        ) + 0.25, 2);

        if ($weight <= 0.25) {
            $weight = round(mt_rand(50, 300) / 100, 2);
        }

        // The facts the package and its label row share, built once so the
        // two inserts cannot disagree.
        $projected = [
            'tracking_number' => $this->generateTrackingNumber($carrier),
            'carrier' => $carrier,
            'service' => $service['name'],
            'service_evidence' => ServiceEvidence::Confirmed->value,
            'label_orientation' => 'portrait',
            'label_format' => 'pdf',
            'label_dpi' => 203,
            'cost' => round(mt_rand((int) ($service['costMin'] * 100), (int) ($service['costMax'] * 100)) / 100, 2),
            'postage_source' => PostageSource::CarrierAccount->value,
            'ship_date' => $shipDate,
            'shipped_at' => $shippedAt->format('Y-m-d H:i:s'),
            'shipped_by_user_id' => $userIds === [] ? null : $userIds[array_rand($userIds)],
        ];

        $packageId = DB::table('packages')->insertGetId($projected + [
            'shipment_id' => $shipment->id,
            'location_id' => $locationId,
            'box_size_id' => $boxSizeIds === [] ? null : $boxSizeIds[array_rand($boxSizeIds)],
            'weight' => $weight,
            'height' => round(mt_rand(200, 1200) / 100, 2),
            'width' => round(mt_rand(400, 1400) / 100, 2),
            'length' => round(mt_rand(600, 1800) / 100, 2),
            'status' => PackageStatus::Shipped->value,
            'exported' => false,
            'manifest_id' => $this->manifestIdFor($manifests, $carrier, $shipDate, $locationId),
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $shippedAt->format('Y-m-d H:i:s'),
        ]);

        DB::table('package_labels')->insert(PackageLabel::projectionFrom($projected) + [
            'package_id' => $packageId,
            'created_at' => $shippedAt->format('Y-m-d H:i:s'),
            'updated_at' => $shippedAt->format('Y-m-d H:i:s'),
        ]);

        $packageItemRows = $shipment->shipmentItems->map(fn ($item): array => [
            'package_id' => $packageId,
            'shipment_item_id' => $item->id,
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'created_at' => $shippedAt->format('Y-m-d H:i:s'),
            'updated_at' => $shippedAt->format('Y-m-d H:i:s'),
        ])->all();

        if ($packageItemRows !== []) {
            DB::table('package_items')->insert($packageItemRows);
        }
    }

    /**
     * Historical packages must belong to a manifest, otherwise they pile up on the
     * End of Day page and bury the packages shipped live during the demo.
     *
     * @param  array<string, array<string, int>>  $manifests
     */
    private function manifestIdFor(array &$manifests, string $carrier, string $shipDate, ?int $locationId): int
    {
        if (! isset($manifests[$carrier][$shipDate])) {
            $manifests[$carrier][$shipDate] = DB::table('manifests')->insertGetId([
                'carrier' => $carrier,
                'location_id' => $locationId,
                'manifest_number' => '92'.$this->randomDigits(20),
                'manifest_date' => $shipDate,
                'package_count' => 0,
                'created_at' => $shipDate.' 21:00:00',
                'updated_at' => $shipDate.' 21:00:00',
            ]);
        }

        return $manifests[$carrier][$shipDate];
    }

    /**
     * Push fabricated tracking data back to the import database through the
     * configured export query, marking the source rows as exported so the live
     * import only picks up genuinely new rows.
     */
    private function exportFabricatedPackages(): int
    {
        $exportService = app(PackageExportService::class);

        $packages = Package::query()
            ->where('status', PackageStatus::Shipped)
            ->where('exported', false)
            ->orderBy('id');

        $succeeded = 0;
        $consecutiveFailures = 0;

        $bar = $this->output->createProgressBar((clone $packages)->count());

        foreach ($packages->cursor() as $package) {
            $result = $exportService->exportPackage($package);

            if ($result->success && $result->destinationsSucceeded > 0) {
                $succeeded++;
                $consecutiveFailures = 0;
            } elseif ($result->deferred) {
                $consecutiveFailures = 0;
            } elseif (! $result->success) {
                if (++$consecutiveFailures >= 10) {
                    $bar->finish();
                    $this->newLine();
                    $this->warn('Aborting export write-back after 10 consecutive failures; last error: '.implode('; ', $result->errors));
                    $this->warn('Fix the export configuration and re-run, or use --skip-export.');

                    return $succeeded;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $succeeded;
    }

    private function pickCarrier(): string
    {
        $roll = mt_rand(1, 100);
        $cumulative = 0;

        foreach (self::CARRIER_CONFIG as $name => $config) {
            $cumulative += $config['weight'];

            if ($roll <= $cumulative) {
                return $name;
            }
        }

        return 'USPS';
    }

    /**
     * @return array{code: string, name: string, weight: int, costMin: float, costMax: float}
     */
    private function pickService(string $carrier): array
    {
        $services = self::CARRIER_CONFIG[$carrier]['services'];
        $roll = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($services as $service) {
            $cumulative += $service['weight'];

            if ($roll <= $cumulative) {
                return $service;
            }
        }

        return $services[0];
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
