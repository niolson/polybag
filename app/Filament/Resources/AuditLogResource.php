<?php

namespace App\Filament\Resources;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\Location;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $modelLabel = 'Audit Log';

    protected static ?string $pluralModelLabel = 'Audit Logs';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Admin) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('System'),
                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (AuditAction $state) => $state->getLabel()),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Model')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('ID')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options(AuditAction::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->preload(),
                Tables\Filters\SelectFilter::make('auditable_type')
                    ->label('Model')
                    ->options(fn () => AuditLog::query()
                        ->whereNotNull('auditable_type')
                        ->distinct()
                        ->pluck('auditable_type')
                        ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
                        ->sort()
                        ->toArray()
                    ),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('From'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators['from'] = 'From '.$data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $indicators['until'] = 'Until '.$data['until'];
                        }

                        return $indicators;
                    }),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make('Details')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Date')
                            ->dateTime('M j, Y g:i:s A', timezone: Location::timezone()),
                        TextEntry::make('user.name')
                            ->label('User')
                            ->placeholder('System'),
                        TextEntry::make('action')
                            ->badge()
                            ->formatStateUsing(fn (AuditAction $state) => $state->getLabel()),
                        TextEntry::make('auditable_type')
                            ->label('Model')
                            ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—'),
                        TextEntry::make('auditable_id')
                            ->label('Record ID')
                            ->url(fn (AuditLog $record) => self::getAuditableUrl($record)),
                        TextEntry::make('ip_address')
                            ->label('IP Address')
                            ->placeholder('—'),
                    ])->columns(3),
                Section::make('Changes')
                    ->schema([
                        TextEntry::make('old_values')
                            ->label('Old Values')
                            ->getStateUsing(fn (AuditLog $record) => self::formatJson($record->old_values))
                            ->fontFamily('mono'),
                        TextEntry::make('new_values')
                            ->label('New Values')
                            ->getStateUsing(fn (AuditLog $record) => self::formatJson($record->new_values))
                            ->fontFamily('mono'),
                        TextEntry::make('metadata')
                            ->label('Metadata')
                            ->getStateUsing(fn (AuditLog $record) => self::formatJson($record->metadata))
                            ->fontFamily('mono'),
                    ])->columns(3),
            ]);
    }

    private static function formatJson(?array $data): string
    {
        return $data ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '—';
    }

    private static function getAuditableUrl(AuditLog $record): ?string
    {
        if (! $record->auditable_type || ! $record->auditable_id) {
            return null;
        }

        $resourceMap = [
            \App\Models\Package::class => PackageResource::class,
            \App\Models\Shipment::class => ShipmentResource::class,
            \App\Models\LabelBatch::class => LabelBatchResource::class,
            \App\Models\User::class => UserResource::class,
            \App\Models\BoxSize::class => BoxSizeResource::class,
            \App\Models\Carrier::class => Carriers\CarrierResource::class,
            \App\Models\CarrierService::class => CarrierServiceResource::class,
            \App\Models\Channel::class => ChannelResource::class,
            \App\Models\Location::class => LocationResource::class,
            \App\Models\Product::class => ProductResource::class,
            \App\Models\ShippingMethod::class => ShippingMethodResource::class,
        ];

        $resourceClass = $resourceMap[$record->auditable_type] ?? null;

        if (! $resourceClass) {
            return null;
        }

        try {
            // Use 'view' page if it exists, otherwise 'edit'
            $pages = $resourceClass::getPages();

            $page = isset($pages['view']) ? 'view' : (isset($pages['edit']) ? 'edit' : null);

            return $page ? $resourceClass::getUrl($page, ['record' => $record->auditable_id]) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
