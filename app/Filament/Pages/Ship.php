<?php

namespace App\Filament\Pages;

use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\RateQuoteLogger;
use App\Services\RuleEvaluator;
use App\Services\SettingsService;
use App\Services\ShippingRateService;
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

        $rates = app(ShippingRateService::class)->getShippingRates($this->package->id);

        // Apply shipping rules (exclude services, etc.)
        $ruleResult = app(RuleEvaluator::class)->evaluate($this->package->shipment);
        if ($ruleResult->shouldFilterRates()) {
            $rates = $rates->reject(
                fn (RateResponse $rate) => in_array($rate->serviceCode, $ruleResult->excludedServiceCodes)
            );
        }

        $this->rateOptions = $rates->values()->map->toArray()->all();
        $this->updateFormData();

        // If a rule pre-selects a rate, auto-select the matching service in the form
        if ($ruleResult->hasPreSelectedRate()) {
            $preSelected = $ruleResult->preSelectedRate;
            foreach ($this->rateOptions as $key => $rateArray) {
                if ($rateArray['carrier'] === $preSelected->carrier && $rateArray['serviceCode'] === $preSelected->serviceCode) {
                    $this->form->fill(['rateOptions' => $key]);
                    break;
                }
            }
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

    public function updateFormData(): void
    {
        $deadline = $this->package?->shipment->getDeliverByDate();
        $this->deliverByDate = $deadline?->format('D, M j');

        $formRateOptionLabels = [];
        $formRateOptionDescriptions = [];
        $onTimeKeys = [];
        $lateKeys = [];

        foreach ($this->rateOptions as $key => $rateArray) {
            $rate = RateResponse::fromArray($rateArray);
            $formRateOptionLabels[$key] = $rate->formLabel();
            $description = $rate->formDescription();

            if ($deadline) {
                $parsed = $rate->parsedDeliveryDate();
                if ($parsed && $parsed->gt($deadline)) {
                    $description .= ' — LATE';
                    $lateKeys[] = $key;
                } elseif (! $parsed) {
                    // Unknown delivery date with a deadline — mark uncertain
                    $lateKeys[] = $key;
                } else {
                    $onTimeKeys[] = $key;
                }
            } else {
                $onTimeKeys[] = $key;
            }

            $formRateOptionDescriptions[$key] = $description;
        }

        // Sort: on-time first (by price), then late (by price)
        $sortedKeys = collect($onTimeKeys)
            ->sortBy(fn ($key) => $this->rateOptions[$key]['price'])
            ->merge(collect($lateKeys)->sortBy(fn ($key) => $this->rateOptions[$key]['price']))
            ->values();

        $sortedLabels = [];
        $sortedDescriptions = [];
        $sortedOptions = [];
        foreach ($sortedKeys as $newKey => $oldKey) {
            $sortedLabels[$newKey] = $formRateOptionLabels[$oldKey];
            $sortedDescriptions[$newKey] = $formRateOptionDescriptions[$oldKey];
            $sortedOptions[$newKey] = $this->rateOptions[$oldKey];
        }

        $this->rateOptions = $sortedOptions;
        $this->formRateOptionLabels = $sortedLabels;
        $this->formRateOptionDescriptions = $sortedDescriptions;
        $this->allRatesLate = $deadline && empty($onTimeKeys) && ! empty($lateKeys);

        // Default to cheapest on-time rate, or cheapest overall if all late
        if (! empty($this->rateOptions)) {
            $defaultKey = collect($this->rateOptions)->sortBy('price')->keys()->first();

            if (! empty($onTimeKeys)) {
                // Find the first on-time key (already sorted by price in sortedOptions)
                foreach ($sortedOptions as $key => $option) {
                    if (in_array($key, array_keys($sortedLabels)) && ! str_contains($sortedDescriptions[$key] ?? '', 'LATE')) {
                        $defaultKey = $key;
                        break;
                    }
                }
            }

            $this->form->fill(['rateOptions' => $defaultKey]);
        }
    }

    public function ship(): void
    {
        $formData = $this->form->getState();
        $selectedOptionKey = $formData['rateOptions'];
        $rate = $this->rateOptions[$selectedOptionKey];

        $selectedRate = RateResponse::fromArray($rate);

        app(RateQuoteLogger::class)->markSelected($this->package->id, $selectedRate);

        try {
            $adapter = app(CarrierRegistry::class)->get($selectedRate->carrier);

            $shipRequest = ShipRequest::fromPackageAndRate($this->package, $selectedRate, $this->labelFormat, $this->labelDpi);
            $response = $adapter->createShipment($shipRequest);

            if (! $response->success) {
                $this->notifyError('Shipping Error', $response->errorMessage ?? 'Failed to create shipment.');

                return;
            }

            $this->package->markShipped($response, auth()->id());

            $this->notifySuccess('Package Shipped', "Tracking: {$response->trackingNumber}");

            if ($response->labelData && ! app(SettingsService::class)->get('suppress_printing', false)) {
                $this->dispatch('print-label',
                    label: $response->labelData,
                    orientation: $response->labelOrientation ?? 'portrait',
                    format: $response->labelFormat ?? 'pdf',
                    dpi: $response->labelDpi,
                    redirectTo: $this->returnUrl,
                );
            } elseif ($response->labelData) {
                $this->notifyInfo('Label printing suppressed (sandbox mode)');
                $this->redirect($this->returnUrl);
            } else {
                $this->redirect($this->returnUrl);
            }

        } catch (\Saloon\Exceptions\Request\Statuses\RequestTimeOutException $e) {
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
}
