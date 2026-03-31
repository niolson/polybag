<?php

namespace App\Filament\Pages;

use App\Enums\Deliverability;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Events\PackageCreated;
use App\Filament\Concerns\NotifiesUser;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Services\AddressValidationService;
use App\Services\AddressReferenceService;
use App\Services\CacheService;
use App\Services\LabelGenerationService;
use App\Services\SettingsService;
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
            && app(SettingsService::class)->get('manual_shipping_enabled', true);
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

    /** @var array<string, string> */
    public array $countryOptions = [];

    /** @var array<string, array<string, string>> */
    public array $subdivisionOptionsByCountry = [];

    /** @var array<string, string> */
    public array $administrativeAreaLabels = [];

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

        $addressReference = app(AddressReferenceService::class);
        $this->countryOptions = $addressReference->getCountryOptions();
        $this->subdivisionOptionsByCountry = $addressReference->getAllSubdivisionOptions();
        $this->administrativeAreaLabels = collect(array_keys($this->countryOptions))
            ->mapWithKeys(fn (string $countryCode): array => [$countryCode => $addressReference->getAdministrativeAreaLabel($countryCode)])
            ->all();
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
        $this->postalCode = $postalCode;
        $this->country = app(AddressReferenceService::class)->normalizeCountry($country) ?? strtoupper(trim($country));
        $this->stateOrProvince = app(AddressReferenceService::class)->normalizeSubdivision($this->country, $stateOrProvince) ?? '';
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
        $package = $this->createShipmentAndPackage();
        $shipment = $package->shipment;

        $result = app(LabelGenerationService::class)->autoShip(
            package: $package,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            userId: auth()->id(),
            onCleanup: fn () => $shipment->delete(),
        );

        if (! $result->success) {
            $this->notifyError($result->errorTitle, $result->errorMessage);

            return;
        }

        Session::put('last_shipped_package_id', $package->id);

        if ($result->response->labelData && ! app(SettingsService::class)->get('suppress_printing', false)) {
            $this->dispatch('print-label', label: $result->response->labelData, orientation: $result->response->labelOrientation ?? 'portrait', format: $result->response->labelFormat ?? 'pdf', dpi: $result->response->labelDpi);
        }

        $this->notifySuccess('Shipped', $result->summaryMessage());
        $this->resetForm();
    }

    private function createShipmentAndPackage(): Package
    {
        return DB::transaction(function () {
            $manualChannel = Channel::where('name', 'Manual')->first();

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

                if (! in_array($shipment->deliverability, [Deliverability::Yes, Deliverability::NotChecked], true)) {
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

        if (app(AddressReferenceService::class)->isAdministrativeAreaRequired($this->country) && empty($this->stateOrProvince)) {
            $label = app(AddressReferenceService::class)->getAdministrativeAreaLabel($this->country);
            $this->notifyError('Missing Info', "Please enter a {$label}.");

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
        return ShippingMethod::where('active', true)
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

        // Verify the current user shipped this package or has elevated permissions
        $user = auth()->user();
        if (! $user->role->isAtLeast(Role::Manager) && $package->shipped_by_user_id !== $user->id) {
            $this->notifyError('Access Denied', 'You can only reprint labels for packages you shipped.');

            return;
        }

        $this->dispatch('print-label', label: $package->label_data, orientation: $package->label_orientation ?? 'portrait', format: $package->label_format ?? 'pdf', dpi: $package->label_dpi);
        $this->notifySuccess('Label Reprinted', "Reprinted label for tracking: {$package->tracking_number}");
    }
}
