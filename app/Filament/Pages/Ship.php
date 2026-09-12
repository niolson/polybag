<?php

namespace App\Filament\Pages;

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Models\Package;
use App\Services\ShipmentLocationGuard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;

class Ship extends Page
{
    use NotifiesUser;
    use PrintsLabels;

    private const RATE_CACHE_SECONDS = 60;

    /**
     * Allow a full carrier request to finish before another request may take
     * over quote generation for the same package.
     */
    private const RATE_LOCK_SECONDS = 75;

    private const RATE_LOCK_WAIT_SECONDS = 70;

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

    public array $formRateOptionDescriptions = [];

    public ?string $deliverByDate = null;

    public bool $allRatesLate = false;

    public string $labelFormat = 'pdf';

    public ?int $labelDpi = null;

    public ?int $selectedRateIndex = null;

    /**
     * Priceless offers, listed apart from the rates and never ranked with them.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $blindPurchaseOffers = [];

    /**
     * The blind offer chosen, by its identifier. Mutually exclusive with
     * `$selectedRateIndex` — a package buys one label.
     */
    public ?string $selectedBlindOfferId = null;

    /**
     * Whether the packer has confirmed they are buying without a price or a
     * service. Reset after every attempt: consent is per purchase, not a mode
     * the page stays in.
     */
    public bool $confirmedBlindPurchase = false;

    public string $returnUrl = '/pack';

    public bool $overrideCustomsWeights = false;

    /**
     * Whether the packer has been shown that the seller declares more weight
     * for the goods than the box weighs, and asked for the purchase anyway.
     * Reset after every attempt for the same reason the blind-purchase consent
     * is: insisting once is not a setting.
     */
    public bool $overrideDeclaredWeight = false;

    /** The refusal to show in that prompt — it is the only place both weights are named. */
    public ?string $declaredWeightMessage = null;

    public function mount($package_id = null): void
    {
        $this->returnUrl = Session::pull('ship_return_url', '/pack');

        if (! $package_id) {
            $this->redirect($this->returnUrl);

            return;
        }

        $this->package = Package::with(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod', 'shipment.location', 'boxSize'])->findOrFail($package_id);

        $this->authorize('ship', $this->package);

        $locationError = app(ShipmentLocationGuard::class)->errorFor($this->package->shipment, auth()->user());

        if ($locationError !== null) {
            $this->notifyError('Location unavailable', $locationError);
            $this->package = null;
            $this->redirect($this->returnUrl);

            return;
        }

        if ($this->package->status === PackageStatus::Shipped) {
            $this->notifyWarning('Already Shipped', 'This package has already been shipped.');
            $this->redirect($this->returnUrl);

            return;
        }

        try {
            $options = $this->prepareCachedRates();
        } catch (LockTimeoutException) {
            $this->notifyWarning('Rates are being refreshed', 'Please wait a moment and try again.');

            return;
        }

        if ($options->blockingError) {
            $this->notifyError('Declared Value Required', $options->blockingError);
        }

        foreach ($options->exclusions as $exclusion) {
            $this->notifyWarning($exclusion['carrier'].' excluded', $exclusion['reason']);
        }

        $this->applyRateOptions($options);
    }

    protected function getHeaderActions(): array
    {
        if (! $this->package) {
            return [];
        }

        return [
            // Once the label is bought this page has nothing left to sell. It
            // stays open only when the label could not be printed, and the
            // one thing to offer then is another attempt at printing it.
            Action::make('Ship')
                ->action(fn () => $this->ship())
                ->icon('heroicon-o-printer')
                ->keybindings(['f12'])
                ->hidden(fn (): bool => $this->isShipped())
                ->disabled(fn (): bool => $this->selectedRateIndex === null && $this->selectedBlindOfferId === null),
            Action::make('Print again')
                ->action(fn () => $this->printStoredPackageLabel($this->package->id))
                ->icon('heroicon-o-printer')
                ->keybindings(['f12'])
                ->visible(fn (): bool => $this->isShipped()),
            Action::make('Back')
                ->action(fn () => $this->redirect($this->returnUrl))
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    public function isShipped(): bool
    {
        return $this->package?->status === PackageStatus::Shipped;
    }

    public function refreshRates(): void
    {
        if (! $this->package) {
            return;
        }

        // Explicit refresh fires fresh carrier calls; cap the rate per user so it
        // can't be scripted into hammering the carriers (or PolyBag). See issue 09.
        $throttleKey = 'ship-refresh-rates:'.(auth()->id() ?? 'guest');

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 15)) {
            $this->notifyWarning(
                'Slow down',
                'Too many rate refreshes. Please wait '.RateLimiter::availableIn($throttleKey).'s and try again.',
            );

            return;
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

        // Invalidate before acquiring the per-package lock. The request that
        // acquires it obtains fresh rates; concurrent refreshes wait and reuse
        // that fresh result instead of calling carriers in parallel.
        Cache::forget($this->rateCacheKey());

        try {
            $options = $this->prepareCachedRates();
        } catch (LockTimeoutException) {
            $this->notifyWarning('Rates are being refreshed', 'Please wait a moment and try again.');

            return;
        }

        if ($options->blockingError) {
            $this->notifyError('Declared Value Required', $options->blockingError);
        }

        $this->applyRateOptions($options);
    }

    /**
     * Cache key for a package's prepared rates, tied to the package/shipment state
     * so an edit to either produces a fresh quote rather than serving a stale one.
     */
    private function rateCacheKey(): string
    {
        return implode(':', [
            'ship-rates',
            $this->package->id,
            $this->package->updated_at->timestamp,
            $this->package->shipment?->updated_at->timestamp ?? 0,
        ]);
    }

    /**
     * Return a cached quote or have exactly one request obtain and store a new
     * quote for this package. Cache::remember() alone is not single-flight: on
     * a cold cache, concurrent requests would all invoke the carrier workflow.
     */
    private function prepareCachedRates(): PackageShippingOptions
    {
        $cacheKey = $this->rateCacheKey();
        $cached = Cache::get($cacheKey);

        if ($cached instanceof PackageShippingOptions) {
            return $cached;
        }

        return Cache::lock("{$cacheKey}:lock", self::RATE_LOCK_SECONDS)->block(
            self::RATE_LOCK_WAIT_SECONDS,
            function () use ($cacheKey): PackageShippingOptions {
                // The request that held the lock may have completed while this
                // request waited, so always check again after acquiring it.
                $cached = Cache::get($cacheKey);

                if ($cached instanceof PackageShippingOptions) {
                    return $cached;
                }

                $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

                Cache::put($cacheKey, $options, now()->addSeconds(self::RATE_CACHE_SECONDS));

                return $options;
            },
        );
    }

    private function applyRateOptions(PackageShippingOptions $options): void
    {
        $this->rateOptions = $options->rateOptions;
        $this->formRateOptionDescriptions = $options->rateOptionDescriptions;
        $this->deliverByDate = $options->deliverByDate;
        $this->allRatesLate = $options->allRatesLate;
        $this->selectedRateIndex = $options->selectedRateIndex;
        $this->blindPurchaseOffers = $options->blindPurchaseOffers;
        $this->selectedBlindOfferId = null;
        $this->confirmedBlindPurchase = false;
    }

    /**
     * The two selections are one choice, so each clears the other. A blind
     * purchase is never pre-selected — `selectedRateIndex` may arrive already
     * set from a shipping rule, and nothing sets this but a packer.
     */
    public function updatedSelectedRateIndex(): void
    {
        if ($this->selectedRateIndex !== null) {
            $this->selectedBlindOfferId = null;
        }
    }

    public function updatedSelectedBlindOfferId(): void
    {
        if ($this->selectedBlindOfferId !== null) {
            $this->selectedRateIndex = null;
            $this->confirmedBlindPurchase = false;
        }
    }

    /**
     * The chosen blind offer, matched against the offers this page produced.
     *
     * A convenience, not a control: `$blindPurchaseOffers` is public component
     * state and arrives from the browser like everything else here. The
     * workflow derives the real offer from the package before spending
     * anything — see `EloquentPackageShippingWorkflow::resolveBlindOffer()` —
     * so what this decides is which identifier to send, not what gets bought.
     */
    private function selectedBlindOffer(): ?BlindPurchaseOffer
    {
        foreach ($this->blindPurchaseOffers as $offer) {
            if (($offer['id'] ?? null) === $this->selectedBlindOfferId) {
                return BlindPurchaseOffer::fromArray($offer);
            }
        }

        return null;
    }

    public function ship(): void
    {
        if (! $this->package) {
            return;
        }

        // The workflow refuses this too, from the database. Refusing here as
        // well keeps a stale page from re-quoting carriers on its way there.
        if ($this->package->refresh()->status === PackageStatus::Shipped) {
            $this->notifyWarning('Already Shipped', 'This package already has a label. Print it again, or void it from the Packages page.');

            return;
        }

        $this->package->shipment->refresh()->load('location');
        $locationError = app(ShipmentLocationGuard::class)->errorFor($this->package->shipment, auth()->user());
        if ($locationError !== null) {
            $this->notifyError('Location unavailable', $locationError);

            return;
        }

        $blindOffer = $this->selectedBlindOfferId === null ? null : $this->selectedBlindOffer();

        if ($this->selectedBlindOfferId !== null && ! $blindOffer) {
            $this->notifyError('Offer Unavailable', 'That option is no longer being offered. Get rates again.');

            return;
        }

        if ($blindOffer && ! $this->confirmedBlindPurchase) {
            $this->dispatch('open-modal', id: 'blind-purchase-confirm');

            return;
        }

        if (! $blindOffer && ($this->selectedRateIndex === null || ! isset($this->rateOptions[$this->selectedRateIndex]))) {
            $this->notifyError('No Rate Selected', 'Please select a shipping rate.');

            return;
        }

        $request = $blindOffer
            ? new PackageShippingRequest(
                labelFormat: $this->labelFormat,
                labelDpi: $this->labelDpi,
                overrideCustomsWeights: $this->overrideCustomsWeights,
                overrideDeclaredWeight: $this->overrideDeclaredWeight,
                userId: auth()->id(),
                blindOffer: $blindOffer,
            )
            : new PackageShippingRequest(
                selectedRate: RateResponse::fromArray($this->rateOptions[$this->selectedRateIndex]),
                labelFormat: $this->labelFormat,
                labelDpi: $this->labelDpi,
                overrideCustomsWeights: $this->overrideCustomsWeights,
                userId: auth()->id(),
            );

        $result = app(PackageShippingWorkflow::class)->ship($this->package, $request);

        if ($result->requiresCustomsWeightOverride) {
            $this->dispatch('open-modal', id: 'customs-weight-override');

            return;
        }

        if ($result->requiresDeclaredWeightOverride) {
            $this->declaredWeightMessage = $result->message;
            $this->dispatch('open-modal', id: 'declared-weight-override');

            return;
        }

        $this->overrideCustomsWeights = false;
        $this->overrideDeclaredWeight = false;
        $this->declaredWeightMessage = null;
        $this->confirmedBlindPurchase = false;

        if (! $result->success) {
            // A stale offer is cured by asking again, so ask — the packer gets
            // a current list to choose from instead of an instruction to press
            // the button they just pressed. Never reached for an offer that was
            // already spent: a label may exist for that one, and re-quoting
            // would invite a second.
            if ($result->requiresRequote) {
                Cache::forget($this->rateCacheKey());
                $this->applyRateOptions($this->prepareCachedRates());
            }

            $this->notifyError($result->title ?? 'Shipping Error', $result->message ?? 'An unexpected error occurred. Please try again.');

            return;
        }

        $this->notifySuccess($result->title ?? 'Package Shipped', $result->message ?? 'Package shipped.');

        // The page re-renders in this same request, and if the print below
        // fails the browser stays here. What it shows must be the shipped
        // package, not the rate list it was bought from.
        $this->package->refresh();

        if ($result->printRequest) {
            $this->dispatchPrint($result->printRequest, redirectTo: $this->returnUrl);
        } else {
            $this->redirect($this->returnUrl);
        }
    }

    /**
     * Explicit consent to buy without a price or a service, given once per
     * purchase. ADR-0003 decision 6 requires the confirmation; resetting it
     * after every attempt is what keeps it from becoming a setting.
     */
    public function confirmBlindPurchase(): void
    {
        $this->confirmedBlindPurchase = true;
        $this->dispatch('close-modal', id: 'blind-purchase-confirm');
        $this->ship();
    }

    public function confirmCustomsWeightOverride(): void
    {
        $this->overrideCustomsWeights = true;
        $this->dispatch('close-modal', id: 'customs-weight-override');
        $this->ship();
    }

    /**
     * Buy at the weight on the scale, knowing the seller declares more.
     *
     * Sends nothing different — the refusal is waived, not the reading. What it
     * buys is the case PolyBag cannot see: a catalogue corrected in the Shopify
     * admin between the refusal and this click, which our own copy of the
     * numbers would not know about.
     */
    public function confirmDeclaredWeightOverride(): void
    {
        $this->overrideDeclaredWeight = true;
        $this->dispatch('close-modal', id: 'declared-weight-override');
        $this->ship();
    }
}
