<?php

namespace App\Filament\Pages;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Events\PackageCreated;
use App\Filament\Concerns\NotifiesUser;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\AddressValidationService;
use App\Services\CacheService;
use App\Services\LabelGenerationService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use UnitEnum;

class ManualShip extends Page
{
    use NotifiesUser;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Manual Ship';

    protected static UnitEnum|string|null $navigationGroup = 'Ship';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.manual-ship';

    protected static ?string $slug = 'manual-ship';

    protected ?string $heading = 'Manual Ship';

    public static function canAccess(): bool
    {
        return (auth()->user()?->role->isAtLeast(Role::User) ?? false)
            && app(\App\Services\SettingsService::class)->get('manual_shipping_enabled', true);
    }

    // Address fields
    public string $shipmentReference = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $company = '';

    public string $address1 = '';

    public string $address2 = '';

    public string $city = '';

    public string $stateOrProvince = '';

    public string $postalCode = '';

    public string $country = 'US';

    public string $phone = '';

    public string $email = '';

    public ?int $shippingMethodId = null;

    // Package fields
    public array $boxSizes = [];

    public ?int $boxSizeId = null;

    public string $weight = '';

    public string $height = '';

    public string $width = '';

    public string $length = '';

    public string $labelFormat = 'pdf';

    public ?int $labelDpi = null;

    public function mount(): void
    {
        $this->boxSizes = app(CacheService::class)->getBoxSizesForPacking();
    }

    public function ship(
        string $firstName,
        string $lastName,
        string $company,
        string $address1,
        string $address2,
        string $city,
        string $stateOrProvince,
        string $postalCode,
        string $country,
        string $phone,
        string $email,
        ?int $shippingMethodId,
        ?int $boxSizeId,
        string $weight,
        string $height,
        string $width,
        string $length,
        bool $autoShip,
        string $shipmentReference = '',
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
    ): void {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->company = $company;
        $this->address1 = $address1;
        $this->address2 = $address2;
        $this->city = $city;
        $this->stateOrProvince = $stateOrProvince;
        $this->postalCode = $postalCode;
        $this->country = $country;
        $this->phone = $phone;
        $this->email = $email;
        $this->shippingMethodId = $shippingMethodId;
        $this->boxSizeId = $boxSizeId;
        $this->weight = $weight;
        $this->height = $height;
        $this->width = $width;
        $this->length = $length;
        $this->shipmentReference = $shipmentReference;
        $this->labelFormat = $labelFormat;
        $this->labelDpi = $labelDpi;

        if ($autoShip && ! auth()->user()->role->isAtLeast(Role::Admin)) {
            $autoShip = false;
        }

        if (! $this->validateForm()) {
            return;
        }

        if ($autoShip) {
            $this->autoShip();
        } else {
            $this->manualShip();
        }
    }

    private function manualShip(): void
    {
        $package = $this->createShipmentAndPackage();

        Session::put('ship_return_url', '/manual-ship');
        $this->redirect('/ship/'.$package->id);
    }

    private function autoShip(): void
    {
        $package = null;

        try {
            $package = $this->createShipmentAndPackage();

            $result = app(LabelGenerationService::class)->generateLabel($package, $this->labelFormat, $this->labelDpi);

            if (! $result->success) {
                $package->packageItems()->delete();
                $package->delete();
                $package->shipment->delete();
                $this->notifyError('Shipping Error', $result->errorMessage);

                return;
            }

            $package->markShipped($result->response, auth()->id());

            Session::put('last_shipped_package_id', $package->id);

            if ($result->response->labelData) {
                $this->dispatch('print-label', label: $result->response->labelData, orientation: $result->response->labelOrientation ?? 'portrait', format: $result->response->labelFormat ?? 'pdf', dpi: $result->response->labelDpi);
            }

            $this->notifySuccess(
                'Shipped',
                "Tracking: {$result->response->trackingNumber} via {$result->response->carrier} ({$result->selectedRate->serviceName}) - \$".number_format($result->response->cost, 2)
            );

            $this->resetForm();

        } catch (\Saloon\Exceptions\Request\Statuses\RequestTimeOutException $e) {
            if ($package?->exists && $package->status !== PackageStatus::Shipped) {
                $shipment = $package->shipment;
                $package->packageItems()->delete();
                $package->delete();
                $shipment->delete();
            }
            logger()->error('ManualShip timeout', ['package_id' => $package?->id]);
            $this->notifyError('Carrier Timeout', 'The carrier API is not responding. Please try again in a few moments.');
        } catch (\Saloon\Exceptions\Request\RequestException $e) {
            if ($package?->exists && $package->status !== PackageStatus::Shipped) {
                $shipment = $package->shipment;
                $package->packageItems()->delete();
                $package->delete();
                $shipment->delete();
            }
            logger()->error('ManualShip carrier error', ['package_id' => $package?->id, 'error' => $e->getMessage()]);
            $this->notifyError('Carrier Error', 'Unable to connect to the carrier. Please try again.');
        } catch (\RuntimeException $e) {
            logger()->warning('ManualShip race condition', ['package_id' => $package?->id, 'error' => $e->getMessage()]);
            $this->notifyError('Package State Changed', $e->getMessage());
        } catch (\Exception $e) {
            if ($package?->exists && $package->status !== PackageStatus::Shipped) {
                $shipment = $package->shipment;
                $package->packageItems()->delete();
                $package->delete();
                $shipment->delete();
            }
            logger()->error('ManualShip error', ['error' => $e->getMessage()]);
            $this->notifyError('Error', 'An unexpected error occurred. Please try again.');
        }
    }

    private function createShipmentAndPackage(): Package
    {
        return DB::transaction(function () {
            $manualChannel = Channel::where('channel_reference', 'manual')->first();

            $shipment = Shipment::create([
                'shipment_reference' => $this->shipmentReference ?: null,
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'company' => $this->company ?: null,
                'address1' => $this->address1,
                'address2' => $this->address2 ?: null,
                'city' => $this->city,
                'state_or_province' => $this->stateOrProvince,
                'postal_code' => $this->postalCode,
                'country' => $this->country,
                'phone' => $this->phone ?: null,
                'email' => $this->email ?: null,
                'shipping_method_id' => $this->shippingMethodId,
                'channel_id' => $manualChannel?->id,
                'status' => 'open',
            ]);

            // Validate address (non-blocking)
            try {
                app(AddressValidationService::class)->validate($shipment);
                $shipment->refresh();

                if ($shipment->deliverability && $shipment->deliverability !== \App\Enums\Deliverability::Yes) {
                    $this->notifyWarning('Address Warning', $shipment->validation_message ?? 'Address may not be deliverable.');
                }
            } catch (\Exception $e) {
                logger()->warning('ManualShip address validation failed', ['error' => $e->getMessage()]);
            }

            $package = Package::create([
                'shipment_id' => $shipment->id,
                'box_size_id' => $this->boxSizeId,
                'weight' => $this->weight,
                'height' => $this->height,
                'width' => $this->width,
                'length' => $this->length,
            ]);

            PackageCreated::dispatch($package, $shipment);

            return $package;
        });
    }

    private function validateForm(): bool
    {
        if (empty($this->firstName) && empty($this->lastName) && empty($this->company)) {
            $this->notifyError('Missing Info', 'Please enter a name or company.');

            return false;
        }

        if (empty($this->address1)) {
            $this->notifyError('Missing Info', 'Please enter an address.');

            return false;
        }

        if (empty($this->city)) {
            $this->notifyError('Missing Info', 'Please enter a city.');

            return false;
        }

        if (empty($this->country)) {
            $this->notifyError('Missing Info', 'Please enter a country.');

            return false;
        }

        if ($this->boxSizeId !== null && ! BoxSize::where('id', $this->boxSizeId)->exists()) {
            $this->notifyError('Invalid Box Size', 'The selected box size does not exist.');

            return false;
        }

        if (empty($this->weight) || $this->weight <= 0) {
            $this->notifyError('Missing Info', 'Please enter a weight.');

            return false;
        }

        if (empty($this->height) || $this->height <= 0 || empty($this->width) || $this->width <= 0 || empty($this->length) || $this->length <= 0) {
            $this->notifyError('Missing Info', 'Please enter all dimensions.');

            return false;
        }

        return true;
    }

    private function resetForm(): void
    {
        $this->shipmentReference = '';
        $this->firstName = '';
        $this->lastName = '';
        $this->company = '';
        $this->address1 = '';
        $this->address2 = '';
        $this->city = '';
        $this->stateOrProvince = '';
        $this->postalCode = '';
        $this->country = 'US';
        $this->phone = '';
        $this->email = '';
        $this->shippingMethodId = null;
        $this->boxSizeId = null;
        $this->weight = '';
        $this->height = '';
        $this->width = '';
        $this->length = '';

        $this->dispatch('form-reset');
    }

    public function getShippingMethodOptions(): array
    {
        return \App\Models\ShippingMethod::where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function reprintLastLabel(): void
    {
        $packageId = Session::get('last_shipped_package_id');

        if (! $packageId) {
            $this->notifyError('No Label to Reprint', 'No package has been shipped in this session.');

            return;
        }

        $package = Package::find($packageId);

        if (! $package || $package->status !== PackageStatus::Shipped || ! $package->label_data) {
            $this->notifyError('Label Not Available', 'The label for the last shipped package is not available.');

            return;
        }

        $this->dispatch('print-label', label: $package->label_data, orientation: $package->label_orientation ?? 'portrait', format: $package->label_format ?? 'pdf', dpi: $package->label_dpi);
        $this->notifySuccess('Label Reprinted', "Reprinted label for tracking: {$package->tracking_number}");
    }
}
