<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PackageLabelWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PrintRequest;
use App\Enums\PackageDraftState;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Exceptions\PackageDraftIncompleteException;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\ShipmentResource;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use LogicException;

class ViewPackage extends ViewRecord
{
    use NotifiesUser, PrintsLabels;

    protected static string $resource = PackageResource::class;

    protected string $view = 'filament.resources.package-resource.pages.view-package';

    /** Formats a workstation may ask carriers for; anything else buys PDF. */
    private const LABEL_FORMATS = ['pdf', 'png', 'gif', 'zpl'];

    private bool $notReadyReasonResolved = false;

    private ?string $notReadyReason = null;

    protected function getHeaderActions(): array
    {
        return [
            // Every draft can go back to the Pack page: to finish one that is
            // not ready, or to re-weigh one that sat on a shelf.
            Action::make('pack')
                ->label('Pack')
                ->icon('heroicon-o-archive-box')
                ->color(fn (): string => $this->notReadyReason() === null ? 'gray' : 'primary')
                ->authorize('ship')
                ->visible(fn (): bool => $this->isDraft())
                ->tooltip(fn (): ?string => $this->notReadyReason())
                ->url(fn (): string => '/pack/'.$this->package()->shipment_id),
            // Choosing the rate by hand is a manager's call from here; a shipper
            // buys within the shipping rules, or picks a rate from the Pack page.
            Action::make('ship')
                ->label('Ship')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->authorize('ship')
                ->visible(fn (): bool => $this->isDraft()
                    && $this->notReadyReason() === null
                    && auth()->user()->role->isAtLeast(Role::Manager))
                ->url(fn (): string => '/ship/'.$this->record->id),
            Action::make('buyAndPrintLabel')
                ->label('Buy and print label')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->authorize('ship')
                ->visible(fn (): bool => $this->isDraft() && $this->notReadyReason() === null)
                // The workstation's printers live in the browser, so the button
                // reads them there and mounts the action with them, as the Pack
                // page does when it ships.
                ->alpineClickHandler(<<<'JS'
                    $wire.mountAction('buyAndPrintLabel', {
                        labelFormat: PrinterSettings.labelFormat(),
                        labelDpi: PrinterSettings.labelDpi(),
                        hasReportPrinter: PrinterSettings.hasDocumentPrinter(),
                    })
                    JS)
                ->requiresConfirmation()
                ->modalHeading('Buy and print label')
                ->modalDescription(fn (): string => $this->buyConfirmationMessage())
                ->modalSubmitActionLabel('Buy and print')
                ->action(fn (array $arguments) => $this->buyAndPrintLabel($arguments)),
            Action::make('reprint')
                ->label(fn (): string => $this->record->label_printed_at ? 'Reprint Label' : 'Print Label')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->visible(fn (): bool => $this->record->status === PackageStatus::Shipped && $this->record->label_data)
                ->action(fn () => $this->printStoredPackageLabel($this->record->id)),
            PackageResource::makeTrackAction(),
            // The rare actions go behind a menu, so the header fits beside a
            // title that is a whole tracking number.
            ActionGroup::make([
                Action::make('void')
                    ->label('Void Label')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize('voidLabel')
                    ->modalHeading('Void Label')
                    ->modalDescription('This will cancel the label with the carrier. The package will be kept with its dimensions so it can be re-shipped.')
                    ->visible(fn (): bool => $this->record->status === PackageStatus::Shipped
                        && $this->record->tracking_number
                        && ($this->record->carrier || $this->shopifyShipped()))
                    // Shopify exposes no void operation, so this can only ever fail
                    // for a label bought through Shopify Shipping.
                    ->disabled(fn (): bool => $this->shopifyShipped())
                    ->tooltip(fn (): ?string => $this->shopifyShipped()
                        ? 'Void and refund this label in the Shopify admin.'
                        : null)
                    ->action(function (): void {
                        $result = app(PackageLabelWorkflow::class)->voidLabel($this->record, auth()->user());

                        $notification = Notification::make()
                            ->title($result->title)
                            ->body($result->message);

                        $result->success
                            ? $notification->success()->send()
                            : $notification->danger()->send();
                    }),
                Action::make('edit')
                    ->icon('heroicon-o-pencil-square')
                    ->authorize('update')
                    ->url(fn (): string => PackageResource::getUrl('edit', ['record' => $this->record])),
            ])
                ->label('More actions')
                ->icon('heroicon-o-ellipsis-vertical')
                ->color('gray')
                ->button()
                ->hiddenLabel(),
        ];
    }

    /**
     * Where the packer voids a Shopify Shipping label, since the disabled Void
     * action cannot: Shopify's API sells labels but only the admin can cancel
     * and refund one.
     */
    private function openInShopifyAction(): Action
    {
        return Action::make('open_in_shopify')
            ->label('Open in Shopify')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->visible(fn (): bool => $this->shopifyAdminOrderUrl() !== null)
            ->url(fn (): ?string => $this->shopifyAdminOrderUrl(), shouldOpenInNewTab: true);
    }

    public function getFooter(): ?View
    {
        if (strcasecmp($this->record->carrier ?? '', 'fedex') !== 0) {
            return null;
        }

        return view('components.legal-disclaimers', ['show' => ['fedex']]);
    }

    private function package(): Package
    {
        return $this->record instanceof Package
            ? $this->record
            : throw new LogicException('View Package was mounted without a Package.');
    }

    private function isDraft(): bool
    {
        return $this->package()->status === PackageStatus::Unshipped;
    }

    /**
     * Why this Package Draft may not be bought for yet, or null when it may.
     * The purchase's own rule, asked once per request; the header asks it for
     * three actions.
     */
    private function notReadyReason(): ?string
    {
        if ($this->notReadyReasonResolved) {
            return $this->notReadyReason;
        }

        $this->notReadyReasonResolved = true;

        if ($this->package()->shipment?->isBlockedByPicking()) {
            return $this->notReadyReason = 'This shipment must be picked before it can be shipped.';
        }

        try {
            app(PackageDraftWorkflow::class)->assertPackageReadyToShip($this->package());
        } catch (PackageDraftIncompleteException $e) {
            return $this->notReadyReason = $e->getMessage();
        }

        return $this->notReadyReason = null;
    }

    /**
     * The measurements the purchase will be bought at, and how old they are:
     * a box set aside on a shelf may no longer weigh what it was saved at.
     */
    private function buyConfirmationMessage(): string
    {
        $package = $this->package();
        $savedAt = $package->updated_at?->timezone(Location::timezone());

        return sprintf(
            'Buys a label within this shipment\'s shipping rules at %s lbs, %s × %s × %s in, then prints it. Last saved %s. If the box has changed since, re-weigh it on the Pack page first.',
            number_format((float) $package->weight, 2),
            (float) $package->length,
            (float) $package->width,
            (float) $package->height,
            $savedAt ? $savedAt->format('M j, Y g:i A').' ('.$savedAt->diffForHumans().')' : 'at an unknown time',
        );
    }

    /**
     * @param  array{labelFormat?: mixed, labelDpi?: mixed, hasReportPrinter?: mixed}  $printer  Read from the workstation's browser settings
     */
    private function buyAndPrintLabel(array $printer): void
    {
        $package = $this->package();
        $labelFormat = in_array($printer['labelFormat'] ?? null, self::LABEL_FORMATS, true) ? $printer['labelFormat'] : 'pdf';
        $labelDpi = in_array((int) ($printer['labelDpi'] ?? 0), [203, 300], true) ? (int) $printer['labelDpi'] : null;

        $result = app(PackageShippingWorkflow::class)->autoShip(
            $package,
            new PackageAutoShippingRequest(
                labelFormat: $labelFormat,
                labelDpi: $labelDpi,
                userId: auth()->id(),
                // A draft opened from the Packages list is someone's packed box:
                // whatever goes wrong, it stays.
                cleanupOnFailure: false,
                hasReportPrinter: (bool) ($printer['hasReportPrinter'] ?? false),
            ),
        );

        $this->notReadyReasonResolved = false;

        if (! $result->success) {
            if ($result->requiresAttendedSelection) {
                $this->notifyWarning($result->title ?? 'Attended Shipping Required', $result->message);
                $this->redirect('/ship/'.$package->id);

                return;
            }

            $this->notifyError($result->title ?? 'Shipping Error', $result->message ?? 'Unable to ship package.');
            // A label bought but not recorded is also sent to the bell; show it now, not at the next poll.
            $this->dispatch('databaseNotificationsSent');
            $package->refresh();

            return;
        }

        Session::put('last_shipped_package_id', $package->id);
        $package->refresh();

        if ($result->response?->labelData) {
            $this->dispatchPrint(PrintRequest::fromShipResponse($result->response, $package));
        }

        $this->notifySuccess('Label Bought', $result->summaryMessage());
    }

    private function shopifyShipped(): bool
    {
        return $this->record instanceof Package && $this->record->isShopifyShipped();
    }

    private function shopifyAdminOrderUrl(): ?string
    {
        return $this->record instanceof Package ? $this->record->shopifyAdminOrderUrl() : null;
    }

    public function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->columns(2)
            ->schema([
                Section::make('Package Details')
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('id')
                            ->label('Package ID'),
                        TextEntry::make('shipment.shipment_reference')
                            ->label('Shipment Reference')
                            ->url(fn ($record): string => ShipmentResource::getUrl('view', ['record' => $record->shipment_id])),
                        TextEntry::make('shipment.client.name')
                            ->label('Client')
                            ->placeholder('—')
                            ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
                        TextEntry::make('tracking_number')
                            ->icon('heroicon-o-clipboard')
                            ->iconPosition('after')
                            ->copyable(),
                        TextEntry::make('carrier')
                            ->label(fn ($record): string => match (true) {
                                $record->isShopifyShipped() => 'Carrier (chosen by Shopify)',
                                $record->isAmazonShipped() => 'Carrier (via Amazon Buy Shipping)',
                                default => 'Carrier',
                            }),
                        TextEntry::make('service'),
                        TextEntry::make('cost')
                            ->money('USD')
                            // Shopify never reports what a label cost, so an
                            // empty cost here is the API's silence, not a $0 label.
                            ->placeholder(fn ($record): string => $record->isShopifyShipped()
                                ? 'Billed by Shopify — not reported through the API'
                                : '—'),
                        Callout::make('Bought through Shopify Shipping')
                            ->key('shopify_shipping_notice')
                            ->warning()
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => $record->isShopifyShipped())
                            ->description('Void and refund this label in the Shopify admin, not here. PolyBag returns this package to unshipped once Shopify reports the label voided.')
                            ->footerActions([$this->openInShopifyAction()]),
                        // Stacked labels: the section's inline labels leave no
                        // room for a value in a quarter-width column.
                        Components\Fieldset::make('Dimensions')->inlineLabel(false)->columns(['default' => 2, 'sm' => 4])->schema([
                            TextEntry::make('length')
                                ->suffix(' in'),
                            TextEntry::make('width')
                                ->suffix(' in'),
                            TextEntry::make('height')
                                ->suffix(' in'),
                            TextEntry::make('weight')
                                ->suffix(' lbs'),
                        ]),
                        // The same badge as the Packages list: a draft shows
                        // how far it has got.
                        TextEntry::make('status')
                            ->badge()
                            ->state(fn (Package $record): PackageStatus|PackageDraftState => $record->draftState() ?? $record->status)
                            ->tooltip(fn (Package $record): ?string => $record->status === PackageStatus::Unshipped ? 'Unshipped' : null),
                        TextEntry::make('tracking_status')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('tracking_updated_at')
                            ->label('Tracking Updated')
                            ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                            ->placeholder('—'),
                        TextEntry::make('delivered_at')
                            ->label('Delivered At')
                            ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                            ->placeholder('—'),
                        TextEntry::make('shipped_at')
                            ->label('Shipped At')
                            ->dateTime('M j, Y g:i A', timezone: Location::timezone()),
                        TextEntry::make('label_printed_at')
                            ->label('Label Printed')
                            ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                            ->placeholder('Not printed'),
                        TextEntry::make('ship_date')
                            ->label('Ship Date')
                            ->date(timezone: Location::timezone()),
                        TextEntry::make('shippedBy.name')
                            ->label('Shipped By'),
                    ]),

                Section::make('Ship To')
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('shipment.first_name')
                            ->label('First Name'),
                        TextEntry::make('shipment.last_name')
                            ->label('Last Name'),
                        TextEntry::make('shipment.company')
                            ->label('Company')
                            ->placeholder('—'),
                        TextEntry::make('shipment.address1')
                            ->label('Address'),
                        TextEntry::make('shipment.address2')
                            ->label('Address 2')
                            ->placeholder('—'),
                        TextEntry::make('shipment.city')
                            ->label('City'),
                        TextEntry::make('shipment.state_or_province')
                            ->label('State/Province'),
                        TextEntry::make('shipment.postal_code')
                            ->label('Postal Code'),
                        TextEntry::make('shipment.country')
                            ->label('Country'),
                    ]),

                Section::make('Special Services')
                    ->visible(fn ($record) => $record->specialServices->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('specialServices')
                            ->label('')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('specialService.name')
                                    ->label('Service'),
                                TextEntry::make('source')
                                    ->label('Source')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => ucwords(str_replace('_', ' ', $state instanceof \BackedEnum ? $state->value : $state)))
                                    ->color(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                                        'shipping_method' => 'info',
                                        'product' => 'warning',
                                        'manual' => 'gray',
                                        'system' => 'gray',
                                        'rule' => 'success',
                                        default => 'gray',
                                    }),
                                TextEntry::make('applied_at')
                                    ->label('Applied At')
                                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                                    ->placeholder('—'),
                            ]),
                    ]),

                // Every label ever bought for this package, voided ones
                // included (ADR-0004). Read-only on purpose: only the label
                // writers on Package touch these rows, so there is nothing to
                // create, edit or delete here.
                Section::make('Labels')
                    ->columnSpanFull()
                    ->visible(fn (Package $record): bool => $record->labels->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('labels')
                            ->hiddenLabel()
                            ->state(fn (Package $record): array => $record->labels
                                ->loadMissing(['purchasedBy', 'voidedBy'])
                                ->sortBy([['purchased_at', 'desc'], ['id', 'desc']])
                                ->values()
                                ->all())
                            ->table([
                                TableColumn::make('Status'),
                                TableColumn::make('Purchased'),
                                TableColumn::make('Carrier'),
                                TableColumn::make('Tracking Number'),
                                TableColumn::make('Cost'),
                                TableColumn::make('Printed'),
                                TableColumn::make('Voided'),
                            ])
                            ->schema([
                                TextEntry::make('state')
                                    ->label('Status')
                                    ->badge()
                                    ->state(fn (PackageLabel $record): string => $record->isVoided() ? 'Voided' : 'Active')
                                    ->color(fn (PackageLabel $record): string => $record->isVoided() ? 'gray' : 'success'),
                                TextEntry::make('purchased_at')
                                    ->label('Purchased')
                                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                                    ->placeholder('—')
                                    ->helperText(fn (PackageLabel $record): ?string => $record->purchasedBy?->name),
                                TextEntry::make('carrier')
                                    ->label('Carrier')
                                    ->placeholder('—')
                                    ->helperText(fn (PackageLabel $record): ?string => $record->service),
                                TextEntry::make('tracking_number')
                                    ->label('Tracking Number')
                                    ->fontFamily('mono')
                                    ->size('sm')
                                    ->copyable()
                                    ->placeholder('—'),
                                TextEntry::make('cost')
                                    ->label('Cost')
                                    ->money('USD')
                                    ->placeholder('—'),
                                TextEntry::make('last_printed_at')
                                    ->label('Printed')
                                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                                    ->placeholder('Not printed'),
                                TextEntry::make('voided_at')
                                    ->label('Voided')
                                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                                    ->placeholder('—')
                                    ->helperText(fn (PackageLabel $record): ?string => $record->isVoided()
                                        ? implode(' · ', array_filter([
                                            $record->void_reason?->getLabel(),
                                            $record->voidedBy?->name,
                                        ]))
                                        : null),
                            ]),
                    ]),
            ]);
    }
}
