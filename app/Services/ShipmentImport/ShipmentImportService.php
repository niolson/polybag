<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ImportSourceInterface;
use App\Events\ImportCompleted;
use App\Events\ShipmentImported;
use App\Events\ShipmentUpdated;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\PhoneParserService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShipmentImportService
{
    private ImportSourceInterface $source;

    private array $stats;

    private array $errors;

    /** @var array<string, int> */
    private array $channelCache = [];

    /** @var array<string, ?int> */
    private array $shippingMethodCache = [];

    /** @var array<string, int> */
    private array $productCache = [];

    public function __construct(ImportSourceInterface $source)
    {
        $this->source = $source;
        $this->resetStats();
    }

    /**
     * Static factory method
     */
    public static function forSource(ImportSourceInterface $source): self
    {
        return new self($source);
    }

    /**
     * Run the import process
     */
    public function import(): ImportResult
    {
        $startTime = microtime(true);

        try {
            $this->source->validateConfiguration();
        } catch (\Exception $e) {
            return new ImportResult(
                errors: [$e->getMessage()],
                duration: microtime(true) - $startTime
            );
        }

        $this->warmCaches();

        $shipments = $this->source->fetchShipments();

        $this->log('info', "Starting import from {$this->source->getSourceName()}", [
            'shipment_count' => $shipments->count(),
        ]);

        $batchSize = config('shipment-import.behavior.batch_size', 100);

        Shipment::withoutSyncingToSearch(function () use ($shipments, $batchSize): void {
            $shipments->chunk($batchSize)->each(function (Collection $batch): void {
                DB::transaction(function () use ($batch): void {
                    $this->importBatch($batch);
                });
            });
        });

        $duration = microtime(true) - $startTime;

        $this->log('info', 'Import completed', array_merge($this->stats, ['duration' => $duration]));

        ImportCompleted::dispatch($this->stats, $this->source->getSourceName());

        return new ImportResult(
            shipmentsCreated: $this->stats['shipments_created'],
            shipmentsUpdated: $this->stats['shipments_updated'],
            itemsCreated: $this->stats['items_created'],
            itemsUpdated: $this->stats['items_updated'],
            productsCreated: $this->stats['products_created'],
            productsUpdated: $this->stats['products_updated'],
            shipmentsExported: $this->stats['shipments_exported'],
            errors: $this->errors,
            duration: $duration
        );
    }

    /**
     * Pre-load lookup tables into memory to avoid per-shipment queries.
     */
    private function warmCaches(): void
    {
        // Cache all channel aliases
        ChannelAlias::all()->each(function (ChannelAlias $alias): void {
            $this->channelCache[$alias->reference] = $alias->channel_id;
        });

        // Cache all channels by reference and by ID
        Channel::all()->each(function (Channel $channel): void {
            if ($channel->channel_reference) {
                $this->channelCache[$channel->channel_reference] = $channel->id;
            }
            $this->channelCache[(string) $channel->id] = $channel->id;
        });

        // Cache all shipping method aliases
        ShippingMethodAlias::all()->each(function (ShippingMethodAlias $alias): void {
            $this->shippingMethodCache[$alias->reference] = $alias->shipping_method_id;
        });

        // Cache shipping method IDs for numeric lookups
        ShippingMethod::pluck('id')->each(function (int $id): void {
            $this->shippingMethodCache[(string) $id] = $id;
        });

        // Cache all products by SKU
        Product::pluck('id', 'sku')->each(function (int $id, string $sku): void {
            $this->productCache[$sku] = $id;
        });
    }

    /**
     * Import a batch of shipments using bulk upsert.
     */
    private function importBatch(Collection $batch): void
    {
        $now = now();
        $preparedRows = [];
        $validDataByRef = [];

        // Phase 1: Validate and prepare all shipment data as arrays
        foreach ($batch as $data) {
            try {
                $prepared = $this->prepareShipmentData($data);
                if ($prepared === null) {
                    continue;
                }

                $prepared['created_at'] = $now;
                $prepared['updated_at'] = $now;
                $preparedRows[] = $prepared;
                $validDataByRef[$data['shipment_reference']] = $data;
            } catch (\Exception $e) {
                $this->errors[] = "Error importing shipment {$data['shipment_reference']}: ".$e->getMessage();

                $this->log('error', 'Import error', [
                    'shipment_reference' => $data['shipment_reference'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($preparedRows)) {
            return;
        }

        // Track which references already exist for event dispatching
        $references = array_column($preparedRows, 'shipment_reference');
        $existingRefs = Shipment::whereIn('shipment_reference', $references)
            ->pluck('shipment_reference')
            ->all();

        // Phase 2: Split into mapped (channel resolved) and unmapped (channel unknown)
        $mappedRows = array_filter($preparedRows, fn ($row) => $row['channel_id'] !== null);
        $unmappedRows = array_filter($preparedRows, fn ($row) => $row['channel_id'] === null);

        $updateColumns = [
            'first_name', 'last_name', 'company',
            'address1', 'address2', 'city', 'state_or_province', 'postal_code', 'country',
            'phone', 'phone_extension', 'email', 'value',
            'validation_message', 'shipping_method_reference', 'shipping_method_id',
            'channel_reference', 'deliver_by', 'metadata', 'updated_at',
        ];

        // Phase 2a: Upsert mapped rows using composite unique key
        if (! empty($mappedRows)) {
            $mappedRows = array_values($mappedRows);
            $existingMappedCount = Shipment::where(function ($query) use ($mappedRows): void {
                foreach ($mappedRows as $row) {
                    $query->orWhere(function ($q) use ($row): void {
                        $q->where('channel_id', $row['channel_id'])
                            ->where('shipment_reference', $row['shipment_reference']);
                    });
                }
            })->count();

            Shipment::upsert($mappedRows, ['channel_id', 'shipment_reference'], $updateColumns);

            $this->stats['shipments_created'] += count($mappedRows) - $existingMappedCount;
            $this->stats['shipments_updated'] += $existingMappedCount;
        }

        // Phase 2b: Handle unmapped rows individually (can't use composite upsert with null channel_id)
        foreach ($unmappedRows as $row) {
            $existing = Shipment::where('shipment_reference', $row['shipment_reference'])
                ->where('channel_reference', $row['channel_reference'])
                ->whereNull('channel_id')
                ->first();

            if ($existing) {
                $existing->update($row);
                $this->stats['shipments_updated']++;
            } else {
                Shipment::create($row);
                $this->stats['shipments_created']++;
            }
        }

        // Phase 3: Fetch shipment IDs for item processing
        $references = array_column($preparedRows, 'shipment_reference');
        $shipmentMap = Shipment::whereIn('shipment_reference', $references)
            ->pluck('id', 'shipment_reference');

        // Phase 4: Import items and mark exported
        foreach ($validDataByRef as $reference => $data) {
            $shipmentId = $shipmentMap[$reference] ?? null;
            if (! $shipmentId) {
                continue;
            }

            $this->importShipmentItems($shipmentId, $reference);

            $shipment = Shipment::find($shipmentId);
            if ($shipment) {
                if (in_array($reference, $existingRefs, true)) {
                    ShipmentUpdated::dispatch($shipment);
                } else {
                    ShipmentImported::dispatch($shipment);
                }
            }

            try {
                $this->source->markExported($reference);
                $this->stats['shipments_exported']++;
            } catch (\Exception $e) {
                $this->errors[] = "Error marking shipment {$reference} as exported: ".$e->getMessage();

                $this->log('warning', 'Failed to mark shipment as exported', [
                    'shipment_reference' => $reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Validate and prepare a single shipment's data for upsert.
     *
     * @return array<string, mixed>|null Prepared data array, or null if validation failed
     */
    private function prepareShipmentData(array $data): ?array
    {
        ['errors' => $validationErrors, 'warnings' => $validationWarnings] = $this->validateShipmentData($data);
        if (! empty($validationErrors)) {
            $this->errors[] = "Validation errors for shipment {$data['shipment_reference']}: ".implode(', ', $validationErrors);

            return null;
        }

        // Parse phone number using libphonenumber
        $phoneExtension = $data['phone_extension'] ?? null;
        if (! empty($data['phone'])) {
            $phoneResult = PhoneParserService::parse($data['phone']);
            if ($phoneResult->isValid()) {
                $data['phone'] = $phoneResult->phone;
                if ($phoneExtension === null && $phoneResult->extension !== null) {
                    $phoneExtension = $phoneResult->extension;
                }
            } else {
                $validationWarnings[] = "Invalid phone number removed: {$data['phone']}";
                $data['phone'] = null;
            }
        }

        // Clear invalid email
        if (! empty($validationWarnings)) {
            foreach ($validationWarnings as $warning) {
                if (str_contains($warning, 'email')) {
                    $data['email'] = null;
                }
            }
        }

        return [
            'shipment_reference' => $data['shipment_reference'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'company' => $data['company'] ?? null,
            'address1' => $data['address1'] ?? null,
            'address2' => $data['address2'] ?? null,
            'city' => $data['city'] ?? null,
            'state_or_province' => $data['state_or_province'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? 'US',
            'phone' => $data['phone'] ?? null,
            'phone_extension' => $phoneExtension,
            'email' => $data['email'] ?? null,
            'value' => $data['value'] ?? null,
            'validation_message' => ! empty($validationWarnings) ? implode('; ', $validationWarnings) : null,
            'shipping_method_reference' => $data['shipping_method_id'] ?? null,
            'shipping_method_id' => $this->resolveShippingMethodId($data),
            'channel_reference' => $data['channel_id'] ?? null,
            'channel_id' => $this->resolveChannelId($data),
            'deliver_by' => $data['deliver_by'] ?? null,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ];
    }

    private function importShipmentItems(int $shipmentId, string $shipmentReference): void
    {
        $items = $this->source->fetchShipmentItems($shipmentReference);

        foreach ($items as $itemData) {
            $productId = $this->resolveProductId($itemData);

            if (! $productId) {
                continue;
            }

            $shipmentItem = ShipmentItem::updateOrCreate(
                [
                    'shipment_id' => $shipmentId,
                    'product_id' => $productId,
                ],
                [
                    'barcode' => $itemData['barcode'] ?? null,
                    'quantity' => $itemData['quantity'] ?? 1,
                    'value' => $itemData['value'] ?? null,
                    'description' => $itemData['description'] ?? null,
                    'transparency' => $itemData['transparency'] ?? false,
                ]
            );

            if ($shipmentItem->wasRecentlyCreated) {
                $this->stats['items_created']++;
            } else {
                $this->stats['items_updated']++;
            }
        }
    }

    private function resolveShippingMethodId(array $data): ?int
    {
        $reference = $data['shipping_method_id'] ?? null;

        if (! $reference) {
            return null;
        }

        return $this->shippingMethodCache[(string) $reference] ?? null;
    }

    private function resolveChannelId(array $data): ?int
    {
        $reference = $data['channel_id'] ?? null;

        if (! $reference) {
            return null;
        }

        return $this->channelCache[(string) $reference] ?? null;
    }

    private function resolveProductId(array $itemData): ?int
    {
        $sku = $itemData['sku'] ?? null;

        if (! $sku) {
            return null;
        }

        // Auto-create or update product if enabled
        if (config('shipment-import.behavior.auto_update_products', true)) {
            $updateData = array_filter([
                'name' => $itemData['name'] ?? $sku,
                'description' => $itemData['description'] ?? null,
                'barcode' => $itemData['barcode'] ?? null,
                'weight' => $itemData['weight'] ?? null,
            ], fn ($value) => $value !== null);

            $product = Product::updateOrCreate(
                ['sku' => $sku],
                array_merge($updateData, ['active' => true])
            );

            $this->productCache[$sku] = $product->id;

            if ($product->wasRecentlyCreated) {
                $this->stats['products_created']++;
            } elseif ($product->wasChanged()) {
                $this->stats['products_updated']++;
            }

            return $product->id;
        }

        // Return from cache if available (auto-update disabled)
        if (isset($this->productCache[$sku])) {
            return $this->productCache[$sku];
        }

        return null;
    }

    private function resetStats(): void
    {
        $this->stats = [
            'shipments_created' => 0,
            'shipments_updated' => 0,
            'items_created' => 0,
            'items_updated' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'shipments_exported' => 0,
        ];
        $this->errors = [];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $channel = config('shipment-import.logging.channel', 'stack');
        Log::channel($channel)->log($level, $message, $context);
    }

    /**
     * Validate shipment data before import.
     *
     * Returns blocking errors (prevent import) and warnings (import proceeds with note).
     * Postal code and state/province checks are warnings, not errors, to allow
     * international orders to import even with missing data.
     *
     * @return array{errors: array<string>, warnings: array<string>}
     */
    private function validateShipmentData(array $data): array
    {
        $errors = [];
        $warnings = [];

        // Required fields
        if (empty($data['shipment_reference'])) {
            $errors[] = 'Missing shipment reference';
        }

        if (empty($data['address1'])) {
            $errors[] = 'Missing address line 1';
        }

        if (empty($data['city'])) {
            $errors[] = 'Missing city';
        }

        $country = $data['country'] ?? 'US';

        // Postal code: warn if missing for US, validate format if present
        if (empty($data['postal_code'])) {
            if ($country === 'US') {
                $warnings[] = 'Missing postal code';
            }
        } elseif ($country === 'US') {
            $zip = preg_replace('/[^0-9]/', '', $data['postal_code']);
            if (strlen($zip) !== 5 && strlen($zip) !== 9) {
                $warnings[] = 'Invalid US postal code format';
            }
        }

        // State/province: warn if missing for US/CA, validate code if present for US
        if (empty($data['state_or_province'])) {
            if (in_array($country, ['US', 'CA'], true)) {
                $warnings[] = 'Missing state/province';
            }
        } elseif ($country === 'US') {
            $validStates = [
                'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
                'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
                'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
                'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
                'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
                'DC', 'PR', 'VI', 'GU', 'AS', 'MP', 'AA', 'AE', 'AP',
            ];
            $state = strtoupper(trim($data['state_or_province']));
            if (strlen($state) === 2 && ! in_array($state, $validStates, true)) {
                $warnings[] = "Invalid US state code: {$state}";
            }
        }

        // Validate email format if provided (warning only - does not block import)
        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $warnings[] = "Invalid email removed: {$data['email']}";
        }

        // Validate value if provided
        if (isset($data['value']) && $data['value'] !== null) {
            if (! is_numeric($data['value']) || $data['value'] < 0) {
                $errors[] = 'Invalid shipment value (must be a positive number)';
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
