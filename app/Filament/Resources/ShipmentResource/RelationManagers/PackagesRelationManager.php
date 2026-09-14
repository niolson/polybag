<?php

namespace App\Filament\Resources\ShipmentResource\RelationManagers;

use App\Enums\PackageStatus;
use App\Filament\Resources\PackageResource;
use App\Models\Package;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                // No tracking number, cost or status: those are the projection
                // of the package's active label and only the label writers set
                // them (ADR-0004 decision 3).
                Forms\Components\TextInput::make('shipping_method')
                    ->maxLength(255),
                Forms\Components\TextInput::make('weight')
                    ->numeric(),
                Forms\Components\TextInput::make('height')
                    ->numeric(),
                Forms\Components\TextInput::make('width')
                    ->numeric(),
                Forms\Components\TextInput::make('length')
                    ->numeric(),
                Forms\Components\Toggle::make('exported')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tracking_number')
            ->columns([
                Tables\Columns\TextColumn::make('tracking_number'),
                Tables\Columns\TextColumn::make('tracking_status')
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('shipping_method'),
                Tables\Columns\TextColumn::make('weight'),
                Tables\Columns\TextColumn::make('cost')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\IconColumn::make('exported')
                    ->boolean(),
            ])
            ->recordUrl(fn ($record): string => PackageResource::getUrl('view', ['record' => $record]))
            ->filters([
                //
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->recordActions([
                PackageResource::makeTrackAction(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->before(function (Actions\DeleteAction $action, $record): void {
                        if ($record->status === PackageStatus::Shipped) {
                            Notification::make()
                                ->title('Cannot delete package')
                                ->body('This package has been shipped. Void the label first before deleting.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->groupedBulkActions([
                // The same guard as the row action: a shipped package has a live
                // label at the carrier and an active label record that would
                // cascade with it.
                Actions\DeleteBulkAction::make()
                    ->before(function (Actions\DeleteBulkAction $action, Collection $records): void {
                        if ($records->contains(fn (Package $record): bool => $record->status === PackageStatus::Shipped)) {
                            Notification::make()
                                ->title('Cannot delete packages')
                                ->body('At least one selected package has been shipped. Void its label first before deleting.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ]);
    }
}
