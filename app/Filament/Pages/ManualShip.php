<?php

namespace App\Filament\Pages;

use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PackageLabelWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\ManualShip\ManualShipLine;
use App\DataTransferObjects\PackageDrafts\Measurements;
use App\DataTransferObjects\PackageDrafts\PackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftItemInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftOptions;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PrintRequest;
use App\Enums\Deliverability;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Enums\ValidationTrigger;
use App\Exceptions\PackageDraftIncompleteException;
use App\Exceptions\PackageDraftInvalidException;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Filament\Support\AddressForm;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Services\AddressValidationService;
use App\Services\ClientContext;
use App\Services\ManualShipContents;
use App\Services\PackageLabels\SessionLastLabel;
use App\Services\PackagingService;
use App\Services\PostageSources\OfferStore;
use App\Services\SettingsService;
use BackedEnum;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use UnitEnum;

class ManualShip extends Page implements HasForms
{
    use HasUnsavedDataChangesAlert;
    use InteractsWithForms;
    use NotifiesUser;
    use PrintsLabels;

    public ?array $data = [];

    public bool $autoShipEnabled = false;

    public bool $scanToAddEnabled = false;

    /**
     * Whether the form takes the parcel's items (`manual-ship-contents/02`).
     */
    #[Locked]
    public bool $itemsEnabled = false;

    /**
     * The Draft Shipment's currency, the default Client's, which item values are entered in.
     */
    #[Locked]
    public string $currency = Shipment::DEFAULT_CURRENCY;

    private ?int $catalogClientId = null;

    public string $labelFormat = 'pdf';

    public ?int $labelDpi = null;

    public bool $hasReportPrinter = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Manual Ship';

    protected static UnitEnum|string|null $navigationGroup = 'Ship';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.manual-ship';

    protected static ?string $slug = 'manual-ship';

    protected ?string $heading = 'Manual Ship';

    public static function canAccess(): bool
    {
        return (auth()->user()?->role->isAtLeast(Role::Manager) ?? false)
            && app(SettingsService::class)->get('manual_shipping_enabled', true);
    }

    public function mount(): void
    {
        $this->autoShipEnabled = (bool) auth()->user()->auto_ship_enabled;
        $this->scanToAddEnabled = (bool) app(SettingsService::class)->get('scan_to_add_enabled', false);
        $this->itemsEnabled = app(SettingsService::class)->manualShipItemsEnabled();
        if ($this->itemsEnabled) {
            $this->currency = app(ClientContext::class)->default()->currency;
        }

        $this->form->fill([
            'country' => 'US',
            'label_format' => 'pdf',
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                ])
                    ->schema([
                        Section::make('Recipient & Address')
                            ->description('Enter the destination details for this manual shipment.')
                            ->schema([
                                ...AddressForm::recipientAddressFields(
                                    includeCompany: true,
                                    includePhone: true,
                                    includeEmail: true,
                                ),
                            ])
                            ->columns(2),
                        Grid::make(1)
                            ->schema([
                                Section::make('Package')
                                    ->description('Choose a saved box and confirm the measured package dimensions.')
                                    ->schema([
                                        Forms\Components\Select::make('box_size_id')
                                            ->label('Box Size')
                                            ->options(fn (): array => $this->getBoxSizeOptions())
                                            ->searchable()
                                            ->native(false)
                                            ->placeholder('Custom')
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                                if (! $state) {
                                                    return;
                                                }

                                                $box = BoxSize::find($state);

                                                if (! $box) {
                                                    return;
                                                }

                                                $set('height', (string) $box->height);
                                                $set('width', (string) $box->width);
                                                $set('length', (string) $box->length);
                                            }),
                                        Forms\Components\TextInput::make('weight')
                                            ->label(new HtmlString(
                                                'Weight <span x-show="scaleConnected && !scaleStable" x-cloak class="ml-1 text-xs font-normal text-warning-500">In motion...</span>'
                                            ))
                                            ->suffix('lbs')
                                            ->helperText('Auto-fills from the connected scale when stable.')
                                            ->required()
                                            ->numeric()
                                            ->minValue(0.01)
                                            ->step(0.01)
                                            ->extraInputAttributes([
                                                'x-bind:style' => "scaleConnected && !scaleStable ? 'color: var(--warning-500)' : ''",
                                            ]),
                                        Grid::make(3)
                                            ->schema([
                                                Forms\Components\TextInput::make('height')
                                                    ->label('Height')
                                                    ->suffix('in')
                                                    ->required()
                                                    ->numeric()
                                                    ->minValue(0.01)
                                                    ->step(0.01),
                                                Forms\Components\TextInput::make('width')
                                                    ->label('Width')
                                                    ->suffix('in')
                                                    ->required()
                                                    ->numeric()
                                                    ->minValue(0.01)
                                                    ->step(0.01),
                                                Forms\Components\TextInput::make('length')
                                                    ->label('Length')
                                                    ->suffix('in')
                                                    ->required()
                                                    ->numeric()
                                                    ->minValue(0.01)
                                                    ->step(0.01),
                                            ])
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                                Section::make('Shipment Details')
                                    ->schema([
                                        Forms\Components\TextInput::make('shipment_reference')
                                            ->label('Reference')
                                            ->helperText('Optional. The reference number from the external channel.')
                                            ->maxLength(255),
                                        Forms\Components\Select::make('shipping_method_id')
                                            ->label('Shipping Method')
                                            ->options(fn (): array => $this->getShippingMethodOptions())
                                            ->searchable()
                                            ->native(false)
                                            // Nothing is bought without one (`carrier-catalog-reset/16`).
                                            ->required()
                                            ->rules(['exists:shipping_methods,id']),
                                        Forms\Components\TextInput::make('value')
                                            ->label('Value')
                                            ->numeric()
                                            ->inputMode('decimal')
                                            ->step(0.01)
                                            ->minValue(0)
                                            ->maxValue(999999.99)
                                            ->suffix(fn (): string => $this->currency)
                                            ->helperText('Optional. Leave blank to total the items.')
                                            ->visible(fn (): bool => $this->itemsEnabled),
                                    ])
                                    ->columns(2),
                            ]),
                    ]),
                $this->itemsSection(),
            ])
            ->statePath('data');
    }

    /**
     * The parcel's items, each picked from the catalog or typed in. A catalog
     * line shows its Product's details and declares them; only its quantity
     * and value are its own.
     */
    private function itemsSection(): Section
    {
        $isCatalogLine = fn (Get $get): bool => filled($get('product_id'));
        $isFreehandLine = fn (Get $get): bool => blank($get('product_id'));

        return Section::make('Items')
            ->description('What is in the parcel. Pick an item from the product catalog, or type one in and it is added to the catalog. Values are per unit, in '.$this->currency.'.')
            ->visible(fn (): bool => $this->itemsEnabled)
            ->columnSpanFull()
            ->schema([
                Forms\Components\Repeater::make('items')
                    ->hiddenLabel()
                    ->default([])
                    ->addActionLabel('Add item')
                    ->reorderable(false)
                    ->table([
                        TableColumn::make('Catalog Product'),
                        TableColumn::make('Description')->markAsRequired(),
                        TableColumn::make('SKU')->markAsRequired(),
                        TableColumn::make('Qty')->markAsRequired(),
                        TableColumn::make('Unit Value ('.$this->currency.')'),
                        TableColumn::make('Weight (lbs)'),
                        TableColumn::make('HS Code'),
                        TableColumn::make('Origin'),
                    ])
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->placeholder('Typed in')
                            ->searchable()
                            ->native(false)
                            ->distinct()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchCatalog($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->catalogLabel($value))
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $product = filled($state) ? $this->catalogProductQuery()->find($state) : null;

                                // A cleared pick leaves an empty line to type, not the Product's SKU,
                                // which a typed-in line would be refused for.
                                if (! $product instanceof Product) {
                                    foreach (['description', 'sku', 'weight', 'hs_tariff_number', 'country_of_origin', 'value'] as $field) {
                                        $set($field, null);
                                    }

                                    return;
                                }

                                $set('description', $product->description ?? $product->name);
                                $set('sku', $product->sku);
                                $set('weight', $product->weight);
                                $set('hs_tariff_number', $product->hs_tariff_number);
                                $set('country_of_origin', $product->country_of_origin);
                                // Copied only in the Client's currency, never converted (`manual-ship-contents/01`).
                                $set('value', $product->customsValueIn($this->currency));
                            }),
                        Forms\Components\TextInput::make('description')
                            ->required($isFreehandLine)
                            ->disabled($isCatalogLine)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('sku')
                            ->required($isFreehandLine)
                            ->disabled($isCatalogLine)
                            ->maxLength(255)
                            ->distinct()
                            ->rules(fn (Get $get): array => [
                                function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (blank($get('product_id'))
                                        && ($message = ManualShipContents::catalogConflict($this->catalogClientId(), (string) $value)) !== null) {
                                        $fail($message);
                                    }
                                },
                            ]),
                        Forms\Components\TextInput::make('quantity')
                            ->required()
                            ->integer()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(10000),
                        Forms\Components\TextInput::make('value')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(999999.99),
                        Forms\Components\TextInput::make('weight')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(999999.99)
                            ->disabled($isCatalogLine),
                        Forms\Components\TextInput::make('hs_tariff_number')
                            ->maxLength(20)
                            ->disabled($isCatalogLine),
                        Forms\Components\TextInput::make('country_of_origin')
                            ->maxLength(2)
                            ->minLength(2)
                            ->alpha()
                            ->placeholder('US')
                            ->disabled($isCatalogLine),
                    ]),
            ]);
    }

    /**
     * @return Builder<Product>
     */
    private function catalogProductQuery(): Builder
    {
        return Product::query()->where('client_id', $this->catalogClientId());
    }

    /**
     * The default Client, whose catalog the lines come from, resolved once a request.
     */
    private function catalogClientId(): int
    {
        return $this->catalogClientId ??= app(ClientContext::class)->default()->id;
    }

    /**
     * @return array<int, string>
     */
    private function searchCatalog(string $search): array
    {
        return $this->catalogProductQuery()
            ->where('active', true)
            ->where(function ($query) use ($search): void {
                $pattern = '%'.addcslashes($search, '\\%_').'%';

                $query->where('sku', 'like', $pattern)
                    ->orWhere('barcode', 'like', $pattern)
                    ->orWhere('name', 'like', $pattern);
            })
            ->orderBy('sku')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Product $product): array => [$product->id => self::productLabel($product)])
            ->all();
    }

    private function catalogLabel(mixed $value): ?string
    {
        $product = $this->catalogProductQuery()->find($value);

        return $product instanceof Product ? self::productLabel($product) : null;
    }

    private static function productLabel(Product $product): string
    {
        return implode(' — ', array_filter([$product->sku, $product->name], filled(...)));
    }

    public function ship(): void
    {
        $data = $this->form->getState();

        if (! $this->validateBusinessRules($data)) {
            return;
        }

        $this->autoShipEnabled = (bool) auth()->user()->auto_ship_enabled;

        try {
            if ($this->scanToAddEnabled) {
                $this->packFromManual($data);

                return;
            }

            if ($this->autoShipEnabled) {
                if ($this->refusesForLabelPrinter()) {
                    return;
                }

                $this->autoShip($data);

                return;
            }

            $this->manualShip($data);
        } catch (PackageDraftIncompleteException|PackageDraftInvalidException $e) {
            $this->notifyError('Not Ready', $e->getMessage());
        }
    }

    private function packFromManual(array $data): void
    {
        // Pack checks the items as the packer scans them, so the draft starts empty.
        ['shipment' => $shipment] = $this->createShipmentAndPackage($data, packItems: false);

        Session::put('pack_scan_to_add_override', true);
        Session::put('ship_return_url', '/manual-ship');
        $this->redirect('/pack/'.$shipment->id);
    }

    private function manualShip(array $data): void
    {
        ['shipment' => $shipment, 'package' => $package] = $this->createShipmentAndPackage($data);

        Session::put('ship_return_url', '/manual-ship');
        $this->redirect('/ship/'.$package->id);
    }

    private function autoShip(array $data): void
    {
        ['shipment' => $shipment, 'package' => $package, 'items' => $items] = $this->createShipmentAndPackage($data);

        $result = app(PackageShippingWorkflow::class)->autoShip(
            $package,
            new PackageAutoShippingRequest(
                labelFormat: $this->labelFormat,
                labelDpi: $this->labelDpi,
                userId: auth()->id(),
                hasReportPrinter: $this->hasReportPrinter,
            ),
        );

        if (! $result->success) {
            // The purchase never reported back, so a label may exist. Resubmitting
            // this form would make a new package and buy again; the package's own
            // Ship page asks the carrier first (`project-review/01`).
            if (app(OfferStore::class)->hasUnresolvedPurchase($package)) {
                Session::put('ship_return_url', '/manual-ship');
                $this->notifyWarning(
                    $result->title ?? 'Purchase Unconfirmed',
                    ($result->message ?? 'The carrier did not confirm the purchase.')
                    .' A label may already exist, so continue on this package\'s Ship page, which checks with the carrier before buying again.',
                );
                $this->redirect('/ship/'.$package->id);

                return;
            }

            if ($result->requiresAttendedSelection) {
                Session::put('ship_return_url', '/manual-ship');
                $this->notifyWarning($result->title ?? 'Attended Shipping Required', $result->message);
                $this->redirect('/ship/'.$package->id);

                return;
            }

            $this->pickRecordedProducts($items);
            $this->notifyError($result->title ?? 'Shipping Error', $result->message ?? 'Unable to ship package.');
            // A label bought but not recorded is also sent to the bell; show it now, not at the next poll.
            $this->dispatch('databaseNotificationsSent');

            return;
        }

        app(SessionLastLabel::class)->remember($package);

        if ($result->response->labelData) {
            $this->dispatchPrint(PrintRequest::fromShipResponse($result->response, $package));
        }

        $this->notifySuccess('Label Bought', $result->summaryMessage());
        $this->resetForm();
    }

    /**
     * Create a draft shipment and its package, with optional address validation.
     * The shipment is a draft until a label is bought, so a packer who only
     * wants a price, or backs out, leaves nothing on the Shipments list.
     * Returns both so callers can reference the shipment (e.g. for cleanup on failure).
     *
     * Items entered on the form become the Shipment's items. With `$packItems`
     * the package holds every one at its full quantity: no one scans them after,
     * so the person who entered them has declared the parcel.
     *
     * @param  array<string, mixed>  $data
     * @return array{shipment: Shipment, package: Package, items: array<array-key, ShipmentItem>}
     *
     * @throws ValidationException When an item cannot be recorded
     */
    private function createShipmentAndPackage(array $data, bool $packItems = true): array
    {
        return DB::transaction(function () use ($data, $packItems): array {
            $channelId = Channel::where('name', 'Manual')->first()?->id;
            $shipment = app(PackagingService::class)->createShipment($data, $channelId, ShipmentStatus::Draft);
            $shipmentItems = $this->recordItems($shipment, $data);

            try {
                app(AddressValidationService::class)->validate($shipment, ValidationTrigger::Manual);
                $shipment->refresh();
                $source = $shipment->validation_source?->getLabel();

                if (in_array($shipment->deliverability, Deliverability::confirmed(), true)) {
                    $this->notifyInfo(
                        'Address '.mb_strtolower($shipment->deliverability->getLabel()),
                        $source !== null ? "Checked by {$source}." : null,
                    );
                } elseif ($shipment->deliverability !== Deliverability::NotChecked) {
                    $message = $shipment->validation_message ?? 'Address may not be deliverable.';
                    $this->notifyWarning('Address Warning', $source !== null ? "{$source}: {$message}" : $message);
                }
            } catch (\Exception $e) {
                logger()->warning('ManualShip address validation failed', ['error' => $e->getMessage()]);
            }

            $options = new PackageDraftOptions(requireCompletePackedItems: false);
            $draft = app(PackageDraftWorkflow::class)->saveForShipment(
                shipment: $shipment,
                input: new PackageDraftInput(
                    measurements: new Measurements($data['weight'], $data['height'], $data['width'], $data['length']),
                    boxSizeId: $data['box_size_id'] ?: null,
                    items: $packItems ? array_map(fn (ShipmentItem $item): PackageDraftItemInput => new PackageDraftItemInput(
                        shipmentItemId: $item->id,
                        productId: $item->product_id,
                        quantity: $item->quantity,
                    ), array_values($shipmentItems)) : [],
                ),
                options: $options,
            );
            $ready = app(PackageDraftWorkflow::class)->assertReadyToShip(
                shipment: $shipment,
                packageDraftId: $draft->packageDraftId,
                options: $options,
            );

            return ['shipment' => $shipment, 'package' => $ready->package, 'items' => $shipmentItems];
        });
    }

    /**
     * Records the items, and the value entered with or without them.
     *
     * @param  array<string, mixed>  $data
     * @return array<array-key, ShipmentItem> By the form row each came from
     *
     * @throws ValidationException When an item cannot be recorded
     */
    private function recordItems(Shipment $shipment, array $data): array
    {
        if (! $this->itemsEnabled) {
            return [];
        }

        $rows = $data['items'] ?? [];
        $items = app(ManualShipContents::class)->record(
            $shipment,
            array_map(ManualShipLine::fromFormState(...), array_values($rows)),
            filled($data['value'] ?? null) ? (float) $data['value'] : null,
        );

        return array_combine(array_keys($rows), $items);
    }

    /**
     * After a failed purchase the form stays for another try, and a typed-in
     * line's Product now exists. Each such row becomes a pick of it, so the
     * retry is not refused for a SKU already in the catalog.
     *
     * @param  array<array-key, ShipmentItem>  $items
     */
    private function pickRecordedProducts(array $items): void
    {
        foreach ($items as $rowKey => $item) {
            if (isset($this->data['items'][$rowKey]) && blank($this->data['items'][$rowKey]['product_id'] ?? null)) {
                $this->data['items'][$rowKey]['product_id'] = $item->product_id;
            }
        }
    }

    private function validateBusinessRules(array $data): bool
    {
        if (blank($data['first_name'] ?? null) && blank($data['last_name'] ?? null) && blank($data['company'] ?? null)) {
            $this->notifyError('Missing Info', 'Please enter a name or company.');

            return false;
        }

        if (! empty($data['box_size_id']) && ! BoxSize::where('id', $data['box_size_id'])->exists()) {
            $this->notifyError('Box Size Not Found', 'The selected box size no longer exists. Choose another.');

            return false;
        }

        return true;
    }

    private function resetForm(): void
    {
        $this->form->fill([
            'country' => 'US',
        ]);

        $this->dispatch('form-reset');
    }

    /**
     * @return array<int, string>
     */
    public function getShippingMethodOptions(): array
    {
        return ShippingMethod::where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * @return array<int, string>
     */
    public function getBoxSizeOptions(): array
    {
        return BoxSize::query()
            ->active()
            ->orderBy('label')
            ->get()
            ->mapWithKeys(fn (BoxSize $box): array => [
                $box->id => sprintf('%s (%s" x %s" x %s")', $box->label, $box->length, $box->width, $box->height),
            ])
            ->all();
    }

    public function syncDataHash(): void
    {
        $this->rememberData();
    }

    public function reprintLastLabel(): void
    {
        $package = app(SessionLastLabel::class)->package();

        if (is_string($package)) {
            $this->notifyError('No Label to Reprint', $package);

            return;
        }

        $result = app(PackageLabelWorkflow::class)->labelForReprint($package, auth()->user());

        if (! $result->success) {
            $this->notifyError($result->title, $result->message);

            return;
        }

        $this->dispatchPrint($result->printRequest);
        $this->notifySuccess($result->title, $result->message);
    }
}
