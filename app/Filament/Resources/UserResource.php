<?php

namespace App\Filament\Resources;

use App\Enums\Role;
use App\Filament\Components\PasswordPolicyChecklist;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Location;
use App\Models\User;
use App\Services\AccountLockoutService;
use App\Services\MfaResetService;
use App\Services\PasswordPolicyService;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static \UnitEnum|string|null $navigationGroup = 'Admin';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('username')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Optional if email is provided.'),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->rules(fn (?User $record): array => [
                        app(PasswordPolicyService::class)->rule(),
                        app(PasswordPolicyService::class)->historyRule($record),
                    ])
                    ->maxLength(255)
                    ->helperText('Leave empty for SSO-only users (no local password).'),
                PasswordPolicyChecklist::make(),
                Forms\Components\Select::make('role')
                    ->options(Role::class)
                    ->required(),
                Forms\Components\Select::make('location_id')
                    ->label('Default Location')
                    ->relationship('location', 'name')
                    ->placeholder('Use system default location')
                    ->nullable()
                    ->visible(fn (): bool => (bool) app(SettingsService::class)->get('multi_location_enabled', false)),
                Forms\Components\Toggle::make('active')
                    ->default(true),
                Forms\Components\Toggle::make('auto_ship_enabled')
                    ->label('Auto Ship')
                    ->default(true)
                    ->helperText('Buy and print the cheapest on-time label as soon as a package is packed. Managers and admins can also switch this for themselves on the Pack page.'),
                Forms\Components\Placeholder::make('mfa_status')
                    ->label('Multi-Factor Authentication')
                    ->content(function (?User $record): string {
                        if (! $record) {
                            return 'Not enabled';
                        }

                        $service = app(MfaResetService::class);
                        $methods = [];

                        if ($service->hasAppAuthentication($record)) {
                            $methods[] = 'Authenticator App';
                        }

                        if ($service->hasEmailAuthentication($record)) {
                            $methods[] = 'Email Code';
                        }

                        return $methods === [] ? 'Not enabled' : implode(', ', $methods);
                    })
                    ->visible(fn (?User $record): bool => $record !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role'),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
                Tables\Columns\IconColumn::make('auto_ship_enabled')
                    ->label('Auto Ship')
                    ->boolean(),
                Tables\Columns\IconColumn::make('locked')
                    ->label('Locked')
                    ->state(fn (User $record): bool => app(AccountLockoutService::class)->isLocked($record))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('danger')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('mfa')
                    ->label('MFA')
                    ->state(function (User $record): string {
                        $service = app(MfaResetService::class);
                        $methods = [];

                        if ($service->hasAppAuthentication($record)) {
                            $methods[] = 'App';
                        }

                        if ($service->hasEmailAuthentication($record)) {
                            $methods[] = 'Email';
                        }

                        return $methods === [] ? 'None' : implode(' + ', $methods);
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'None' ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\Action::make('unlock')
                    ->label('Unlock')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => app(AccountLockoutService::class)->isLocked($record))
                    ->action(fn (User $record) => app(AccountLockoutService::class)->resetAttempts($record)),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
