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
use App\Models\Shipment;
use App\Services\CacheService;
use App\Services\SettingsService;
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

    public array $packingItems = [];

    public array $boxSizes = [];

    public ?int $boxSizeId = null;

    public string $weight = '';

    public string $height = '';

    public string $width = '';

    public string $length = '';

    public bool $transparencyEnabled = true;

    public function mount($shipment_id = null): void
    {
        $this->transparencyEnabled = (bool) app(SettingsService::class)->get('transparency_enabled', true);

        // Load box sizes for client-side lookup (cached)
        $this->boxSizes = app(CacheService::class)->getBoxSizesForPacking();

        if ($shipment_id) {
            $this->shipment = Shipment::with('shipmentItems.product')
                ->findOrFail($shipment_id);

            $draft = app(PackageDraftWorkflow::class)->resumeForShipment($this->shipment);
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

            $this->boxSizeId = $draft->boxSizeId;
            $this->weight = (string) ($draft->measurements->weight ?? '');
            $this->height = (string) ($draft->measurements->height ?? '');
            $this->width = (string) ($draft->measurements->width ?? '');
            $this->length = (string) ($draft->measurements->length ?? '');
        }
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

            return;
        }

        try {
            $ready = $this->saveReadyPackageDraft();
        } catch (PackageDraftIncompleteException|PackageDraftInvalidException $e) {
            $this->notifyError('Not Ready', $e->getMessage());

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

            return;
        }

        Session::put('last_shipped_package_id', $package->id);

        if ($result->response->labelData) {
            $this->dispatchPrint(PrintRequest::fromShipResponse($result->response));
        }

        $this->notifySuccess('Auto Shipped', $result->summaryMessage());
        $this->resetForNextShipment();
    }

    private function saveReadyPackageDraft(): ReadyPackageDraft
    {
        $options = new PackageDraftOptions(
            requireCompletePackedItems: (bool) app(SettingsService::class)->get('packing_validation_enabled', true),
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
        return array_map(fn (array $item) => new PackageDraftItemInput(
            shipmentItemId: $item['id'],
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
