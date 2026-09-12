<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Contracts\PackageLabelWorkflow;
use App\Enums\PackageStatus;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\ShipmentResource;
use App\Models\Location;
use App\Models\Package;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;

class ViewPackage extends ViewRecord
{
    use NotifiesUser, PrintsLabels;

    protected static string $resource = PackageResource::class;

    protected string $view = 'filament.resources.package-resource.pages.view-package';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ship')
                ->label('Ship')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->url(fn (): string => '/ship/'.$this->record->id)
                ->disabled(fn (): bool => $this->record->status === PackageStatus::Shipped),
            Action::make('reprint')
                ->label(fn (): string => $this->record->label_printed_at ? 'Reprint Label' : 'Print Label')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->visible(fn (): bool => $this->record->status === PackageStatus::Shipped && $this->record->label_data)
                ->action(fn () => $this->printStoredPackageLabel($this->record->id)),
            PackageResource::makeTrackAction(),
            Action::make('void')
                ->label('Void Label')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
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
                    $result = app(PackageLabelWorkflow::class)->voidLabel($this->record);

                    $notification = Notification::make()
                        ->title($result->title)
                        ->body($result->message);

                    $result->success
                        ? $notification->success()->send()
                        : $notification->danger()->send();
                }),
            Action::make('edit')
                ->url(fn (): string => PackageResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function getFooter(): ?View
    {
        if (strcasecmp($this->record->carrier ?? '', 'fedex') !== 0) {
            return null;
        }

        return view('components.legal-disclaimers', ['show' => ['fedex']]);
    }

    private function shopifyShipped(): bool
    {
        return $this->record instanceof Package && $this->record->isShopifyShipped();
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
                        TextEntry::make('shopify_shipping_notice')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => $record->isShopifyShipped())
                            ->badge()
                            ->color('warning')
                            ->state('Bought through Shopify Shipping — void and refund it in the Shopify admin, not here. PolyBag returns this package to unshipped once Shopify reports the label voided.'),
                        Components\Fieldset::make('Dimensions')->columns(3)->schema([
                            TextEntry::make('length')
                                ->suffix(' in'),
                            TextEntry::make('width')
                                ->suffix(' in'),
                            TextEntry::make('height')
                                ->suffix(' in'),
                            TextEntry::make('weight')
                                ->suffix(' lbs'),
                        ]),
                        TextEntry::make('status')
                            ->badge(),
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

            ]);
    }
}
