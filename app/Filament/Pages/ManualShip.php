<?php

namespace App\Filament\Pages;

use App\Contracts\PackageDraftWorkflow;
use App\DataTransferObjects\PackageDrafts\Measurements;
use App\DataTransferObjects\PackageDrafts\PackageDraftInput;
use App\DataTransferObjects\PackageDrafts\PackageDraftOptions;
use App\DataTransferObjects\PrintRequest;
use App\Enums\Deliverability;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Exceptions\PackageDraftIncompleteException;
use App\Exceptions\PackageDraftInvalidException;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Filament\Support\AddressForm;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Services\AddressValidationService;
use App\Services\LabelGenerationService;
use App\Services\PackagingService;
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
    use PrintsLabels;

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

        try {
            if ($this->autoShipEnabled) {
                $this->autoShip($data);

                return;
            }

            $this->manualShip($data);
        } catch (PackageDraftIncompleteException|PackageDraftInvalidException $e) {
            $this->notifyError('Not Ready', $e->getMessage());
        }
    }

    private function manualShip(array $data): void
    {
        ['shipment' => $shipment, 'package' => $package] = $this->createShipmentAndPackage($data);

        Session::put('ship_return_url', '/manual-ship');
        $this->redirect('/ship/'.$package->id);
    }

    private function autoShip(array $data): void
    {
        ['shipment' => $shipment, 'package' => $package] = $this->createShipmentAndPackage($data);

        $result = app(LabelGenerationService::class)->autoShip(
            package: $package,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            userId: auth()->id(),
        );

        if (! $result->success) {
            $this->notifyError($result->errorTitle, $result->errorMessage);

            return;
        }

        Session::put('last_shipped_package_id', $package->id);

        if ($result->response->labelData) {
            $this->dispatchPrint(PrintRequest::fromShipResponse($result->response));
        }

        $this->notifySuccess('Shipped', $result->summaryMessage());
        $this->resetForm();
    }

    /**
     * Create a shipment and package, with optional address validation.
     * Returns both so callers can reference the shipment (e.g. for cleanup on failure).
     *
     * @return array{shipment: Shipment, package: Package}
     */
    private function createShipmentAndPackage(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $channelId = Channel::where('name', 'Manual')->first()?->id;
            $shipment = app(PackagingService::class)->createShipment($data, $channelId);

            try {
                app(AddressValidationService::class)->validate($shipment);
                $shipment->refresh();

                if (! in_array($shipment->deliverability, [Deliverability::Yes, Deliverability::NotChecked], true)) {
                    $this->notifyWarning('Address Warning', $shipment->validation_message ?? 'Address may not be deliverable.');
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
                ),
                options: $options,
            );
            $ready = app(PackageDraftWorkflow::class)->assertReadyToShip(
                shipment: $shipment,
                packageDraftId: $draft->packageDraftId,
                options: $options,
            );

            return ['shipment' => $shipment, 'package' => $ready->package];
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

        $this->dispatchPrint(PrintRequest::fromPackage($package));
        $this->notifySuccess('Label Reprinted', "Reprinted label for tracking: {$package->tracking_number}");
    }
}
