<?php

namespace App\Filament\Pages;

use App\Enums\Deliverability;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Events\PackageCreated;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Support\AddressForm;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Services\AddressValidationService;
use App\Services\LabelGenerationService;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use UnitEnum;

class ManualShip extends Page implements HasForms
{
    use InteractsWithForms;
    use NotifiesUser;

    public ?array $data = [];

    public bool $autoShipEnabled = false;

    public string $labelFormat = 'pdf';

    public ?int $labelDpi = null;

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

    public function mount(): void
    {
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
                                Forms\Components\TextInput::make('shipment_reference')
                                    ->label('Reference')
                                    ->helperText('Optional - External channel reference number')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                ...AddressForm::recipientAddressFields(
                                    includeCompany: true,
                                    includePhone: true,
                                    includeEmail: true,
                                ),
                                Forms\Components\Select::make('shipping_method_id')
                                    ->label('Shipping Method')
                                    ->options(fn (): array => $this->getShippingMethodOptions())
                                    ->searchable()
                                    ->native(false)
                                    ->placeholder('— None —')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
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
                                    ->label('Weight')
                                    ->suffix('lbs')
                                    ->helperText('Auto-fills from the connected scale when stable.')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->step(0.01),
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
                            ->columns(2),
                    ]),
            ])
            ->statePath('data');
    }

    public function ship(): void
    {
        $data = $this->form->getState();

        if (! $this->validateBusinessRules($data)) {
            return;
        }

        if ($this->autoShipEnabled && ! auth()->user()->role->isAtLeast(Role::Admin)) {
            $this->autoShipEnabled = false;
        }

        if ($this->autoShipEnabled) {
            $this->autoShip($data);

            return;
        }

        $this->manualShip($data);
    }

    private function manualShip(array $data): void
    {
        $package = $this->createShipmentAndPackage($data);

        Session::put('ship_return_url', '/manual-ship');
        $this->redirect('/ship/'.$package->id);
    }

    private function autoShip(array $data): void
    {
        $package = $this->createShipmentAndPackage($data);
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

    private function createShipmentAndPackage(array $data): Package
    {
        return DB::transaction(function () use ($data) {
            $manualChannel = Channel::where('name', 'Manual')->first();

            $shipment = Shipment::create([
                'shipment_reference' => $data['shipment_reference'] ?: null,
                'first_name' => $data['first_name'] ?: null,
                'last_name' => $data['last_name'] ?: null,
                'company' => $data['company'] ?: null,
                'address1' => $data['address1'],
                'address2' => $data['address2'] ?: null,
                'city' => $data['city'],
                'state_or_province' => $data['state_or_province'] ?: null,
                'postal_code' => $data['postal_code'] ?: null,
                'country' => $data['country'],
                'phone' => $data['phone'] ?: null,
                'email' => $data['email'] ?: null,
                'shipping_method_id' => $data['shipping_method_id'] ?: null,
                'channel_id' => $manualChannel?->id,
                'status' => 'open',
            ]);

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
                'box_size_id' => $data['box_size_id'] ?: null,
                'weight' => $data['weight'],
                'height' => $data['height'],
                'width' => $data['width'],
                'length' => $data['length'],
            ]);

            PackageCreated::dispatch($package, $shipment);

            return $package;
        });
    }

    private function validateBusinessRules(array $data): bool
    {
        if (blank($data['first_name'] ?? null) && blank($data['last_name'] ?? null) && blank($data['company'] ?? null)) {
            $this->notifyError('Missing Info', 'Please enter a name or company.');

            return false;
        }

        if (! empty($data['box_size_id']) && ! BoxSize::where('id', $data['box_size_id'])->exists()) {
            $this->notifyError('Invalid Box Size', 'The selected box size does not exist.');

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
            ->orderBy('label')
            ->get()
            ->mapWithKeys(fn (BoxSize $box): array => [
                $box->id => sprintf('%s (%s" x %s" x %s")', $box->label, $box->length, $box->width, $box->height),
            ])
            ->all();
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

        $user = auth()->user();
        if (! $user->role->isAtLeast(Role::Manager) && $package->shipped_by_user_id !== $user->id) {
            $this->notifyError('Access Denied', 'You can only reprint labels for packages you shipped.');

            return;
        }

        $this->dispatch('print-label', label: $package->label_data, orientation: $package->label_orientation ?? 'portrait', format: $package->label_format ?? 'pdf', dpi: $package->label_dpi);
        $this->notifySuccess('Label Reprinted', "Reprinted label for tracking: {$package->tracking_number}");
    }
}
