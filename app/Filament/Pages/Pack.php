<?php

namespace App\Filament\Pages;

use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PackageLabelWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageDrafts\Measurements;
use App\DataTransferObjects\PackageDrafts\PackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftItemInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftOptions;
use App\DataTransferObjects\PackageDrafts\ReadyPackageDraft;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PrintRequest;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\ScanCodeType;
use App\Enums\ScanCommand;
use App\Enums\ShipmentStatus;
use App\Exceptions\PackageDraftIncompleteException;
use App\Exceptions\PackageDraftInvalidException;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Filament\Resources\PackageResource;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\CacheService;
use App\Services\PackageLabels\SessionLastLabel;
use App\Services\Scanning\ScanCode;
use App\Services\Scanning\ShipmentReferenceResolver;
use App\Services\SettingsService;
use App\Services\ShipmentLocationGuard;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Renderless;
use UnitEnum;

class Pack extends Page
{
    use NotifiesUser;
    use PrintsLabels;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $navigationLabel = 'Scan & Pack';

    protected static UnitEnum|string|null $navigationGroup = 'Ship';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.pack';

    protected static ?string $slug = 'pack/{shipment_id?}';

    protected ?string $heading = 'Scan & Pack';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::User) ?? false;
    }

    public ?Shipment $shipment = null;

    public ?string $clientName = null;

    /**
     * The Shipments a scan could mean, when it meant more than one: the packer
     * chooses, since a shared order reference must never open one by guessing.
     *
     * @var list<array{id: int, code: string, reference: ?string, client: ?string, connection: ?string, recipient: string, place: string, status: ?string, statusColor: string|array<int|string, string>|null}>
     */
    public array $shipmentCandidates = [];

    public string $candidateScan = '';

    public bool $multiClientEnabled = false;

    public array $packingItems = [];

    public array $boxSizes = [];

    public ?int $boxSizeId = null;

    /** Whether the shipment has a Package Draft, so every change must be saved to it — including one that empties it. */
    public bool $hasDraft = false;

    public string $weight = '';

    public string $height = '';

    public string $width = '';

    public string $length = '';

    public bool $transparencyEnabled = true;

    public bool $scanToAddEnabled = false;

    public bool $scanToAddMode = false;

    public bool $packingValidationEnabled = true;

    public function mount($shipment_id = null): void
    {
        $this->transparencyEnabled = (bool) app(SettingsService::class)->get('transparency_enabled', true);
        $this->multiClientEnabled = (bool) app(SettingsService::class)->get('multi_client_enabled', false);
        $this->scanToAddEnabled = (bool) app(SettingsService::class)->get('scan_to_add_enabled', false);
        $this->packingValidationEnabled = (bool) app(SettingsService::class)->get('packing_validation_enabled', true);

        if (Session::pull('pack_scan_to_add_override', false)) {
            $this->scanToAddEnabled = true;
        }

        // Load box sizes for client-side lookup (cached)
        $this->boxSizes = app(CacheService::class)->getBoxSizesForPacking();

        if ($shipment_id) {
            $this->shipment = Shipment::with(['client', 'location'])->findOrFail($shipment_id);

            $locationError = app(ShipmentLocationGuard::class)->errorFor($this->shipment, auth()->user());

            if ($locationError !== null) {
                $this->notifyError('Location unavailable', $locationError);
                $this->shipment = null;
                $this->redirect('/pack');

                return;
            }

            if ($this->shipment->isAmazonFulfilled()) {
                $this->notifyWarning(
                    'Fulfilled by Amazon',
                    "Shipment {$this->shipment->shipment_reference} is an FBA order. Amazon picks, packs and ships it — packing it here would send a duplicate.",
                );
                $this->shipment = null;
                $this->redirect('/pack');

                return;
            }

            if ($this->shipment->isBlockedByPicking()) {
                $this->notifyWarning(
                    'Picking Required',
                    "Shipment {$this->shipment->shipment_reference} has not been picked. Complete picking before packing.",
                );
                $this->shipment = null;
                $this->redirect('/pack');

                return;
            }

            if (! auth()->user()->can('reship', $this->shipment)) {
                $this->notifyWarning('Already Shipped', $this->alreadyShippedMessage());
                $this->shipment = null;
                $this->redirect('/pack');

                return;
            }

            // Packing it again sends a second parcel, which is sometimes the
            // point — a replacement — but should never be a surprise.
            if ($this->shipment->status === ShipmentStatus::Shipped) {
                $this->notifyWarning(
                    'Already Shipped',
                    "Shipment {$this->shipment->shipment_reference} has already shipped. Packing it again will send another package.",
                );
            }

            // Packing is physical work and the method can be fixed after, so
            // this warns rather than refuses. The label cannot be bought until
            // one is chosen (`carrier-catalog-reset/16`).
            if ($this->shipment->needsShippingMethod()) {
                $this->notifyWarning(
                    'No Shipping Method',
                    "Shipment {$this->shipment->shipment_reference} has no shipping method. It can be packed, but a label cannot be bought until one is chosen.",
                );
            }

            $this->clientName = $this->multiClientEnabled
                ? $this->shipment->client?->name
                : null;

            $clientId = $this->shipment->client_id;
            $this->shipment->load(['shipmentItems.product' => function ($query) use ($clientId): void {
                if ($clientId) {
                    $query->where('client_id', $clientId);
                }
            }]);

            $this->scanToAddMode = $this->scanToAddEnabled && $this->shipment->shipmentItems->isEmpty();

            $draft = app(PackageDraftWorkflow::class)->resumeForShipment($this->shipment);

            if ($this->scanToAddMode) {
                $productIds = collect($draft->items ?? [])->pluck('productId')->unique()->filter();
                $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

                foreach ($draft->items ?? [] as $draftItem) {
                    $product = $products->get($draftItem->productId);
                    if (! $product) {
                        continue;
                    }
                    $this->packingItems[] = [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'barcode' => $product->barcode,
                        'name' => $product->name,
                        'quantity' => $draftItem->quantity,
                        'packed' => $draftItem->quantity,
                        'transparency_codes' => $draftItem->transparencyCodes,
                    ];
                }
            } else {
                $packedItems = collect($draft->items ?? [])->keyBy('shipmentItemId');

                foreach ($this->shipment->shipmentItems as $shipmentItem) {
                    $packedItem = $packedItems->get($shipmentItem->id);
                    $packingItem = $shipmentItem->toArray();
                    $packingItem['sku'] = $shipmentItem->product?->sku;
                    $packingItem['barcode'] = $shipmentItem->product?->barcode;
                    $packingItem['name'] = $shipmentItem->product?->name;
                    $packingItem['packed'] = $packedItem?->quantity ?? 0;
                    $packingItem['transparency_codes'] = $packedItem?->transparencyCodes ?? [];
                    $this->packingItems[] = $packingItem;
                }
            }

            $this->hasDraft = $draft !== null;
            $this->boxSizeId = $draft?->boxSizeId;
            $this->weight = (string) ($draft?->measurements->weight ?? '');
            $this->height = (string) ($draft?->measurements->height ?? '');
            $this->width = (string) ($draft?->measurements->width ?? '');
            $this->length = (string) ($draft?->measurements->length ?? '');
        }
    }

    /**
     * Look up a product by barcode or SKU and dispatch it back to Alpine for scan-to-add mode.
     */
    public function addItemByScan(string $barcode): void
    {
        if (! $this->shipment || ! $this->scanToAddMode) {
            return;
        }

        $query = Product::query();

        if ($this->shipment->client_id) {
            $query->where('client_id', $this->shipment->client_id);
        }

        $product = $query->where(function ($q) use ($barcode): void {
            $q->where('barcode', $barcode)->orWhere('sku', $barcode);
        })->first();

        if (! $product) {
            $this->dispatch('scan-to-add-not-found', barcode: $barcode);

            return;
        }

        $this->dispatch('scan-to-add-found', product: [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
        ]);
    }

    /**
     * Ship the current package. Called from Alpine with all client-side state.
     */
    public string $labelFormat = 'pdf';

    public ?int $labelDpi = null;

    public bool $hasReportPrinter = false;

    public function ship(array $packingItems, ?int $boxSizeId, string $weight, string $height, string $width, string $length, bool $autoShip, string $labelFormat = 'pdf', ?int $labelDpi = null, bool $hasReportPrinter = false): void
    {
        $this->packingItems = $packingItems;
        $this->boxSizeId = $boxSizeId;
        $this->weight = $weight;
        $this->height = $height;
        $this->width = $width;
        $this->length = $length;
        $this->labelFormat = $labelFormat;
        $this->labelDpi = $labelDpi;
        $this->hasReportPrinter = $hasReportPrinter;

        $autoShip = (bool) auth()->user()->auto_ship_enabled;

        if (! $this->shipment) {
            $this->notifyError('Invalid State', 'No shipment loaded.');
            $this->dispatch('shipping-error');

            return;
        }

        $this->shipment->refresh()->load('location');
        $locationError = app(ShipmentLocationGuard::class)->errorFor($this->shipment, auth()->user());
        if ($locationError !== null) {
            $this->notifyError('Location unavailable', $locationError);
            $this->dispatch('shipping-error');

            return;
        }

        // Checked on mount too, but the shipment may have shipped from another
        // station while this page was open.
        if (! auth()->user()->can('reship', $this->shipment)) {
            $this->notifyError('Already Shipped', $this->alreadyShippedMessage());
            $this->dispatch('shipping-error');

            return;
        }

        try {
            $ready = $this->saveReadyPackageDraft();
        } catch (PackageDraftIncompleteException|PackageDraftInvalidException $e) {
            $this->notifyError('Not Ready', $e->getMessage());
            $this->dispatch('shipping-error');

            return;
        }

        if ($autoShip) {
            $this->autoShip($ready->package);
        } else {
            $this->manualShip($ready->package);
        }
    }

    /**
     * Save packing progress as it happens, so a package can be set aside and
     * resumed. Called from Alpine after a box or item scan and when the weight
     * or dimensions settle; the first call creates the Package Draft.
     */
    #[Renderless]
    public function saveDraft(array $packingItems, ?int $boxSizeId, string $weight, string $height, string $width, string $length): void
    {
        if (! $this->shipment) {
            return;
        }

        // As in ship(): another station may have shipped it since mount.
        $current = Shipment::find($this->shipment->id);
        if (! $current || ! auth()->user()->can('reship', $current)) {
            $this->notifyWarning('Progress Not Saved', $this->alreadyShippedMessage());

            return;
        }

        $this->packingItems = $packingItems;
        $this->boxSizeId = $boxSizeId;
        $this->weight = $weight;
        $this->height = $height;
        $this->width = $width;
        $this->length = $length;

        try {
            app(PackageDraftWorkflow::class)->saveForShipment(
                shipment: $this->shipment,
                input: new PackageDraftInput(
                    measurements: new Measurements($this->weight, $this->height, $this->width, $this->length),
                    boxSizeId: $this->boxSizeId,
                    items: $this->mapPackingItems(),
                ),
            );
        } catch (PackageDraftInvalidException $e) {
            $this->notifyWarning('Progress Not Saved', $e->getMessage());
        }
    }

    private function alreadyShippedMessage(): string
    {
        return "Shipment {$this->shipment?->shipment_reference} has already shipped. Ask a manager if it needs to be sent again.";
    }

    public function canToggleAutoShip(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Manager);
    }

    /**
     * Flip the signed-in manager's own auto-ship setting and return the new value.
     */
    public function toggleAutoShip(): bool
    {
        abort_unless($this->canToggleAutoShip(), 403);

        $user = auth()->user();
        $user->update(['auto_ship_enabled' => ! $user->auto_ship_enabled]);

        return $user->auto_ship_enabled;
    }

    /**
     * Manual ship - creates package and redirects to Ship page.
     */
    private function manualShip(Package $package): void
    {
        $this->redirect('/ship/'.$package->id);
    }

    /**
     * Auto ship - creates package, fetches rates, selects cheapest, ships, and prints label.
     * If any step fails after package creation, the package is deleted to prevent orphans.
     */
    private function autoShip(Package $package): void
    {
        $result = app(PackageShippingWorkflow::class)->autoShip(
            $package,
            new PackageAutoShippingRequest(
                labelFormat: $this->labelFormat,
                labelDpi: $this->labelDpi,
                userId: auth()->id(),
                cleanupOnFailure: false,
                hasReportPrinter: $this->hasReportPrinter,
            ),
        );

        if (! $result->success) {
            if ($result->requiresAttendedSelection) {
                $this->notifyWarning($result->title ?? 'Attended Shipping Required', $result->message);
                $this->redirect('/ship/'.$package->id);

                return;
            }

            $this->notifyError($result->title ?? 'Shipping Error', $result->message ?? 'Unable to ship package.');
            $this->dispatch('shipping-error');
            // A label bought but not recorded is also sent to the bell; show it now, not at the next poll.
            $this->dispatch('databaseNotificationsSent');

            return;
        }

        app(SessionLastLabel::class)->remember($package);

        if ($result->response->labelData) {
            $this->dispatchPrint(PrintRequest::fromShipResponse($result->response, $package));
        }

        $this->notifySuccess('Auto Shipped', $result->summaryMessage());
        $this->resetForNextShipment();
    }

    private function saveReadyPackageDraft(): ReadyPackageDraft
    {
        $options = new PackageDraftOptions(
            requireCompletePackedItems: ! $this->scanToAddMode
                && $this->packingValidationEnabled,
        );

        $draft = app(PackageDraftWorkflow::class)->saveForShipment(
            shipment: $this->shipment,
            input: new PackageDraftInput(
                measurements: new Measurements($this->weight, $this->height, $this->width, $this->length),
                boxSizeId: $this->boxSizeId,
                items: $this->mapPackingItems(),
            ),
            options: $options,
        );

        return app(PackageDraftWorkflow::class)->assertReadyToShip(
            shipment: $this->shipment,
            packageDraftId: $draft->packageDraftId,
            options: $options,
        );
    }

    /**
     * @return array<int, PackageDraftItemInput>
     */
    private function mapPackingItems(): array
    {
        return array_map(fn (array $item): PackageDraftItemInput => new PackageDraftItemInput(
            shipmentItemId: $this->scanToAddMode ? null : $item['id'],
            productId: $item['product_id'],
            quantity: (int) $item['packed'],
            transparencyCodes: $item['transparency_codes'] ?? [],
        ), $this->packingItems);
    }

    /**
     * Reset state for the next shipment.
     */
    private function resetForNextShipment(): void
    {
        $this->shipment = null;
        $this->packingItems = [];
        $this->boxSizeId = null;
        $this->weight = '';
        $this->height = '';
        $this->width = '';
        $this->length = '';

        // Refocus the scan input for the next shipment
        $this->dispatch('focus-scan-input');
    }

    /**
     * Open the Shipment an order reference names (called from JS when no
     * shipment is loaded). Several matches are listed to choose from; a
     * PolyBag code is resolved exactly instead.
     */
    public function navigateToShipment(string $scan): void
    {
        if (ScanCode::parse($scan) !== null) {
            $this->openScanCode($scan);

            return;
        }

        $this->shipmentCandidates = [];
        $this->candidateScan = '';

        $candidates = app(ShipmentReferenceResolver::class)->matching($scan);

        if ($candidates->isEmpty()) {
            $this->notifyError('Shipment Not Found', "No shipment has the order reference '{$scan}'.");

            return;
        }

        if ($candidates->count() === 1) {
            $this->redirect('/pack/'.$candidates->first()->id);

            return;
        }

        $this->candidateScan = trim($scan);
        $this->shipmentCandidates = $candidates
            ->map(fn (Shipment $shipment): array => [
                'id' => $shipment->id,
                'code' => ScanCode::forShipment($shipment),
                'reference' => $shipment->shipment_reference,
                'client' => $shipment->client?->name,
                'connection' => $shipment->dataSource?->name,
                'recipient' => trim("{$shipment->first_name} {$shipment->last_name}"),
                'place' => trim("{$shipment->city}, {$shipment->state_or_province}", ', '),
                'status' => $shipment->status->getLabel(),
                'statusColor' => $shipment->status->getColor(),
            ])
            ->values()
            ->all();
    }

    /**
     * Open one of the Shipments the last scan could mean.
     */
    public function chooseShipment(int $shipmentId): void
    {
        if (! in_array($shipmentId, array_column($this->shipmentCandidates, 'id'), true)) {
            return;
        }

        $this->redirect('/pack/'.$shipmentId);
    }

    /**
     * Each command's barcode value, for the browser to run it on scan.
     *
     * @return array<string, string> Code => command name
     */
    public function scanCommandCodes(): array
    {
        return collect(ScanCommand::cases())
            ->mapWithKeys(fn (ScanCommand $command): array => [ScanCode::forCommand($command) => $command->value])
            ->all();
    }

    /**
     * Act on a scanned PolyBag code other than a command the browser ran
     * itself. The code names its record exactly; what happens depends on
     * whether a Shipment is being packed (ADR-0007, decision 3).
     */
    public function openScanCode(string $scan): void
    {
        $code = ScanCode::parse($scan);
        $type = $code?->type;

        if ($code === null || $type === null) {
            $this->notifyError('Unrecognized Code', "'".trim($scan)."' starts with this install's barcode prefix, but is not a code PolyBag knows.");

            return;
        }

        match ($type) {
            ScanCodeType::Shipment => $this->openScannedShipment(Shipment::find($code->id), $code->scan),
            ScanCodeType::Package => $this->openScannedPackage(Package::find($code->id), $code->scan),
            // The browser applies an active box while packing; one reaching here is not that.
            ScanCodeType::BoxSize => $this->shipment === null
                ? $this->notifyError('No Shipment Loaded', 'Scan a pack slip before scanning a box.')
                : $this->notifyError('Box Size Not Available', "{$code->scan} is not an active box size."),
            ScanCodeType::Command => $this->notifyError('Unknown Command', "'{$code->scan}' is not a command PolyBag knows. Reprint the command sheet if it is an old one."),
            ScanCodeType::Action => $this->notifyError('Not Available', 'Custom scan actions are not available yet.'),
        };
    }

    private function openScannedShipment(?Shipment $shipment, string $code): void
    {
        if ($shipment === null) {
            $this->notifyError('Shipment Not Found', "No shipment has the code {$code}. It may have been deleted.");

            return;
        }

        if ($this->refusesSwitchTo($shipment, "{$code} is shipment {$shipment->shipment_reference}")) {
            return;
        }

        $this->redirect('/pack/'.$shipment->id);
    }

    /**
     * A Package code opens that Package and no other. Scan & Pack resumes a
     * Shipment's oldest draft, so it opens the scanned draft only when that is
     * the one; anything else goes to the Package's own page (ADR-0007, decision 3).
     */
    private function openScannedPackage(?Package $package, string $code): void
    {
        if ($package === null) {
            $this->notifyError('Package Not Found', "No package has the code {$code}. It may have been deleted.");

            return;
        }

        $named = "{$code} is Package #{$package->id}, for shipment {$package->shipment?->shipment_reference}";
        $resumedDraftId = $package->status === PackageStatus::Unshipped && $package->shipment
            ? app(PackageDraftWorkflow::class)->resumeForShipment($package->shipment)?->packageDraftId
            : null;
        $isResumedDraft = $resumedDraftId === $package->id;

        if ($this->shipment !== null) {
            $isResumedDraft && $package->shipment_id === $this->shipment->id
                ? $this->notifyInfo('Already Open', "{$named}, which is the one being packed.")
                : $this->notifyWarning('Another Package', "{$named}. Clear this shipment first, then scan it again.");

            return;
        }

        if ($isResumedDraft) {
            $this->redirect('/pack/'.$package->shipment_id);

            return;
        }

        if ($package->status === PackageStatus::Unshipped) {
            $this->notifyWarning('Opened on Its Own Page', "Scan & Pack resumes Package #{$resumedDraftId} for this shipment, not #{$package->id}.");
        }

        $this->redirect(PackageResource::getUrl('view', ['record' => $package]));
    }

    /**
     * While a Shipment is being packed, a scan naming another one does not
     * switch to it: progress saves on a debounce, and must land first.
     */
    private function refusesSwitchTo(?Shipment $target, string $named): bool
    {
        if ($this->shipment === null) {
            return false;
        }

        if ($target?->id === $this->shipment->id) {
            $this->notifyInfo('Already Open', "{$named}, which is the one being packed.");
        } else {
            $this->notifyWarning('Another Shipment', "{$named}. Clear this one first, then scan it again.");
        }

        return true;
    }

    /**
     * Reprint the label this session last bought.
     */
    public function reprintLastLabel(): void
    {
        $package = $this->lastLabelPackage('No Label to Reprint');

        if ($package === null) {
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

    /**
     * The Package of the label this session last bought, or null after telling
     * the packer why there is none.
     */
    private function lastLabelPackage(string $title): ?Package
    {
        $package = app(SessionLastLabel::class)->package();

        if (is_string($package)) {
            $this->notifyError($title, $package);

            return null;
        }

        return $package;
    }

    /**
     * Void the label this session last bought, if this user bought it.
     */
    public function cancelLastLabel(): void
    {
        $package = $this->lastLabelPackage('No Label to Cancel');

        if ($package === null) {
            return;
        }

        $result = app(PackageLabelWorkflow::class)->voidOwnLabel($package, auth()->user());

        if ($result->success) {
            app(SessionLastLabel::class)->forget();
            $this->notifySuccess('Label Cancelled', $result->message);

            if ($result->warning !== null) {
                $this->notifyWarning('Sales channel not updated', $result->warning);
            }

            return;
        }

        $this->notifyError($result->title, $result->message);
    }
}
