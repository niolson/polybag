<?php

namespace App\Filament\Pages;

use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\AppMode;
use App\Enums\PackageStatus;
use App\Enums\PostageSourceKind;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Exceptions\PackageDraftIncompleteException;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Filament\Resources\ShipmentResource;
use App\Filament\Support\ResolveUnaccountedPurchaseAction;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\Package;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\Customs\CustomsReadiness;
use App\Services\PackageLabels\SessionLastLabel;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\UnresolvedPurchaseResolver;
use App\Services\ShipmentLocationGuard;
use App\Services\ShopifyShippingLabelService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

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

    /**
     * Shown in Demo beside Amazon Buy Shipping rates, which come from the
     * sandbox while the order was read from production (`live-store-demo/03`).
     */
    public const CANNED_AMAZON_RATES_NOTICE = 'Amazon\'s sandbox returns the same fixed offers whatever the order, so these Amazon rates are not this order\'s. A Label bought from them is a sandbox specimen.';

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

    /**
     * Why quoted rates were dropped for the Shipment's customs terms, each
     * with the fix when there is one place to make it (ADR-0008 decision 4).
     *
     * @var list<array{carrier: string|null, reason: string, fixUrl: string|null, fixLabel: string|null}>
     */
    public array $droppedRates = [];

    /**
     * The customs readiness findings, previewed before any rate is fetched
     * (ADR-0008 decision 7). The purchase runs the same check and refuses on
     * its blocks; these are what the packer sees first.
     *
     * @var list<array{severity: string, code: string, title: string, message: string, lines: list<string>, fixUrl: string|null, fixLabel: string|null}>
     */
    public array $customsFindings = [];

    /**
     * Sellers asked for a rate that gave none, named so a short list does not
     * read as a broken carrier.
     *
     * @var list<string>
     */
    public array $unratedSources = [];

    /** Rates were quoted and the customs terms dropped every one. */
    public bool $allRatesDroppedForCustomsTerms = false;

    public string $labelFormat = 'pdf';

    public ?int $labelDpi = null;

    /**
     * Whether this workstation has a report printer, pushed from
     * `localStorage.reportPrinter` on mount the way label format is. A
     * purchase that returns a separate customs document is refused without one.
     */
    public bool $hasReportPrinter = false;

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

    public ?string $customsWeightMessage = null;

    /**
     * The method chosen on this page for a shipment that has none. Nothing is
     * rated until one is saved to the shipment (`carrier-catalog-reset/16`).
     */
    public ?int $shippingMethodId = null;

    /**
     * The last purchase attempt's refusal, kept on the page beside the rates.
     * A toast is easy to miss, and a label sold but not recorded must not look
     * like nothing happened.
     *
     * @var array{title: string, message: string}|null
     */
    public ?array $purchaseError = null;

    public function getTitle(): string|Htmlable
    {
        $reference = $this->package?->shipment?->shipment_reference;

        return filled($reference) ? "Ship {$reference}" : 'Ship';
    }

    /**
     * Who the parcel is for, and the way back to the order and to packing.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $shipment = $this->package?->shipment;

        if ($shipment === null) {
            return null;
        }

        $recipient = trim(implode(' ', array_filter([$shipment->first_name, $shipment->last_name])));
        $place = implode(', ', array_filter([$shipment->city, $shipment->state_or_province, $shipment->country === 'US' ? null : $shipment->country]));

        $links = ['<a href="'.e(Pack::getUrl(['shipment_id' => $shipment->id])).'" class="text-primary-600 hover:underline dark:text-primary-400">Back to Pack</a>'];

        if (ShipmentResource::canView($shipment)) {
            $links[] = '<a href="'.e(ShipmentResource::getUrl('view', ['record' => $shipment])).'" class="text-primary-600 hover:underline dark:text-primary-400">View Shipment</a>';
        }

        return new HtmlString(
            e(implode(' · ', array_filter([$recipient, $place, "Package {$this->package->id}"])))
                .' — '.implode(' · ', $links),
        );
    }

    /**
     * The earliest purchase on this package that nothing accounts for: a label
     * sold but never saved, or a purchase nobody heard back about.
     */
    private function earlierPurchase(): ?ShippingOffer
    {
        if ($this->package === null) {
            return null;
        }

        $offers = app(OfferStore::class);

        return $offers->boughtButUnrecorded($this->package)->first()
            ?? $offers->awaitingPurchaseConfirmation($this->package)->first();
    }

    /**
     * Whether asking now can settle the earlier purchase: the source can be
     * asked, or nothing says a label was sold. A sold label its source cannot
     * hand over again is a manager's to void or keep, on the package page.
     */
    public function canCheckEarlierPurchase(): bool
    {
        $offer = $this->earlierPurchase();

        if ($offer === null || $this->isShipped() || ResolveUnaccountedPurchaseAction::unrecoverableReason($offer) !== null) {
            return false;
        }

        return ! $this->earlierPurchaseWasSold($offer) || app(UnresolvedPurchaseResolver::class)->canBeAsked($offer);
    }

    private function earlierPurchaseWasSold(ShippingOffer $offer): bool
    {
        return $offer->isKnownSold() || is_string($offer->purchase_context[OfferStore::REPORTED_TRACKING_NUMBER] ?? null);
    }

    /**
     * What an earlier attempt left on this package, and the way to settle it.
     * Read fresh, so it says so after a reload too.
     */
    public function unfinishedPurchaseNotice(): ?string
    {
        if ($this->package === null || $this->isShipped() || $this->purchaseError !== null) {
            return null;
        }

        $offer = $this->earlierPurchase();

        if ($offer === null) {
            return $this->unfinishedShopifyPurchaseNotice();
        }

        $seller = $offer->sellerName();
        $tracking = ResolveUnaccountedPurchaseAction::knownTrackingNumber($offer);
        $label = $tracking !== null ? "label {$tracking}" : 'a label';
        $sold = $this->earlierPurchaseWasSold($offer);

        return match (true) {
            ResolveUnaccountedPurchaseAction::unrecoverableReason($offer) !== null,
            $sold && ! $this->canCheckEarlierPurchase() => "{$seller} sold {$label} for this package that PolyBag did not save, and cannot fetch it again. "
                .'Do not buy another label: a manager can void or keep this one on the package page.',
            $sold => "{$seller} sold {$label} for this package that PolyBag did not save. "
                ."Use Check Earlier Purchase to fetch it from {$seller}; no second label is bought.",
            default => "PolyBag tried to buy a label for this package and never heard back from {$seller}, so it may already exist. "
                .'Use Check Earlier Purchase: it fetches the label if one was made, and otherwise lets a new one be bought.',
        };
    }

    /**
     * A Shopify blind purchase keeps no offer; its record is the markers on
     * the package. Buying through Shopify again picks that purchase up rather
     * than starting another ({@see ShopifyShippingLabelService::purchase()}).
     */
    private function unfinishedShopifyPurchaseNotice(): ?string
    {
        $held = ShopifyAdapter::whereHoldsPurchase(Package::query()->whereKey($this->package->id))->exists();

        if (! $held) {
            return null;
        }

        return $this->blindPurchaseOffers !== []
            ? 'Shopify accepted a label purchase for this package that PolyBag has not finished recording. '
                .'Choose Shopify Shipping below and buy: PolyBag picks up that purchase from Shopify instead of buying another.'
            : 'Shopify accepted a label purchase for this package that PolyBag has not finished recording, and Shopify Shipping is not offered now. '
                .'Do not buy another label: a manager can check the order in the Shopify admin.';
    }

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
            $this->notifyError('Location Unavailable', $locationError);
            $this->package = null;
            $this->redirect($this->returnUrl);

            return;
        }

        if ($this->package->status === PackageStatus::Shipped) {
            $this->notifyWarning('Already Shipped', PackageShippingResult::alreadyShippedMessage($this->package->tracking_number));
            $this->redirect($this->returnUrl);

            return;
        }

        // The purchase refuses these too. Turning them away here sends the
        // packer to finish the package, instead of quoting rates for it.
        if ($this->package->shipment->status === ShipmentStatus::Cancelled) {
            $this->notifyError('Shipment Cancelled', 'This shipment is cancelled, so no label can be bought for it.');
            $this->package = null;
            $this->redirect($this->returnUrl);

            return;
        }

        if ($this->package->shipment->isBlockedByPicking()) {
            $this->notifyError('Picking Required', "Shipment {$this->package->shipment->shipment_reference} has not been picked yet.");
            $this->package = null;
            $this->redirect($this->returnUrl);

            return;
        }

        try {
            app(PackageDraftWorkflow::class)->assertPackageReadyToShip($this->package);
        } catch (PackageDraftIncompleteException $e) {
            $this->notifyWarning('Not Ready', $e->getMessage().' Finish packing it first.');
            $shipmentId = $this->package->shipment_id;
            $this->package = null;
            $this->redirect('/pack/'.$shipmentId);

            return;
        }

        // Nothing is rated for a shipment with no method. The page shows the
        // problem, and a method picker to whoever may edit the shipment.
        if ($this->needsShippingMethod()) {
            return;
        }

        $this->rate();
    }

    /**
     * Quote the package on mount, or once a method has been chosen for it.
     */
    private function rate(): void
    {
        $this->previewCustomsReadiness();

        try {
            $options = $this->prepareCachedRates();
        } catch (LockTimeoutException) {
            $this->notifyWarning('Rates Are Being Refreshed', 'Please wait a moment and try again.');

            return;
        }

        if ($options->blockingError) {
            $this->notifyError('Declared Value Required', $options->blockingError);
        }

        foreach ($options->exclusions as $exclusion) {
            $this->notifyWarning($exclusion['carrier'].' excluded', $exclusion['reason']);
        }

        if ($this->showsCannedAmazonRates($options)) {
            $this->notifyWarning('Amazon sandbox rates', self::CANNED_AMAZON_RATES_NOTICE);
        }

        $this->applyRateOptions($options);
    }

    /**
     * Whether these are Amazon Buy Shipping rates from the sandbox for an
     * Amazon order, read in Demo from production (`live-store-demo/03`). The
     * sandbox answers every order with the same offers, so the page says so
     * rather than let them pass for this order's.
     */
    private function showsCannedAmazonRates(PackageShippingOptions $options): bool
    {
        return AppMode::current() === AppMode::Demo
            && collect($options->rateOptions)->contains(
                fn (array $rate): bool => ($rate['observedService']['source'] ?? null) === PostageSourceKind::Amazon->value
            );
    }

    /**
     * Run the customs readiness check and keep its findings for the page, ahead
     * of the rates. Read fresh each time: it costs no carrier call, and a fix
     * made on the product or the Shipment shows on the next refresh.
     */
    private function previewCustomsReadiness(): void
    {
        $this->customsFindings = array_map(
            fn ($finding): array => $finding->toArray(),
            app(CustomsReadiness::class)->check($this->package),
        );
    }

    protected function getHeaderActions(): array
    {
        if (! $this->package) {
            return [];
        }

        return [
            // What every "Get rates again" message points at: a stale or
            // refused rate is cured by quoting again.
            Action::make('getRatesAgain')
                ->label('Get Rates Again')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->refreshRates())
                ->hidden(fn (): bool => $this->isShipped() || $this->needsShippingMethod()),
            // Once the label is bought this page has nothing left to sell. It
            // stays open only when the label could not be printed, and the
            // one thing to offer then is another attempt at printing it.
            Action::make('Ship')
                ->label('Buy & Print Label')
                ->action(fn () => $this->ship())
                ->icon('heroicon-o-printer')
                ->keybindings(['f12'])
                ->hidden(fn (): bool => $this->isShipped())
                ->disabled(fn (): bool => $this->selectedRateIndex === null && $this->selectedBlindOfferId === null),
            // Settles an earlier purchase without a rate: none may come back
            // while one is unaccounted for, and Buy needs a selection.
            Action::make('checkEarlierPurchase')
                ->label('Check Earlier Purchase')
                ->icon('heroicon-o-magnifying-glass')
                ->color('warning')
                ->action(fn () => $this->checkEarlierPurchase())
                ->visible(fn (): bool => $this->canCheckEarlierPurchase()),
            Action::make('Print again')
                ->label('Reprint Label')
                ->action(fn () => $this->printStoredPackageLabel($this->package->id))
                ->icon('heroicon-o-printer')
                ->keybindings(['f12'])
                ->visible(fn (): bool => $this->isShipped()),
        ];
    }

    public function isShipped(): bool
    {
        return $this->package?->status === PackageStatus::Shipped;
    }

    public function needsShippingMethod(): bool
    {
        return $this->package?->shipment?->needsShippingMethod() ?? false;
    }

    /**
     * Whether this user may choose the method here: whoever may edit the
     * shipment.
     */
    public function canAssignShippingMethod(): bool
    {
        return $this->package?->shipment !== null
            && (auth()->user()?->can('update', $this->package->shipment) ?? false);
    }

    /**
     * @return array<int, string>
     */
    public function getShippingMethodOptions(): array
    {
        return ShippingMethod::query()
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Save the chosen method to the shipment and rate the package with it.
     */
    public function assignShippingMethod(): void
    {
        if (! $this->package || ! $this->needsShippingMethod()) {
            return;
        }

        $shipment = $this->package->shipment;

        $this->authorize('update', $shipment);

        $this->validate([
            'shippingMethodId' => ['required', Rule::exists('shipping_methods', 'id')->where('active', true)],
        ], [], ['shippingMethodId' => 'shipping method']);

        $shipment->update(['shipping_method_id' => $this->shippingMethodId]);
        $shipment->load('shippingMethod');

        $this->notifySuccess('Shipping Method Set', "Rating with {$shipment->shippingMethod->name}.");

        $this->rate();
    }

    /**
     * Fresh quotes fire carrier calls, so they are capped per user whichever path
     * asks for them, and cannot be scripted into hammering the carriers (or
     * PolyBag). See issue 09. False once the cap is reached.
     */
    private function takeRateRefresh(): bool
    {
        if (RateLimiter::tooManyAttempts($this->rateRefreshThrottleKey(), maxAttempts: 15)) {
            return false;
        }

        RateLimiter::hit($this->rateRefreshThrottleKey(), decaySeconds: 60);

        return true;
    }

    private function rateRefreshThrottleKey(): string
    {
        return 'ship-refresh-rates:'.(auth()->id() ?? 'guest');
    }

    public function refreshRates(): void
    {
        if (! $this->package || $this->needsShippingMethod()) {
            return;
        }

        if (! $this->takeRateRefresh()) {
            $this->notifyWarning(
                'Slow Down',
                'Too many rate refreshes. Please wait '.RateLimiter::availableIn($this->rateRefreshThrottleKey()).'s and try again.',
            );

            return;
        }

        $this->previewCustomsReadiness();

        // Invalidate before acquiring the per-package lock. The request that
        // acquires it obtains fresh rates; concurrent refreshes wait and reuse
        // that fresh result instead of calling carriers in parallel.
        Cache::forget($this->rateCacheKey());

        try {
            $options = $this->prepareCachedRates();
        } catch (LockTimeoutException) {
            $this->notifyWarning('Rates Are Being Refreshed', 'Please wait a moment and try again.');

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
            $this->clientCustomsTermsDigest(),
        ]);
    }

    /**
     * A digest of the client's duties policy and tax registrations, which
     * decide which rates survive the customs-terms filter but live on rows the
     * Package and Shipment timestamps do not cover. Read fresh, so an edit on
     * the client form or in Settings is not served from a stale quote.
     */
    private function clientCustomsTermsDigest(): string
    {
        $clientId = $this->package->shipment?->client_id;

        if ($clientId === null) {
            return '-';
        }

        $client = Client::query()->find($clientId);

        return md5((string) json_encode([
            $client?->duties_policy,
            ClientTaxRegistration::query()
                ->where('client_id', $clientId)
                ->orderBy('regime')
                ->get(['regime', 'number'])
                ->map(fn (ClientTaxRegistration $registration): string => $registration->regime->value.':'.$registration->number)
                ->all(),
        ]));
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
        $this->droppedRates = $options->droppedRates;
        $this->allRatesDroppedForCustomsTerms = $options->allRatesDroppedForCustomsTerms;
        $this->unratedSources = $options->unratedSources;
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
        // The page renders an inactive rate unselectable; this is only the
        // guard for a selection that arrives anyway. The purchase refuses it
        // regardless.
        if ($this->selectedRateIndex !== null && isset($this->rateOptions[$this->selectedRateIndex]['inactive'])) {
            $this->selectedRateIndex = null;

            return;
        }

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

        if ($this->refusesPurchase()) {
            return;
        }

        $blindOffer = $this->selectedBlindOfferId === null ? null : $this->selectedBlindOffer();

        if ($this->selectedBlindOfferId !== null && ! $blindOffer) {
            $this->notifyError('Rate Unavailable', 'That option is no longer being offered. Get rates again.');

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
                hasReportPrinter: $this->hasReportPrinter,
            )
            : new PackageShippingRequest(
                selectedRate: RateResponse::fromArray($this->rateOptions[$this->selectedRateIndex]),
                labelFormat: $this->labelFormat,
                labelDpi: $this->labelDpi,
                overrideCustomsWeights: $this->overrideCustomsWeights,
                userId: auth()->id(),
                hasReportPrinter: $this->hasReportPrinter,
            );

        $result = app(PackageShippingWorkflow::class)->ship($this->package, $request);

        if ($result->requiresCustomsWeightOverride) {
            $this->customsWeightMessage = $result->message;
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
        $this->customsWeightMessage = null;
        $this->confirmedBlindPurchase = false;

        if (! $result->success) {
            // A stale offer is cured by asking again, so ask — the packer gets
            // a current list to choose from instead of an instruction to press
            // the button they just pressed. Never reached for an offer that was
            // already spent: a label may exist for that one, and re-quoting
            // would invite a second.
            if ($result->requiresRequote && $this->takeRateRefresh()) {
                Cache::forget($this->rateCacheKey());
                $this->applyRateOptions($this->prepareCachedRates());
            }

            $this->purchaseError = [
                'title' => $result->title ?? 'Shipping Error',
                'message' => $result->message ?? 'An unexpected error occurred. Please try again.',
            ];
            $this->notifyError($this->purchaseError['title'], $this->purchaseError['message']);
            // A label bought but not recorded is also sent to the bell; show it now, not at the next poll.
            $this->dispatch('databaseNotificationsSent');

            return;
        }

        $this->labelBought($result, 'Label Bought');
    }

    /**
     * Ask the source about the earlier purchase now: a label that comes back
     * ships the package and prints; a definite "nothing bought" frees it to be
     * bought, with fresh rates; anything else stays on the page.
     */
    public function checkEarlierPurchase(): void
    {
        if (! $this->package || $this->refusesPurchase()) {
            return;
        }

        $seller = $this->earlierPurchase()?->sellerName() ?? 'the carrier';

        $result = app(PackageShippingWorkflow::class)->checkEarlierPurchases(
            $this->package,
            $this->labelFormat,
            $this->labelDpi,
            auth()->id(),
        );

        if ($result === null) {
            $this->notifySuccess('Nothing Was Bought', "{$seller} has no label for this package, so one can be bought now.");

            if ($this->takeRateRefresh()) {
                Cache::forget($this->rateCacheKey());
                $this->applyRateOptions($this->prepareCachedRates());
            }

            return;
        }

        if (! $result->success) {
            $this->purchaseError = [
                'title' => $result->title ?? 'Still Unresolved',
                'message' => $result->message ?? "{$seller} could not say what became of the earlier purchase. Try again in a few minutes.",
            ];
            $this->notifyError($this->purchaseError['title'], $this->purchaseError['message']);
            $this->dispatch('databaseNotificationsSent');

            return;
        }

        $this->labelBought($result, 'Label Found');
    }

    /**
     * Refuse a purchase or a recovery this page may no longer make, saying
     * why. True when refused.
     */
    private function refusesPurchase(): bool
    {
        // The workflow refuses this too, from the database. Refusing here as
        // well keeps a stale page from re-quoting carriers on its way there.
        if ($this->package->refresh()->status === PackageStatus::Shipped) {
            $this->notifyWarning('Already Shipped', PackageShippingResult::alreadyShippedMessage($this->package->tracking_number));

            return true;
        }

        $this->purchaseError = null;

        $this->package->shipment->refresh()->load('location');

        // Authorized on mount, but the shipment may have shipped from another
        // station since, which makes this a reshipment.
        $permission = Gate::inspect('ship', $this->package);
        if ($permission->denied()) {
            $this->notifyError('Not Allowed', $permission->message() ?? 'You may not ship this package.');

            return true;
        }

        $locationError = app(ShipmentLocationGuard::class)->errorFor($this->package->shipment, auth()->user());
        if ($locationError !== null) {
            $this->notifyError('Location Unavailable', $locationError);

            return true;
        }

        return false;
    }

    /**
     * The package shipped on a label, bought now or fetched from an earlier
     * purchase: remember it, print it, and go back.
     */
    private function labelBought(PackageShippingResult $result, string $title): void
    {
        // Pack sends every Package that needs attended selection here and is
        // returned to afterwards, so its VOID and REPRINT commands must act on
        // this Label, not the last one auto-shipped (ADR-0007, decision 4).
        app(SessionLastLabel::class)->remember($this->package);

        $this->notifySuccess($title, $result->summaryMessage());

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
     * buys is the case PolyBag cannot see: a catalog corrected in the Shopify
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
