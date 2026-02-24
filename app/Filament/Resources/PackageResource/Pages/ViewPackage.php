<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Filament\Resources\PackageResource;
use App\Services\Carriers\CarrierRegistry;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewPackage extends ViewRecord
{
    protected static string $resource = PackageResource::class;

    protected string $view = 'filament.resources.package-resource.pages.view-package';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ship')
                ->label('Ship')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->url(fn () => '/ship/'.$this->record->id)
                ->disabled(fn () => $this->record->shipped),
            Action::make('reprint')
                ->label('Reprint Label')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->visible(fn () => $this->record->shipped && $this->record->label_data)
                ->action(function (): void {
                    $this->dispatch('print-label', label: $this->record->label_data, orientation: $this->record->label_orientation ?? 'portrait', format: $this->record->label_format ?? 'pdf', dpi: $this->record->label_dpi);

                    Notification::make()
                        ->title('Label sent to printer')
                        ->success()
                        ->send();
                }),
            Action::make('void')
                ->label('Void Label')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Void Label')
                ->modalDescription('This will cancel the label with the carrier. The package will be kept with its dimensions so it can be re-shipped.')
                ->visible(fn () => $this->record->shipped && $this->record->tracking_number && $this->record->carrier)
                ->action(function (): void {
                    $adapter = CarrierRegistry::get($this->record->carrier);
                    $response = $adapter->cancelShipment($this->record->tracking_number, $this->record);

                    if ($response->success) {
                        $this->record->clearShipping();
                        Notification::make()->success()->title('Label voided')->body($response->message)->send();
                    } else {
                        Notification::make()->danger()->title('Void failed')->body($response->message)->send();
                    }
                }),
            Action::make('edit')
                ->url(fn () => PackageResource::getUrl('edit', ['record' => $this->record])),
        ];
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
                            ->label('Shipment Reference'),
                        TextEntry::make('tracking_number')
                            ->icon('heroicon-o-clipboard')
                            ->iconPosition('after')
                            ->copyable(),
                        TextEntry::make('carrier'),
                        TextEntry::make('service'),
                        TextEntry::make('cost')
                            ->money('USD'),
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
                        TextEntry::make('shipped')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state ? 'Shipped' : 'Not Shipped')
                            ->color(fn ($state) => $state ? 'success' : 'warning'),
                        TextEntry::make('shipped_at')
                            ->dateTime(),
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
            ]);
    }
}
