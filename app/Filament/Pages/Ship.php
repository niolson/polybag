<?php

namespace App\Filament\Pages;

use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\SettingsService;
use App\Services\ShippingRateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class Ship extends Page implements HasForms
{
    use InteractsWithForms;
    use NotifiesUser;

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

    public ?array $data = [];

    public function mount($package_id = null): void
    {
        if (! $package_id) {
            $this->redirect('/pack');

            return;
        }

        $this->package = Package::with(['packageItems.product', 'packageItems.shipmentItem', 'shipment', 'boxSize'])->findOrFail($package_id);

        if ($this->package->shipped) {
            $this->notifyWarning('Already Shipped', 'This package has already been shipped.');
            $this->redirect('/pack');

            return;
        }

        $rates = ShippingRateService::getShippingRates($this->package->id);
        $this->rateOptions = $rates->map->toArray()->all();
        $this->updateFormData();
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
            Action::make('Back to Pack')
                ->action(fn () => $this->redirect('/pack'))
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

    public function updateFormData(): void
    {
        $formRateOptionLabels = [];
        $formRateOptionDescriptions = [];

        foreach ($this->rateOptions as $key => $rateArray) {
            $rate = RateResponse::fromArray($rateArray);
            $formRateOptionLabels[$key] = $rate->formLabel();
            $formRateOptionDescriptions[$key] = $rate->formDescription();
        }

        $this->formRateOptionLabels = $formRateOptionLabels;
        $this->formRateOptionDescriptions = $formRateOptionDescriptions;

        if (! empty($this->rateOptions)) {
            $cheapestKey = collect($this->rateOptions)->sortBy('price')->keys()->first();
            $this->form->fill(['rateOptions' => $cheapestKey]);
        }
    }

    public function ship(): void
    {
        $formData = $this->form->getState();
        $selectedOptionKey = $formData['rateOptions'];
        $rate = $this->rateOptions[$selectedOptionKey];

        $selectedRate = RateResponse::fromArray($rate);

        try {
            $adapter = CarrierRegistry::get($selectedRate->carrier);

            $shipRequest = ShipRequest::fromPackageAndRate($this->package, $selectedRate);
            $response = $adapter->createShipment($shipRequest);

            if (! $response->success) {
                $this->notifyError('Shipping Error', $response->errorMessage ?? 'Failed to create shipment.');

                return;
            }

            $this->package->markShipped($response, auth()->id());

            if ($response->labelData && ! SettingsService::get('suppress_printing', false)) {
                $this->printLabel($response->labelData, $response->labelOrientation ?? 'portrait');
            } elseif ($response->labelData) {
                $this->notifyInfo('Label printing suppressed (sandbox mode)');
            }

            $this->notifySuccess('Package Shipped', "Tracking: {$response->trackingNumber}");

            $this->redirect('/pack');

        } catch (\Saloon\Exceptions\Request\Statuses\RequestTimeoutException $e) {
            logger()->error('Carrier API timeout', [
                'carrier' => $selectedRate->carrier,
                'package_id' => $this->package->id,
            ]);
            $this->notifyError(
                'Carrier Timeout',
                "The {$selectedRate->carrier} API is not responding. Please try again in a few moments."
            );
        } catch (\Saloon\Exceptions\Request\RequestException $e) {
            logger()->error('Carrier API error', [
                'carrier' => $selectedRate->carrier,
                'package_id' => $this->package->id,
                'error' => $e->getMessage(),
            ]);
            $this->notifyError(
                'Carrier Error',
                "Unable to connect to {$selectedRate->carrier}. Please check your connection and try again."
            );
        } catch (\RuntimeException $e) {
            // Optimistic locking failure
            $this->notifyError('Package State Changed', $e->getMessage());
        } catch (\Exception $e) {
            logger()->error('Shipping error', [
                'package_id' => $this->package->id,
                'error' => $e->getMessage(),
            ]);
            $this->notifyError('Shipping Error', 'An unexpected error occurred. Please try again.');
        }
    }

    private function printLabel(string $base64Label, string $orientation = 'portrait'): void
    {
        $this->dispatch('print-label', label: $base64Label, orientation: $orientation);
    }
}
