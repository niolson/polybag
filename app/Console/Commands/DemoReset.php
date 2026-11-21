<?php

namespace App\Console\Commands;

use App\DataTransferObjects\Shipping\AddressData;
use App\Enums\AppMode;
use App\Enums\DutiesTerms;
use App\Enums\LabelBatchItemStatus;
use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Enums\TrackingStatus;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Location;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ClientContext;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ImportReferenceResolver;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Carbon\CarbonInterface;
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
        {--skip-export : Leave the import database untouched (its rows stay unexported)}
        {--full-export : Run the export query for every fabricated package, writing tracking back to each row (slow)}';

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
     * The checkout labels the synthetic order database (polybag-demo-data-tools)
     * writes into `shipments.shipping_method`, and the starter method each one
     * means. The fabricated history ships on these directly; the open orders
     * import unmapped, so the presenter maps them on Map Shipping References.
     *
     * @var array<string, string>
     */
    public const DEMO_SHIPPING_REFERENCES = [
        'Standard Shipping' => 'Standard Ground',
        'Expedited Shipping' => '2 Day',
        'Overnight Shipping' => 'Overnight',
        'International Shipping' => 'International Economy',
    ];

    /**
     * Starter method name => the services its history ships on, each weighted
     * out of 100. Every list is drawn from that method's own services in
     * `ShippingMethodSeeder`, so no package ships on a service its method does
     * not allow, and the carrier mix still reads as believable on the charts.
     * A method not listed here ships on any of them.
     */
    private const HISTORY_SERVICES = [
        'Standard Ground' => [
            ['carrier' => Carrier::USPS, 'code' => 'USPS_GROUND_ADVANTAGE', 'name' => 'Ground Advantage', 'weight' => 55, 'costMin' => 4.00, 'costMax' => 12.00],
            ['carrier' => Carrier::UPS, 'code' => '03', 'name' => 'UPS Ground', 'weight' => 20, 'costMin' => 8.00, 'costMax' => 20.00],
            ['carrier' => Carrier::FEDEX, 'code' => 'GROUND_HOME_DELIVERY', 'name' => 'FedEx Ground Home Delivery', 'weight' => 15, 'costMin' => 7.00, 'costMax' => 18.00],
            ['carrier' => Carrier::FEDEX, 'code' => 'FEDEX_GROUND', 'name' => 'FedEx Ground', 'weight' => 10, 'costMin' => 8.00, 'costMax' => 20.00],
        ],
        '2 Day' => [
            ['carrier' => Carrier::USPS, 'code' => 'PRIORITY_MAIL', 'name' => 'Priority Mail', 'weight' => 70, 'costMin' => 8.00, 'costMax' => 22.00],
            ['carrier' => Carrier::FEDEX, 'code' => 'FEDEX_2_DAY', 'name' => 'FedEx 2Day', 'weight' => 30, 'costMin' => 16.00, 'costMax' => 32.00],
        ],
        'Overnight' => [
            ['carrier' => Carrier::USPS, 'code' => 'PRIORITY_MAIL_EXPRESS', 'name' => 'Priority Mail Express', 'weight' => 60, 'costMin' => 22.00, 'costMax' => 45.00],
            ['carrier' => Carrier::FEDEX, 'code' => 'PRIORITY_OVERNIGHT', 'name' => 'FedEx Priority Overnight', 'weight' => 25, 'costMin' => 35.00, 'costMax' => 75.00],
            ['carrier' => Carrier::FEDEX, 'code' => 'STANDARD_OVERNIGHT', 'name' => 'FedEx Standard Overnight', 'weight' => 15, 'costMin' => 30.00, 'costMax' => 65.00],
        ],
        'International Economy' => [
            ['carrier' => Carrier::USPS, 'code' => 'PRIORITY_MAIL_INTERNATIONAL', 'name' => 'Priority Mail International', 'weight' => 65, 'costMin' => 35.00, 'costMax' => 85.00],
            ['carrier' => Carrier::FEDEX, 'code' => 'INTERNATIONAL_ECONOMY', 'name' => 'FedEx International Economy', 'weight' => 35, 'costMin' => 45.00, 'costMax' => 110.00],
        ],
    ];

    public function handle(): int
    {
        if (AppMode::current() === AppMode::Production) {
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

        // Checked before anything is wiped: the fabricated history is shipped,
        // and a shipped package needs a shipping method (`carrier-catalog-reset/16`).
        // Open orders may stay unmapped; mapping them is part of the demo.
        $unplaceable = $this->unplaceableShippingReferences($record, $from, $mapping);

        if ($unplaceable !== []) {
            $this->error('Shipping method references with no match: '.implode(', ', $unplaceable).'. '
                .'Every demo shipment needs a shipping method. Add them to DemoReset::DEMO_SHIPPING_REFERENCES, map them with a Shipping Method alias, or fix the import data, then run this again.');

            return self::FAILURE;
        }

        $this->truncateTransactionalTables();
        $removedAliases = $this->removeDemoShippingAliases();
        $this->ensureChannelAliases($record, $from, $mapping);
        $this->ensureBillingRates();

        $result = $this->timed('Import', fn (): ImportResult => ShipmentImportService::forSource($source, $record)->import());
        $this->components->twoColumnDetail('Shipments imported', (string) $result->shipmentsCreated);

        if ($removedAliases > 0) {
            $this->components->twoColumnDetail('Demo shipping aliases removed', "{$removedAliases}, so the open orders are there to map");
        }

        if ($result->errors !== []) {
            $this->warn(count($result->errors).' import error(s); first: '.$result->errors[0]);
        }

        $this->timed('Backdate', fn () => $this->backdateShipments($record, $from, $mapping));

        $cutoff = now()->subHours($openHours);
        $shippedCount = $this->timed('Fabricate history', fn (): int => $this->fabricateShippedHistory($record, $cutoff));
        $this->components->twoColumnDetail('Historical packages fabricated', (string) $shippedCount);

        if ($this->option('skip-export')) {
            // Mark fabricated packages exported so a scheduled export run does not
            // try to push thousands of historical rows later.
            DB::table('packages')->where('status', PackageStatus::Shipped->value)->update(['exported' => true]);
            $this->components->twoColumnDetail('Export write-back', 'skipped (packages marked exported)');
        } elseif ($this->option('full-export')) {
            $exported = $this->timed('Export write-back', fn (): int => $this->exportFabricatedPackages());
            $this->components->twoColumnDetail('Packages exported back to import DB', (string) $exported);
        } else {
            $marked = $this->timed('Mark exported', fn (): int => $this->markHistoryExported($record, $from));
            $this->components->twoColumnDetail('Import rows marked exported', (string) $marked);
        }

        $this->timed('Demo prep', fn () => $this->prepareForDemo());

        $this->call('stats:aggregate', [
            '--from' => $from->toDateString(),
            '--to' => now()->toDateString(),
        ]);

        $this->components->twoColumnDetail('Open shipments ready to pack', (string) Shipment::where('status', ShipmentStatus::Open)->count());
        $this->components->info('Nothing is ready to manifest until labels are bought the same day: buy labels first, then show End of Day.');
        $this->components->info('Demo reset complete.');

        return self::SUCCESS;
    }

    /**
     * What the walk-through needs that the history alone does not give it
     * (`ui-review` findings S7, ST3, LB5, E5, PK4 and D12).
     */
    private function prepareForDemo(): void
    {
        $this->putDomesticOpenOrdersFirst();
        $this->ensureEuDutiesTerms();
        $this->attachSampleDocuments();
        $this->markOlderPackagesDelivered();
        $this->seedFinishedLabelBatch();
    }

    /**
     * The Shipments list and Scan & Pack show the newest open order first, and a
     * military or foreign one there quotes fewer carriers. The open orders keep
     * the same set of timestamps, handed out so the domestic ones are the newest.
     */
    private function putDomesticOpenOrdersFirst(): void
    {
        $open = Shipment::query()->where('status', ShipmentStatus::Open)->orderByDesc('created_at')->orderByDesc('id')->get();

        $timestamps = $open->pluck('created_at')->map(fn (CarbonInterface $at): string => $at->format('Y-m-d H:i:s'))->all();

        $open->sortBy(fn (Shipment $shipment): int => $this->isDomestic($shipment) ? 0 : 1)
            ->values()
            ->each(function (Shipment $shipment, int $position) use ($timestamps): void {
                DB::table('shipments')->where('id', $shipment->id)->update([
                    'created_at' => $timestamps[$position],
                    'updated_at' => $timestamps[$position],
                ]);
            });
    }

    private function isDomestic(Shipment $shipment): bool
    {
        $address = AddressData::fromShipment($shipment);

        return $address->country === 'US' && ! $address->isMilitary() && ! $address->isUsTerritory();
    }

    /**
     * An EU order is refused at purchase while its client has no EU terms. A
     * client that already chose some keeps it.
     */
    private function ensureEuDutiesTerms(): void
    {
        Client::query()->each(function (Client $client): void {
            $policy = $client->duties_policy ?? [];

            if (! isset($policy[Client::DUTIES_POLICY_EU])) {
                $client->update(['duties_policy' => [Client::DUTIES_POLICY_EU => DutiesTerms::Ddp->value] + $policy]);
            }
        });
    }

    /**
     * A stored label on every shipped Package and an image on every manifest, so
     * Reprint is offered on the seeded history, not only on what is bought live.
     */
    private function attachSampleDocuments(): void
    {
        $document = base64_encode($this->samplePdf('PolyBag demo label'));

        DB::table('packages')->where('status', PackageStatus::Shipped->value)->whereNull('label_data')->update(['label_data' => $document]);
        DB::table('manifests')->whereNull('image')->update(['image' => base64_encode($this->samplePdf('PolyBag demo manifest'))]);
    }

    /**
     * Sandbox labels are never scanned, so every seeded Package would sit at
     * Pre-Transit and Stuck Pre-Transit would always be orange. Packages shipped
     * more than three days ago are delivered instead; newer ones stay as they are.
     */
    private function markOlderPackagesDelivered(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        // Three delivery lags by package ID, each one statement, not one
        // update per package.
        foreach ([30, 48, 66] as $bucket => $hours) {
            $deliveredAt = $sqlite
                ? DB::raw("datetime(shipped_at, '+{$hours} hours')")
                : DB::raw("shipped_at + interval {$hours} hour");

            DB::table('packages')
                ->where('status', PackageStatus::Shipped->value)
                ->where('shipped_at', '<', now()->subDays(3))
                ->whereRaw('id % 3 = ?', [$bucket])
                ->update([
                    'tracking_status' => TrackingStatus::Delivered->value,
                    'delivered_at' => $deliveredAt,
                    'tracking_updated_at' => $deliveredAt,
                ]);
        }
    }

    /**
     * One finished Label Batch with two failures, so the page is not empty and
     * shows how a failure reads. Its labels are the newest shipped Packages; its
     * failures are open domestic orders.
     */
    private function seedFinishedLabelBatch(): void
    {
        $userId = User::query()->value('id');
        $boxSizeId = BoxSize::query()->active()->whereNull('carrier_packaging')->value('id');
        $packages = Package::query()->where('status', PackageStatus::Shipped)->orderByDesc('shipped_at')->limit(8)->get();
        $failing = Shipment::query()->where('status', ShipmentStatus::Open)->orderByDesc('created_at')->limit(2)->get();

        if ($userId === null || $boxSizeId === null || $packages->isEmpty()) {
            return;
        }

        $completedAt = now()->subHours(2);
        $errors = [
            'The carrier could not validate this address.',
            'The package weight is over the limit for the selected service.',
        ];

        $batch = LabelBatch::create([
            'user_id' => $userId,
            'box_size_id' => $boxSizeId,
            'label_format' => 'pdf',
            'status' => $failing->isEmpty() ? LabelBatchStatus::Completed : LabelBatchStatus::CompletedWithErrors,
            'total_shipments' => $packages->count() + $failing->count(),
            'successful_shipments' => $packages->count(),
            'failed_shipments' => $failing->count(),
            'total_cost' => $packages->sum(fn (Package $package): float => (float) $package->cost),
            'started_at' => $completedAt->copy()->subMinutes(3),
            'completed_at' => $completedAt,
        ]);

        foreach ($packages as $package) {
            LabelBatchItem::create([
                'label_batch_id' => $batch->id,
                'shipment_id' => $package->shipment_id,
                'package_id' => $package->id,
                'status' => LabelBatchItemStatus::Success,
                'tracking_number' => $package->tracking_number,
                'carrier' => $package->carrier,
                'service' => $package->service,
                'cost' => $package->cost,
            ]);
        }

        foreach ($failing->values() as $index => $shipment) {
            LabelBatchItem::create([
                'label_batch_id' => $batch->id,
                'shipment_id' => $shipment->id,
                'status' => LabelBatchItemStatus::Failed,
                'error_message' => $errors[$index % count($errors)],
            ]);
        }

        $this->components->twoColumnDetail('Label batch seeded', "{$packages->count()} labels, {$failing->count()} failed");
    }

    /**
     * A one-page PDF saying `$text`, small enough to store on every Package.
     */
    private function samplePdf(string $text): string
    {
        $stream = "BT /F1 18 Tf 72 700 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * Mark the whole history window exported at the source in one statement, so
     * the live import only picks up genuinely new rows, and its packages
     * exported so no scheduled export run pushes them. Nothing is written back
     * per package; `--full-export` does that.
     */
    private function markHistoryExported(DataSource $record, Carbon $from): int
    {
        DB::table('packages')->where('status', PackageStatus::Shipped->value)->update(['exported' => true]);

        $table = ($record->settings ?? [])['shipments_table'] ?? 'shipments';

        return DB::connection($this->importConnection($record))
            ->table($table)
            ->where('created_at', '>=', $from)
            ->update(['exported' => 'y']);
    }

    /**
     * Runs a phase and says how long it took, so a slow reset shows where.
     *
     * @template T
     *
     * @param  \Closure(): T  $phase
     * @return T
     */
    private function timed(string $label, \Closure $phase): mixed
    {
        $started = microtime(true);
        $result = $phase();

        $this->components->twoColumnDetail("{$label} took", number_format(microtime(true) - $started, 1).'s');

        return $result;
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
     * The shipping method references in the window the history cannot be
     * shipped on, with `(none)` for rows that name none at all: those neither
     * the demo's own labels (DEMO_SHIPPING_REFERENCES, for a method that
     * exists) nor an alias resolves.
     *
     * Resolved as the import resolves each row: against the client its
     * `client_column` names, else the source's client, else the default, and
     * through {@see ImportReferenceResolver}, so an alias counts only for the
     * client it belongs to.
     *
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    private function unplaceableShippingReferences(DataSource $record, Carbon $from, array $mapping): array
    {
        $column = array_search('shipping_method_id', $mapping, true) ?: 'shipping_method';
        $settings = $record->settings ?? [];
        $table = $settings['shipments_table'] ?? 'shipments';
        $clientColumn = $settings['client_column'] ?? null;

        $pairs = DB::connection($this->importConnection($record))
            ->table($table)
            ->where('created_at', '>=', $from)
            ->select($clientColumn ? ["{$column} as reference", "{$clientColumn} as client_name"] : ["{$column} as reference"])
            ->distinct()
            ->get();

        $references = new ImportReferenceResolver;
        $demoMethods = $this->demoShippingMethodIds();
        $unmapped = [];

        foreach ($pairs->sortBy('client_name') as $pair) {
            $reference = (string) ($pair->reference ?? '');

            if ($reference === '') {
                $unmapped['(none)'] = '(none)';

                continue;
            }

            $clientName = $pair->client_name ?? null;
            $client = ($clientName !== null ? Client::findByImportName((string) $clientName) : null)
                ?? $record->client;

            if (! isset($demoMethods[$reference]) && $references->shippingMethodIdFor(['shipping_method_id' => $reference], $client) === null) {
                $label = $clientColumn && $clientName !== null ? "{$reference} ({$clientName})" : $reference;
                $unmapped[$label] = $label;
            }
        }

        return array_values($unmapped);
    }

    /**
     * Demo reference => the ID of the starter method it means, for the methods
     * that exist.
     *
     * @return array<string, int>
     */
    private function demoShippingMethodIds(): array
    {
        $ids = ShippingMethod::query()->whereIn('name', self::DEMO_SHIPPING_REFERENCES)->pluck('id', 'name');

        return collect(self::DEMO_SHIPPING_REFERENCES)
            ->filter(fn (string $name): bool => $ids->has($name))
            ->map(fn (string $name): int => (int) $ids[$name])
            ->all();
    }

    /**
     * Delete every client's alias for a demo reference, mapped by a presenter
     * in the last demo, so this one starts with its open orders unmapped.
     */
    private function removeDemoShippingAliases(): int
    {
        return ShippingMethodAlias::query()
            ->whereIn('reference', array_keys(self::DEMO_SHIPPING_REFERENCES))
            ->delete();
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
        // The warehouse's own boxes only. Carrier packaging is valid with its
        // own carrier alone (ADR-0005), and the reference-data sync seeds it
        // inactive, so drawing from every row put most history in another
        // carrier's box.
        $boxSizeIds = BoxSize::query()->active()->whereNull('carrier_packaging')->pluck('id')->all();
        $userIds = User::pluck('id')->all();
        $timezone = Location::timezone();
        $demoMethods = $this->demoShippingMethodIds();
        $methodNames = ShippingMethod::query()->pluck('name', 'id')->all();

        /** @var array<string, array<string, int>> $manifests carrier => [date => manifest_id] */
        $manifests = [];
        $shipped = 0;

        Shipment::query()
            ->where('data_source_id', $record->id)
            ->where('status', ShipmentStatus::Open)
            ->where('created_at', '<', $cutoff)
            ->with('shipmentItems.product')
            ->chunkById(200, function ($shipments) use (&$manifests, &$shipped, $locationId, $boxSizeIds, $userIds, $timezone, $demoMethods, $methodNames): void {
                DB::transaction(function () use ($shipments, &$manifests, &$shipped, $locationId, $boxSizeIds, $userIds, $timezone, $demoMethods, $methodNames): void {
                    foreach ($shipments as $shipment) {
                        // An alias already resolved it on import; otherwise the
                        // demo label names it. The pre-check left no other case.
                        $methodId = $shipment->shipping_method_id ?? $demoMethods[(string) $shipment->shipping_method_reference] ?? null;
                        $services = self::HISTORY_SERVICES[$methodNames[$methodId] ?? ''] ?? array_merge(...array_values(self::HISTORY_SERVICES));

                        $shippedAt = $this->fabricatePackage($shipment, $services, $manifests, $locationId, $boxSizeIds, $userIds, $timezone);
                        $shipped++;

                        // Shipped by its fabricated Label, so PolyBag set the
                        // status, when that Label shipped (`order-reconciliation/02`).
                        DB::table('shipments')->where('id', $shipment->id)->update([
                            'status' => ShipmentStatus::Shipped->value,
                            'status_origin' => ShipmentStatusOrigin::PolyBag->value,
                            'status_changed_at' => $shippedAt->format('Y-m-d H:i:s'),
                            // Set directly, as a method picked by hand would be,
                            // so no alias exists for the open orders to resolve.
                            'shipping_method_id' => $methodId,
                            'checked' => true,
                            'deliverability' => 'yes',
                        ]);
                    }
                });
            });

        // Stamp package counts on the fabricated manifests.
        Manifest::query()->get()->each(fn (Manifest $manifest) => $manifest->update([
            'package_count' => $manifest->packages()->count(),
        ]));

        return $shipped;
    }

    /**
     * @param  list<array{carrier: string, code: string, name: string, weight: int, costMin: float, costMax: float}>  $services  The shipment's method's services
     * @param  array<string, array<string, int>>  $manifests
     * @param  array<int, int>  $boxSizeIds
     * @param  array<int, int>  $userIds
     */
    private function fabricatePackage(Shipment $shipment, array $services, array &$manifests, ?int $locationId, array $boxSizeIds, array $userIds, string $timezone): CarbonInterface
    {
        $service = $this->pickService($services);
        $carrier = $service['carrier'];

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
            'label_orientation' => 'portrait',
            'label_format' => 'pdf',
            'label_dpi' => 203,
            'cost' => round(mt_rand((int) ($service['costMin'] * 100), (int) ($service['costMax'] * 100)) / 100, 2),
            'postage_source' => PostageSource::CarrierAccount->value,
            'ship_date' => $shipDate,
            'shipped_at' => $shippedAt->format('Y-m-d H:i:s'),
            'shipped_by_user_id' => $userIds === [] ? null : $userIds[array_rand($userIds)],
            'manifest_id' => $this->manifestIdFor($manifests, $carrier, $shipDate, $locationId),
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
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $shippedAt->format('Y-m-d H:i:s'),
        ]);

        // The bought rate first, so the Label can point at the quote it was
        // bought from, as Rate Comparison reads it.
        [$boughtQuote, $otherQuotes] = $this->fabricateRateQuotes($packageId, $services, $service, (float) $projected['cost'], $shippedAt);
        $rateQuoteId = DB::table('rate_quotes')->insertGetId($boughtQuote);
        DB::table('rate_quotes')->insert($otherQuotes);

        DB::table('package_labels')->insert(PackageLabel::projectionFrom($projected) + [
            'package_id' => $packageId,
            'rate_quote_id' => $rateQuoteId,
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

        return $shippedAt;
    }

    /**
     * The quotes the packer chose from: the bought rate, selected, and up to
     * three other services of the same method, as rating would offer, so Rate
     * Comparison has history to show. One quote session: every row shares its
     * `created_at`.
     *
     * @param  list<array{carrier: string, code: string, name: string, weight: int, costMin: float, costMax: float}>  $services
     * @param  array{carrier: string, code: string, name: string, weight: int, costMin: float, costMax: float}  $bought
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} The bought quote, then the others
     */
    private function fabricateRateQuotes(int $packageId, array $services, array $bought, float $cost, CarbonInterface $shippedAt): array
    {
        $quotedAt = $shippedAt->copy()->subMinutes(mt_rand(1, 10))->format('Y-m-d H:i:s');
        $carrier = $bought['carrier'];

        $alternatives = collect($services)
            ->reject(fn (array $service): bool => $service['carrier'] === $carrier && $service['code'] === $bought['code'])
            ->shuffle()
            ->take(mt_rand(1, 3));

        $others = $alternatives
            ->map(fn (array $service): array => [
                'package_id' => $packageId,
                'carrier' => $service['carrier'],
                'service_code' => $service['code'],
                'service_name' => $service['name'],
                'quoted_price' => round(mt_rand((int) ($service['costMin'] * 100), (int) ($service['costMax'] * 100)) / 100, 2),
                'selected' => false,
                'created_at' => $quotedAt,
            ])
            ->values()
            ->all();

        return [[
            'package_id' => $packageId,
            'carrier' => $carrier,
            'service_code' => $bought['code'],
            'service_name' => $bought['name'],
            'quoted_price' => $cost,
            'selected' => true,
            'created_at' => $quotedAt,
        ], $others];
    }

    /**
     * Give each client and the warehouse's boxes sample fees where none are
     * set, so Client Billing has something to bill. A fee someone entered is
     * kept.
     */
    private function ensureBillingRates(): void
    {
        Client::query()->each(function (Client $client): void {
            $client->update(array_filter([
                'pick_fee_first_item' => $client->pick_fee_first_item === null ? 1.25 : null,
                'pick_fee_additional_item' => $client->pick_fee_additional_item === null ? 0.35 : null,
                'label_fee_per_package' => $client->label_fee_per_package === null ? 0.50 : null,
            ], fn (?float $fee): bool => $fee !== null));
        });

        BoxSize::query()
            ->whereNull('carrier_packaging')
            ->whereNull('materials_cost')
            ->update(['materials_cost' => 0.40]);
    }

    /**
     * Historical packages of a carrier that runs a manifest program (USPS) belong
     * to a manifest, as End of Day would have left them. Other carriers' packages
     * have none, because PolyBag cannot create one for them; End of Day only counts
     * unmanifested packages for carriers that support manifests, so they do not
     * pile up there.
     *
     * @param  array<string, array<string, int>>  $manifests
     */
    private function manifestIdFor(array &$manifests, string $carrier, string $shipDate, ?int $locationId): ?int
    {
        if (! (app(CarrierRegistry::class)->policyFor($carrier)?->supportsCarrierManifest() ?? false)) {
            return null;
        }

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

        $bar = $this->output->createProgressBar((clone $packages)->count());

        // One batch, so a tunnelled import database opens one SSH tunnel for
        // every Package rather than one each.
        return $exportService->batch(function () use ($exportService, $packages, $bar): int {
            $succeeded = 0;
            $consecutiveFailures = 0;

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
                        $this->warn('Fix the export configuration and re-run, or drop --full-export.');

                        return $succeeded;
                    }
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            return $succeeded;
        });
    }

    /**
     * @param  list<array{carrier: string, code: string, name: string, weight: int, costMin: float, costMax: float}>  $services
     * @return array{carrier: string, code: string, name: string, weight: int, costMin: float, costMax: float}
     */
    private function pickService(array $services): array
    {
        $roll = mt_rand(1, array_sum(array_column($services, 'weight')));
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
            Carrier::USPS => '94'.$this->randomDigits(20),
            Carrier::FEDEX => $this->randomDigits(12),
            Carrier::UPS => '1Z'.$this->randomAlphanumeric(6).$this->randomDigits(10),
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
