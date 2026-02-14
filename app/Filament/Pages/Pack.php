<?php

namespace App\Filament\Pages;

use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\CacheService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\SettingsService;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShippingRateService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class Pack extends Page
{
    use NotifiesUser;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected string $view = 'filament.pages.pack';

    protected static ?string $slug = 'pack/{shipment_id?}';

    protected ?string $heading = 'Pack';

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
        $this->transparencyEnabled = (bool) SettingsService::get('transparency_enabled', true);

        // Load box sizes for client-side lookup (cached)
        $this->boxSizes = CacheService::getBoxSizesForPacking();

        if ($shipment_id) {
            $this->shipment = Shipment::where('shipment_reference', $shipment_id)
                ->with('shipmentItems.product')
                ->firstOrFail();

            foreach ($this->shipment->shipmentItems as $shipmentItem) {
                $packingItem = $shipmentItem->toArray();
                $packingItem['sku'] = $shipmentItem->product?->sku;
                $packingItem['barcode'] = $shipmentItem->product?->barcode;
                $packingItem['name'] = $shipmentItem->product?->name;
                $packingItem['packed'] = 0;
                $packingItem['transparency_codes'] = [];
                $this->packingItems[] = $packingItem;
            }
        }
    }

    /**
     * Ship the current package. Called from Alpine with all client-side state.
     */
    public function ship(array $packingItems, ?int $boxSizeId, string $weight, string $height, string $width, string $length, bool $autoShip): void
    {
        $this->packingItems = $packingItems;
        $this->boxSizeId = $boxSizeId;
        $this->weight = $weight;
        $this->height = $height;
        $this->width = $width;
        $this->length = $length;

        if ($autoShip && ! auth()->user()->role->isAtLeast(Role::Admin)) {
            $autoShip = false;
        }

        if (! $this->validatePackingData()) {
            return;
        }

        if (! $this->isReadyToShip()) {
            $this->notifyError('Not Ready', 'Please ensure all items are packed and all dimensions are filled.');

            return;
        }

        if ($autoShip) {
            $this->autoShip();
        } else {
            $this->manualShip();
        }
    }

    /**
     * Manual ship - creates package and redirects to Ship page.
     */
    private function manualShip(): void
    {
        $package = $this->createPackage();
        $this->trackInProgressPackage($package->id);
        $this->redirect('/ship/'.$package->id);
    }

    /**
     * Auto ship - creates package, fetches rates, selects cheapest, ships, and prints label.
     * If any step fails after package creation, the package is deleted to prevent orphans.
     */
    private function autoShip(): void
    {
        $package = null;

        try {
            $package = $this->createPackage();

            $rates = ShippingRateService::getShippingRates($package->id);

            if ($rates->isEmpty()) {
                $package->packageItems()->delete();
                $package->delete();
                $this->notifyError('No Rates', 'No shipping rates available for this package.');

                return;
            }

            $selectedRate = $rates->sortBy->price->first();

            $package->load(['packageItems.product', 'packageItems.shipmentItem', 'shipment']);

            $adapter = CarrierRegistry::get($selectedRate->carrier);
            $shipRequest = ShipRequest::fromPackageAndRate($package, $selectedRate);
            $response = $adapter->createShipment($shipRequest);

            if (! $response->success) {
                $package->packageItems()->delete();
                $package->delete();
                $this->notifyError('Shipping Error', $response->errorMessage ?? 'Failed to create shipment.');

                return;
            }

            $package->markShipped($response, auth()->id());

            // Export package data to configured external systems
            try {
                $exportResult = app(PackageExportService::class)->exportPackage($package);
                if ($exportResult->hasErrors()) {
                    logger()->warning('Package export partial failure', [
                        'package_id' => $package->id,
                        'errors' => $exportResult->errors,
                    ]);
                }
            } catch (\Exception $e) {
                logger()->error('Package export failed', [
                    'package_id' => $package->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Store last shipped package for reprint/cancel commands
            Session::put('last_shipped_package_id', $package->id);

            if ($response->labelData) {
                $this->dispatch('print-label', label: $response->labelData, orientation: $response->labelOrientation ?? 'portrait', format: $response->labelFormat ?? 'pdf');
            }

            $this->notifySuccess(
                'Auto Shipped',
                "Tracking: {$response->trackingNumber} via {$response->carrier} ({$selectedRate->serviceName}) - \$".number_format($response->cost, 2)
            );

            $this->resetForNextShipment();

        } catch (\Saloon\Exceptions\Request\Statuses\RequestTimeoutException $e) {
            if ($package?->exists && ! $package->shipped) {
                $package->packageItems()->delete();
                $package->delete();
            }
            logger()->error('AutoShip timeout', ['package_id' => $package?->id]);
            $this->notifyError('Carrier Timeout', 'The carrier API is not responding. Please try again in a few moments.');
        } catch (\Saloon\Exceptions\Request\RequestException $e) {
            if ($package?->exists && ! $package->shipped) {
                $package->packageItems()->delete();
                $package->delete();
            }
            logger()->error('AutoShip carrier error', ['package_id' => $package?->id, 'error' => $e->getMessage()]);
            $this->notifyError('Carrier Error', 'Unable to connect to the carrier. Please try again.');
        } catch (\RuntimeException $e) {
            // Optimistic locking failure - don't delete, just notify
            logger()->warning('AutoShip race condition', ['package_id' => $package?->id, 'error' => $e->getMessage()]);
            $this->notifyError('Package State Changed', $e->getMessage());
        } catch (\Exception $e) {
            if ($package?->exists && ! $package->shipped) {
                $package->packageItems()->delete();
                $package->delete();
            }
            logger()->error('AutoShip error', ['error' => $e->getMessage()]);
            $this->notifyError('Auto Ship Error', 'An unexpected error occurred. Please try again.');
        }
    }

    /**
     * Create a package from the current packing state.
     * Cleans up any previous in-progress package from this user's session before creating.
     */
    private function createPackage(): Package
    {
        return DB::transaction(function () {
            $previousPackageId = Session::get('in_progress_package_id');

            if ($previousPackageId) {
                $orphan = Package::where('id', $previousPackageId)
                    ->where('shipped', false)
                    ->first();

                if ($orphan) {
                    $orphan->packageItems()->delete();
                    $orphan->delete();
                }

                Session::forget('in_progress_package_id');
            }

            $package = Package::create([
                'shipment_id' => $this->shipment->id,
                'box_size_id' => $this->boxSizeId,
                'weight' => $this->weight,
                'height' => $this->height,
                'width' => $this->width,
                'length' => $this->length,
            ]);

            $packageItems = [];
            foreach ($this->packingItems as $packingItem) {
                $packageItems[] = [
                    'shipment_item_id' => $packingItem['id'],
                    'product_id' => $packingItem['product_id'],
                    'quantity' => $packingItem['packed'],
                    'transparency_codes' => $packingItem['transparency_codes'] ?? [],
                ];
            }
            $package->packageItems()->createMany($packageItems);

            return $package;
        });
    }

    /**
     * Track a package as in-progress for this session (for orphan cleanup).
     * Called after createPackage() in manual ship flow.
     */
    private function trackInProgressPackage(int $packageId): void
    {
        Session::put('in_progress_package_id', $packageId);
    }

    /**
     * Clear the in-progress package tracking (after ship or auto-ship completes).
     */
    private function clearInProgressPackage(): void
    {
        Session::forget('in_progress_package_id');
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

        $this->redirect('/pack/'.rawurlencode($reference));
    }

    /**
     * Validate that packing data from the client is consistent with server state.
     */
    private function validatePackingData(): bool
    {
        if (! $this->shipment) {
            $this->notifyError('Invalid State', 'No shipment loaded.');

            return false;
        }

        // Validate box size exists if provided
        if ($this->boxSizeId !== null && ! BoxSize::where('id', $this->boxSizeId)->exists()) {
            $this->notifyError('Invalid Box Size', 'The selected box size does not exist.');

            return false;
        }

        // Build a lookup of valid shipment item IDs and their product IDs for this shipment
        $validItems = $this->shipment->shipmentItems()
            ->pluck('product_id', 'id');

        foreach ($this->packingItems as $packingItem) {
            $itemId = $packingItem['id'] ?? null;
            $productId = $packingItem['product_id'] ?? null;
            $packed = $packingItem['packed'] ?? 0;

            // Verify the shipment item belongs to this shipment
            if (! $itemId || ! $validItems->has($itemId)) {
                $this->notifyError('Invalid Item', 'One or more packing items do not belong to this shipment.');

                return false;
            }

            // Verify the product ID matches
            if ($productId != $validItems[$itemId]) {
                $this->notifyError('Invalid Item', 'Product mismatch detected in packing items.');

                return false;
            }

            // Verify quantity is a reasonable non-negative integer
            if (! is_numeric($packed) || $packed < 0 || $packed > 10000) {
                $this->notifyError('Invalid Quantity', 'Packed quantity is out of range.');

                return false;
            }
        }

        return true;
    }

    private function isReadyToShip(): bool
    {
        if (! $this->shipment) {
            return false;
        }

        // If packing validation is disabled, only check weight/dimensions
        if (! SettingsService::get('packing_validation_enabled', true)) {
            return $this->hasValidDimensions();
        }

        // Check all items are packed
        foreach ($this->packingItems as $packingItem) {
            if ($packingItem['packed'] != $packingItem['quantity']) {
                return false;
            }
        }

        return $this->hasValidDimensions();
    }

    private function hasValidDimensions(): bool
    {
        if (empty($this->weight) || $this->weight <= 0) {
            return false;
        }

        if (empty($this->height) || $this->height <= 0) {
            return false;
        }

        if (empty($this->width) || $this->width <= 0) {
            return false;
        }

        if (empty($this->length) || $this->length <= 0) {
            return false;
        }

        return true;
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

        if (! $package || ! $package->shipped || ! $package->label_data) {
            $this->notifyError('Label Not Available', 'The label for the last shipped package is not available.');

            return;
        }

        // Verify the current user shipped this package or has elevated permissions
        if (! $this->canAccessPackage($package)) {
            $this->notifyError('Access Denied', 'You can only reprint labels for packages you shipped.');

            return;
        }

        $this->dispatch('print-label', label: $package->label_data, orientation: $package->label_orientation ?? 'portrait', format: $package->label_format ?? 'pdf');
        $this->notifySuccess('Label Reprinted', "Reprinted label for tracking: {$package->tracking_number}");
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

        if (! $package || ! $package->shipped) {
            $this->notifyError('Package Not Found', 'The last shipped package could not be found.');

            return;
        }

        // Verify the current user shipped this package or has elevated permissions
        if (! $this->canAccessPackage($package)) {
            $this->notifyError('Access Denied', 'You can only cancel labels for packages you shipped.');

            return;
        }

        if (! $package->tracking_number || ! $package->carrier) {
            $this->notifyError('Cannot Cancel', 'Package is missing tracking information.');

            return;
        }

        try {
            $adapter = CarrierRegistry::get($package->carrier);
            $response = $adapter->cancelShipment($package->tracking_number, $package);

            if ($response->success) {
                $package->clearShipping();
                Session::forget('last_shipped_package_id');
                $this->notifySuccess('Label Cancelled', $response->message ?? 'The label has been voided.');
            } else {
                $this->notifyError('Cancel Failed', $response->message ?? 'Failed to cancel the label.');
            }
        } catch (\RuntimeException $e) {
            // Optimistic locking failure
            $this->notifyError('Package State Changed', $e->getMessage());
        } catch (\Saloon\Exceptions\Request\RequestException $e) {
            logger()->error('Cancel label carrier error', ['error' => $e->getMessage()]);
            $this->notifyError('Carrier Error', 'Unable to connect to carrier. Please try again.');
        } catch (\Exception $e) {
            logger()->error('Cancel label error', ['error' => $e->getMessage()]);
            $this->notifyError('Cancel Error', 'An unexpected error occurred.');
        }
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
