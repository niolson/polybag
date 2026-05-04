<?php

namespace App\Filament\Pages;

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Models\Package;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Session;

class Ship extends Page implements HasForms
{
    use InteractsWithForms;
    use NotifiesUser;
    use PrintsLabels;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'ship/{package_id?}';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::User) ?? false;
    }

    protected string $view = 'filament.pages.ship';

    public ?Package $package = null;

    /** @var array<int, array<string, mixed>> */
    public array $rateOptions = [];

    public array $formRateOptionLabels = [];

    public array $formRateOptionDescriptions = [];

    public ?string $deliverByDate = null;

    public bool $allRatesLate = false;

    public string $labelFormat = 'pdf';

    public ?int $labelDpi = null;

    public ?array $data = [];

    public string $returnUrl = '/pack';

    public bool $overrideCustomsWeights = false;

    public function mount($package_id = null): void
    {
        $this->returnUrl = Session::pull('ship_return_url', '/pack');

        if (! $package_id) {
            $this->redirect($this->returnUrl);

            return;
        }

        $this->package = Package::with(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod', 'boxSize'])->findOrFail($package_id);

        $this->authorize('ship', $this->package);

        if ($this->package->status === PackageStatus::Shipped) {
            $this->notifyWarning('Already Shipped', 'This package has already been shipped.');
            $this->redirect($this->returnUrl);

            return;
        }

        $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

        foreach ($options->exclusions as $exclusion) {
            $this->notifyWarning($exclusion['carrier'].' excluded', $exclusion['reason']);
        }

        $this->rateOptions = $options->rateOptions;
        $this->formRateOptionLabels = $options->rateOptionLabels;
        $this->formRateOptionDescriptions = $options->rateOptionDescriptions;
        $this->deliverByDate = $options->deliverByDate;
        $this->allRatesLate = $options->allRatesLate;

        if ($options->selectedRateIndex !== null) {
            $this->form->fill(['rateOptions' => $options->selectedRateIndex]);
        }
    }

    protected function getHeaderActions(): array
    {
        if (! $this->package) {
            return [];
        }

        return [
            Action::make('Ship')
                ->action(fn () => $this->ship())
                ->icon('heroicon-o-printer')
                ->keybindings(['f12'])
                ->disabled(fn () => empty($this->rateOptions)),
            Action::make('Back')
                ->action(fn () => $this->redirect($this->returnUrl))
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Radio::make('rateOptions')
                    ->label('Select Shipping Rate')
                    ->options($this->formRateOptionLabels)
                    ->descriptions($this->formRateOptionDescriptions)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function refreshRates(): void
    {
        if (! $this->package) {
            return;
        }

        $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

        $this->rateOptions = $options->rateOptions;
        $this->formRateOptionLabels = $options->rateOptionLabels;
        $this->formRateOptionDescriptions = $options->rateOptionDescriptions;
        $this->deliverByDate = $options->deliverByDate;
        $this->allRatesLate = $options->allRatesLate;

        if ($options->selectedRateIndex !== null) {
            $this->form->fill(['rateOptions' => $options->selectedRateIndex]);
        }
    }

    public function ship(): void
    {
        $formData = $this->form->getState();
        $selectedOptionKey = $formData['rateOptions'];
        $rate = $this->rateOptions[$selectedOptionKey];

        $selectedRate = RateResponse::fromArray($rate);

        $result = app(PackageShippingWorkflow::class)->ship(
            $this->package,
            new PackageShippingRequest(
                selectedRate: $selectedRate,
                labelFormat: $this->labelFormat,
                labelDpi: $this->labelDpi,
                overrideCustomsWeights: $this->overrideCustomsWeights,
                userId: auth()->id(),
            ),
        );

        if ($result->requiresCustomsWeightOverride) {
            $this->dispatch('open-modal', id: 'customs-weight-override');

            return;
        }

        $this->overrideCustomsWeights = false;

        if (! $result->success) {
            $this->notifyError($result->title ?? 'Shipping Error', $result->message ?? 'An unexpected error occurred. Please try again.');

            return;
        }

        $this->notifySuccess($result->title ?? 'Package Shipped', $result->message ?? 'Package shipped.');

        if ($result->printRequest) {
            $this->dispatchPrint($result->printRequest, redirectTo: $this->returnUrl);
        } else {
            $this->redirect($this->returnUrl);
        }
    }

    public function confirmCustomsWeightOverride(): void
    {
        $this->overrideCustomsWeights = true;
        $this->dispatch('close-modal', id: 'customs-weight-override');
        $this->ship();
    }
}
