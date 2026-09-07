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
use App\Enums\Role;
use App\Exceptions\PackageDraftIncompleteException;
use App\Exceptions\PackageDraftInvalidException;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\CacheService;
use App\Services\SettingsService;
use App\Services\ShipmentLocationGuard;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Session;
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

    public bool $multiClientEnabled = false;

    public array $packingItems = [];

    public array $boxSizes = [];

    public ?int $boxSizeId = null;

    public string $weight = '';

    public string $height = '';

    public string $width = '';

    public string $length = '';

    public bool $transparencyEnabled = true;

    public bool $scanToAddEnabled = false;

    public bool $scanToAddMode = false;

    public bool $packingValidationEnabled = true;

    public ?bool $autoShipOverride = null;

    public function mount($shipment_id = null): void
    {
        $this->transparencyEnabled = (bool) app(SettingsService::class)->get('transparency_enabled', true);
        $this->multiClientEnabled = (bool) app(SettingsService::class)->get('multi_client_enabled', false);
        $this->scanToAddEnabled = (bool) app(SettingsService::class)->get('scan_to_add_enabled', false);
        $this->packingValidationEnabled = (bool) app(SettingsService::class)->get('packing_validation_enabled', true);

        if (Session::has('pack_auto_ship_override')) {
            $this->autoShipOverride = (bool) Session::pull('pack_auto_ship_override');
        }

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
                $productIds = collect($draft->items)->pluck('productId')->unique()->filter();
                $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

                foreach ($draft->items as $draftItem) {
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
                $packedItems = collect($draft->items)->keyBy('shipmentItemId');

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

            $this->boxSizeId = $draft->boxSizeId;
            $this->weight = (string) ($draft->measurements->weight ?? '');
            $this->height = (string) ($draft->measurements->height ?? '');
            $this->width = (string) ($draft->measurements->width ?? '');
            $this->length = (string) ($draft->measurements->length ?? '');
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

    public function ship(array $packingItems, ?int $boxSizeId, string $weight, string $height, string $width, string $length, bool $autoShip, string $labelFormat = 'pdf', ?int $labelDpi = null): void
    {
        $this->packingItems = $packingItems;
        $this->boxSizeId = $boxSizeId;
        $this->weight = $weight;
        $this->height = $height;
        $this->width = $width;
        $this->length = $length;
        $this->labelFormat = $labelFormat;
        $this->labelDpi = $labelDpi;

        if ($autoShip && ! auth()->user()->role->isAtLeast(Role::Admin)) {
            $autoShip = false;
        }

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
            ),
        );

        if (! $result->success) {
            $this->notifyError($result->title ?? 'Shipping Error', $result->message ?? 'Unable to ship package.');
            $this->dispatch('shipping-error');

            return;
        }

        Session::put('last_shipped_package_id', $package->id);

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
     * Navigate to a shipment by reference (called from JS when no shipment loaded).
     */
    public function navigateToShipment(string $reference): void
    {
        $shipment = Shipment::where('shipment_reference', $reference)->first();

        if (! $shipment) {
            $this->notifyError('Shipment Not Found', "No shipment found for reference '{$reference}'.");

            return;
        }

        $this->redirect('/pack/'.$shipment->id);
    }

    /**
     * Reprint the label for the last shipped package.
     */
    public function reprintLastLabel(): void
    {
        $packageId = Session::get('last_shipped_package_id');

        if (! $packageId) {
            $this->notifyError('No Label to Reprint', 'No package has been shipped in this session.');

            return;
        }

        $package = Package::find($packageId);

        if (! $package) {
            $this->notifyError('Label Not Available', 'The label for the last shipped package is not available.');

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
     * Cancel/void the last shipped label.
     */
    public function cancelLastLabel(): void
    {
        $packageId = Session::get('last_shipped_package_id');

        if (! $packageId) {
            $this->notifyError('No Label to Cancel', 'No package has been shipped in this session.');

            return;
        }

        $package = Package::with('shipment')->find($packageId);

        if (! $package) {
            $this->notifyError('Package Not Found', 'The last shipped package could not be found.');

            return;
        }

        if (! $this->canAccessPackage($package)) {
            $this->notifyError('Access Denied', 'You can only cancel labels for packages you shipped.');

            return;
        }

        $result = app(PackageLabelWorkflow::class)->voidLabel($package);

        if ($result->success) {
            Session::forget('last_shipped_package_id');
            $this->notifySuccess('Label Cancelled', $result->message);

            return;
        }

        $this->notifyError($result->title, $result->message);
    }

    /**
     * Check if the current user can access/modify a package.
     * Users can only access packages they shipped, unless they are a manager or admin.
     */
    private function canAccessPackage(Package $package): bool
    {
        $user = auth()->user();

        // Managers and admins can access any package
        if ($user->role->isAtLeast(Role::Manager)) {
            return true;
        }

        // Regular users can only access packages they shipped
        return $package->shipped_by_user_id === $user->id;
    }
}
