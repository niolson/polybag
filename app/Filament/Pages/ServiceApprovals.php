<?php

namespace App\Filament\Pages;

use App\DataTransferObjects\PostageSources\ApprovalRule;
use App\DataTransferObjects\PostageSources\ServiceApprovalRules;
use App\Enums\AmazonChannelType;
use App\Enums\ApprovalEffect;
use App\Enums\SourceEnvironment;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\ClientContext;
use App\Services\PostageSources\ServiceApprovalGate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Which Amazon Buy Shipping services automation may buy, per client,
 * environment and kind of order — `amazon-buy-shipping/18`.
 *
 * Amazon-only for now, because Amazon is the only postage source that reports
 * services rather than quoting ones we authored: direct carrier accounts buy
 * from the seeded catalog without approval, and Shopify's blind purchase is
 * governed by its connection's postage setting alone. The page is hidden while no Amazon connection is
 * active; approvals already on file are kept, and apply again when one is.
 * Amazon orders and orders from other channels, sold Amazon Shipping off
 * Amazon, are approved separately: the prices and terms differ, so consent to
 * one is not consent to the other (`amazon-shipping-external-orders/07`). Both
 * list the same observed services, because observations are not recorded per
 * channel.
 *
 * Two ways to answer, because sellers want one of two things. *All services*
 * trusts whatever the source offers, including services it starts offering
 * tomorrow, and lists the exceptions. *Selected services* names what may be
 * bought, a whole carrier at a time or service by service. Either way an
 * exception wins over any approval, and nothing here depends on a service
 * having been mapped.
 *
 * The form saves exactly what it shows: {@see ServiceApprovalGate::sync()}
 * withdraws any rule on file for this client and world that the form no longer
 * expresses. So every carrier and service a rule names is listed, whether or
 * not it has been observed in this world, rather than being dropped unseen.
 *
 * @property-read Schema $form
 */
class ServiceApprovals extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationLabel = 'Amazon Approvals';

    protected static ?string $title = 'Amazon Automation Approvals';

    protected static UnitEnum|string|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 93;

    protected string $view = 'filament.pages.service-approvals';

    public const MODE_ALL = 'all';

    public const MODE_SELECTED = 'selected';

    /**
     * Amazon carrier identifiers for the carriers Buy Shipping sells to a US
     * seller — see {@see isOfferedToUsShippers()}.
     *
     * From Seller Central's "US shipping services available on Amazon Buy
     * Shipping" (help page GJC5VMZUMVF2YE4P, read 2026-09-24). That page also
     * lists DHL Express for export, but no DHL identifier we have observed is
     * known to be it, so DHL is left to the eligibility override.
     *
     * @var list<string>
     */
    public const US_BUY_SHIPPING_CARRIERS = [
        'AMZN_US',
        'FEDEX',
        'ONTRAC',
        'UPS',
        'USPS',
    ];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The carriers the form lists, keyed the way their form state is.
     *
     * @var array<string, array{id: string, name: string, services: array<string, string>}>
     */
    public array $carriers = [];

    public static function canAccess(): bool
    {
        return auth()->user()->can('viewAny', ServiceApproval::class)
            && DataSource::hasActiveAmazonConnection();
    }

    public function mount(): void
    {
        $this->loadScope(
            source: AmazonBuyShippingAdapter::OBSERVATION_SOURCE,
            environment: SourceEnvironment::current(),
            channelType: AmazonChannelType::Amazon,
            clientId: app(ClientContext::class)->id(),
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Whose approvals')
                        ->schema([
                            Select::make('client_id')
                                ->label('Client')
                                ->options(fn (): array => static::clientOptions())
                                ->required()
                                ->selectablePlaceholder(false)
                                ->live()
                                ->afterStateUpdated(fn (): null => $this->reloadScope()),
                            Select::make('environment')
                                ->label('Environment')
                                ->options(fn (): array => collect(SourceEnvironment::cases())
                                    ->mapWithKeys(fn (SourceEnvironment $environment): array => [$environment->value => $environment->label()])
                                    ->all())
                                ->required()
                                ->selectablePlaceholder(false)
                                ->live()
                                ->afterStateUpdated(fn (): null => $this->reloadScope())
                                ->helperText('Sandbox and production are approved separately. A sandbox approval never authorizes a purchase with real money.'),
                            Select::make('channel_type')
                                ->label('Orders')
                                ->options(fn (): array => collect(AmazonChannelType::cases())
                                    ->mapWithKeys(fn (AmazonChannelType $channelType): array => [$channelType->value => $channelType->label()])
                                    ->all())
                                ->required()
                                ->selectablePlaceholder(false)
                                ->live()
                                ->afterStateUpdated(fn (): null => $this->reloadScope())
                                ->helperText('Amazon Shipping sold for orders from other channels has its own prices and none of Buy Shipping\'s protections, so it is approved separately.'),
                        ])
                        ->columns(3),

                    Section::make('What automation may buy')
                        ->description('Auto-ship, batch ship and shipping rules buy an Amazon service only if it is approved here. Everything else stays on the Ship page for a packer to choose by hand, having seen the price.')
                        ->schema([
                            Radio::make('mode')
                                ->hiddenLabel()
                                ->options([
                                    self::MODE_SELECTED => 'Selected services',
                                    self::MODE_ALL => 'All services',
                                ])
                                ->descriptions([
                                    self::MODE_SELECTED => 'Only the carriers and services ticked below.',
                                    self::MODE_ALL => 'Everything this source offers, including services it first offers later — except the exceptions below.',
                                ])
                                ->required()
                                ->live(),
                            Placeholder::make('nothing_observed')
                                ->hiddenLabel()
                                ->content('Amazon has not reported any services in this environment yet. They appear here after a rate quote.')
                                ->visible(fn (): bool => $this->carriers === []),
                            Grid::make(1)
                                ->schema(fn (): array => $this->carrierComponents()),
                        ]),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save approvals')
                                ->submit('save'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Read from the raw state: the dehydrated one is for the rules below.
        $source = AmazonBuyShippingAdapter::OBSERVATION_SOURCE;
        $environment = SourceEnvironment::from($this->data['environment']);
        $channelType = AmazonChannelType::from($this->data['channel_type']);
        $client = Client::findOrFail($this->data['client_id']);

        $result = app(ServiceApprovalGate::class)->sync(
            source: $source,
            environment: $environment,
            channelType: $channelType,
            client: $client,
            rules: $this->rulesFromState($data),
            approver: auth()->user(),
        );

        Notification::make()
            ->success()
            ->title('Approvals saved')
            ->body(static::summary($result, $client, $environment, $channelType))
            ->send();

        $this->loadScope($source, $environment, $channelType, $client->getKey());
    }

    /**
     * The rules the form expresses, and only those.
     *
     * Every field is read through the mode that shows it. Livewire keeps the
     * state of a hidden field, so a list of exceptions ticked under *All
     * services* is still in `$data` after switching to *Selected services* —
     * and writing it then would be an exception with nothing to except.
     *
     * @param  array<string, mixed>  $data
     * @return list<ApprovalRule>
     */
    public function rulesFromState(array $data): array
    {
        $all = ($data['mode'] ?? null) === self::MODE_ALL;
        $rules = $all ? [ApprovalRule::everything()] : [];

        foreach ($this->carriers as $key => $carrier) {
            $state = $data['carriers'][$key] ?? [];
            $known = array_keys($carrier['services']);
            $ticked = fn (string $field): array => array_values(array_intersect($known, (array) ($state[$field] ?? [])));

            if ($all && ($state['deny_all'] ?? false)) {
                $rules[] = ApprovalRule::carrier($carrier['id'], ApprovalEffect::Deny);

                continue;
            }

            $wholeCarrier = ! $all && ($state['allow_all'] ?? false);

            if ($wholeCarrier) {
                $rules[] = ApprovalRule::carrier($carrier['id']);
            }

            if ($all || $wholeCarrier) {
                foreach ($ticked('excepted') as $serviceId) {
                    $rules[] = ApprovalRule::service($carrier['id'], $serviceId, ApprovalEffect::Deny);
                }

                continue;
            }

            foreach ($ticked('approved') as $serviceId) {
                $rules[] = ApprovalRule::service($carrier['id'], $serviceId);
            }
        }

        return $rules;
    }

    /**
     * One block per carrier: a whole-carrier toggle, and a service list whose
     * meaning follows from the toggle and the mode.
     *
     * @return list<Component>
     */
    protected function carrierComponents(): array
    {
        $mode = fn (): ?string => $this->data['mode'] ?? null;

        return collect($this->carriers)
            ->map(fn (array $carrier, string $key): Component => Section::make($carrier['name'])
                ->statePath("carriers.{$key}")
                ->compact()
                ->schema([
                    Toggle::make('allow_all')
                        ->label("All {$carrier['name']} services, including ones first offered later")
                        ->live()
                        ->visible(fn (): bool => $mode() === self::MODE_SELECTED),
                    Toggle::make('deny_all')
                        ->label("Except every {$carrier['name']} service")
                        ->live()
                        ->visible(fn (): bool => $mode() === self::MODE_ALL),
                    CheckboxList::make('approved')
                        ->label('Approved services')
                        ->options($carrier['services'])
                        ->columns(2)
                        ->visible(fn (Get $get): bool => $mode() === self::MODE_SELECTED && ! $get('allow_all')),
                    CheckboxList::make('excepted')
                        ->label('Exceptions')
                        ->helperText('Never bought by automation, whatever else is approved.')
                        ->options($carrier['services'])
                        ->columns(2)
                        ->visible(fn (Get $get): bool => ($mode() === self::MODE_ALL && ! $get('deny_all'))
                            || ($mode() === self::MODE_SELECTED && $get('allow_all'))),
                ]))
            ->values()
            ->all();
    }

    protected function reloadScope(): null
    {
        $this->loadScope(
            source: AmazonBuyShippingAdapter::OBSERVATION_SOURCE,
            environment: SourceEnvironment::from($this->data['environment'] ?? SourceEnvironment::current()->value),
            channelType: AmazonChannelType::from($this->data['channel_type'] ?? AmazonChannelType::Amazon->value),
            clientId: (int) $this->data['client_id'],
        );

        return null;
    }

    /**
     * Read one client's rules for one source, world and kind of order into the
     * form.
     */
    protected function loadScope(string $source, SourceEnvironment $environment, AmazonChannelType $channelType, int $clientId): void
    {
        $rules = app(ServiceApprovalGate::class)->rulesFor($source, $environment, $channelType, $clientId);

        $this->carriers = static::carriersFor($source, $environment, $rules);

        $all = $rules->allows()->contains(fn (ApprovalRule $rule): bool => $rule->isEverything());

        $this->form->fill([
            'client_id' => $clientId,
            'environment' => $environment->value,
            'channel_type' => $channelType->value,
            'mode' => $all ? self::MODE_ALL : self::MODE_SELECTED,
            'carriers' => collect($this->carriers)->map(function (array $carrier) use ($rules): array {
                $of = fn (Collection $side): Collection => $side
                    ->filter(fn (ApprovalRule $rule): bool => $rule->externalCarrierId === $carrier['id']);

                return [
                    'allow_all' => $of($rules->allows())->contains(fn (ApprovalRule $rule): bool => $rule->isWholeCarrier()),
                    'deny_all' => $of($rules->denies())->contains(fn (ApprovalRule $rule): bool => $rule->isWholeCarrier()),
                    'approved' => $of($rules->allows())->reject(fn (ApprovalRule $rule): bool => $rule->isWholeCarrier())
                        ->map(fn (ApprovalRule $rule): string => $rule->externalServiceId)->values()->all(),
                    'excepted' => $of($rules->denies())->reject(fn (ApprovalRule $rule): bool => $rule->isWholeCarrier())
                        ->map(fn (ApprovalRule $rule): string => $rule->externalServiceId)->values()->all(),
                ];
            })->all(),
        ]);
    }

    /**
     * Every carrier and service observed from this source in this world that a
     * US shipper could buy, plus any a rule on file names — so the form can
     * show, and so keep, what it would otherwise withdraw without anyone
     * seeing it.
     *
     * @return array<string, array{id: string, name: string, services: array<string, string>}>
     */
    protected static function carriersFor(string $source, SourceEnvironment $environment, ServiceApprovalRules $rules): array
    {
        // Latest-seen first: marketplaces report the same service separately,
        // and the first name found for an identifier is the one shown.
        $observed = ObservedService::query()
            ->where('source', $source)
            ->where('environment', $environment)
            ->orderByDesc('last_seen_at')
            ->get();

        $carrierNames = [];
        $serviceNames = [];

        foreach ($observed as $service) {
            $carrierNames[$service->external_carrier_id] ??= static::name($service->external_carrier_name, $service->external_carrier_id);
            $serviceNames[$service->external_carrier_id][$service->external_service_id] ??= static::name($service->external_service_name, $service->external_service_id);
        }

        $carriers = [];

        $add = function (string $carrierId, ?string $serviceId) use (&$carriers, $carrierNames, $serviceNames): void {
            $carriers[$carrierId] ??= [
                'id' => $carrierId,
                'name' => $carrierNames[$carrierId] ?? $carrierId,
                'services' => [],
            ];

            if ($serviceId !== null) {
                $carriers[$carrierId]['services'][$serviceId] = $serviceNames[$carrierId][$serviceId] ?? $serviceId;
            }
        };

        foreach ($observed->filter(fn (ObservedService $service): bool => static::isOfferedToUsShippers($service)) as $service) {
            $add($service->external_carrier_id, $service->external_service_id);
        }

        foreach ($rules->rules as $rule) {
            if (! $rule->isEverything()) {
                $add($rule->externalCarrierId, $rule->isWholeCarrier() ? null : $rule->externalServiceId);
            }
        }

        uasort($carriers, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        // Form state keys that no carrier identifier can break — they are the
        // source's own strings and may hold dots, which a state path reads as
        // nesting.
        return collect(array_values($carriers))
            ->mapWithKeys(fn (array $carrier, int $index): array => [
                "c{$index}" => [...$carrier, 'services' => static::distinctLabels($carrier['services'])],
            ])
            ->all();
    }

    /**
     * Whether a service could be offered for a parcel leaving a US warehouse.
     *
     * Amazon's `ineligibleRates` lists every carrier in its catalog: the ones a
     * US seller can buy, cross-border carriers that ship *into* the US from
     * China, India, Vietnam or Mexico, and Self Delivery, where the seller
     * delivers the parcel and there is no carrier label to buy. Nothing in the
     * response says which is which — the only signal is a prose ineligibility
     * message, which ADR-0003 decides not to branch on — so the carriers a US
     * seller can buy from are named in {@see US_BUY_SHIPPING_CARRIERS}.
     *
     * A service from any other carrier that has ever been offered as buyable
     * is shown anyway: the list is a reading of Amazon's help page, and an
     * actual offer outranks it.
     */
    protected static function isOfferedToUsShippers(ObservedService $service): bool
    {
        return in_array($service->external_carrier_id, self::US_BUY_SHIPPING_CARRIERS, true)
            || $service->hasBeenEligible();
    }

    /**
     * Service names sorted for reading. Where a carrier reports two services
     * under one name, the identifiers are the only way to tell them apart, so
     * those two — and only those — keep theirs.
     *
     * @param  array<string, string>  $services
     * @return array<string, string>
     */
    protected static function distinctLabels(array $services): array
    {
        $counts = array_count_values($services);

        foreach ($services as $id => $name) {
            if ($counts[$name] > 1) {
                $services[$id] = "{$name} ({$id})";
            }
        }

        asort($services, SORT_NATURAL | SORT_FLAG_CASE);

        return $services;
    }

    /**
     * What a person reads: the source's name for a thing, tidied, or its
     * identifier when the source gave no name.
     */
    protected static function name(?string $name, string $id): string
    {
        $name = Str::squish($name ?? '');

        return $name !== '' ? $name : $id;
    }

    /**
     * Clients, all of them, including inactive ones — an inactive client's
     * approvals are still on file and still theirs to change.
     *
     * @return array<int, string>
     */
    protected static function clientOptions(): array
    {
        return Client::query()
            ->orderBy('name')
            ->get(['id', 'name', 'active'])
            ->mapWithKeys(fn (Client $client): array => [
                $client->getKey() => $client->name.($client->active ? '' : ' (inactive)'),
            ])
            ->all();
    }

    /**
     * What a save actually did — approvals are permission to spend money, so
     * "saved" on its own is not enough.
     *
     * @param  array{granted: int, revoked: int}  $result
     */
    protected static function summary(array $result, Client $client, SourceEnvironment $environment, AmazonChannelType $channelType): string
    {
        $scope = "{$client->name}, {$environment->label()}, ".Str::lower($channelType->label());

        if ($result['granted'] === 0 && $result['revoked'] === 0) {
            return "No change for {$scope}.";
        }

        $changes = array_filter([
            $result['granted'] > 0
                ? trans_choice(':count rule added|:count rules added', $result['granted'], ['count' => $result['granted']])
                : null,
            $result['revoked'] > 0
                ? trans_choice(':count rule withdrawn|:count rules withdrawn', $result['revoked'], ['count' => $result['revoked']])
                : null,
        ]);

        return ucfirst(implode(' and ', $changes))." for {$scope}.";
    }

    public function getSubheading(): ?string
    {
        return 'Which services Amazon Buy Shipping offers that automated shipping may buy, for Amazon orders and, separately, for orders from other channels sold Amazon Shipping. Approve everything, whole carriers, or single services; an exception always wins. Direct carrier accounts need no approval here.';
    }
}
