<?php

namespace App\Filament\Resources\CarrierAccounts\Pages;

use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use App\Filament\Resources\CarrierAccounts\Concerns\HasFedexRegistration;
use App\Models\CarrierAccount;
use App\Services\Carriers\UspsAdapter;
use App\Services\OAuthService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use LogicException;

class EditCarrierAccount extends EditRecord
{
    use HasFedexRegistration;

    protected static string $resource = CarrierAccountResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($notification = session('oauth_notification')) {
            Notification::make()
                ->{$notification['status']}()
                ->title($notification['title'])
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // USPS OAuth actions
            Action::make('usps_connect')
                ->label(fn (): string => app(OAuthService::class)->isAccountConnected($this->record) ? 'Reconnect USPS' : 'Connect USPS')
                ->icon('heroicon-o-link')
                ->color(fn (): string => app(OAuthService::class)->isAccountConnected($this->record) ? 'warning' : 'primary')
                ->visible(fn (): bool => $this->record->carrier?->name === 'USPS')
                ->disabled(fn (): bool => ! app(OAuthService::class)->isBrokerConfigured())
                ->tooltip(fn () => app(OAuthService::class)->brokerlessGuidance('Enter your own USPS developer app credentials under Advanced / API App Credentials instead.'))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => app(OAuthService::class)->isAccountConnected($this->record) ? 'Reconnect USPS' : 'Connect USPS')
                ->modalDescription(fn (): string => app(OAuthService::class)->isAccountConnected($this->record)
                    ? 'This will replace the existing OAuth token with a new one. You will be redirected to USPS to re-authorize.'
                    : 'You will be redirected to USPS to authorize access.')
                ->action(function (): void {
                    $url = app(OAuthService::class)->initiateAuthorization('usps', $this->record->id);
                    $this->redirect($url, navigate: false);
                }),

            Action::make('usps_test_connection')
                ->label('Test USPS Connection')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->visible(fn (): bool => $this->record->carrier?->name === 'USPS')
                ->action(fn () => $this->testUspsConnection()),

            Action::make('usps_disconnect')
                ->label('Disconnect USPS')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (): bool => $this->record->carrier?->name === 'USPS' && app(OAuthService::class)->isAccountConnected($this->record))
                ->requiresConfirmation()
                ->modalHeading('Disconnect USPS OAuth')
                ->modalDescription('This will remove the OAuth access token. You can reconnect anytime, or the app will fall back to client credentials if configured.')
                ->action(function (): void {
                    app(OAuthService::class)->disconnectAccount($this->record, 'usps');
                    Notification::make()->success()->title('USPS disconnected.')->send();
                    $this->redirect(static::getUrl(['record' => $this->carrierAccountRecord()->id]));
                }),

            // FedEx wizard and disconnect
            $this->fedexRegisterAction(),
            $this->fedexDisconnectAction(),

            // UPS OAuth actions
            Action::make('ups_connect')
                ->label(fn (): string => app(OAuthService::class)->isAccountConnected($this->record) ? 'Reconnect UPS' : 'Connect UPS')
                ->icon('heroicon-o-link')
                ->color(fn (): string => app(OAuthService::class)->isAccountConnected($this->record) ? 'warning' : 'primary')
                ->visible(fn (): bool => $this->carrierAccountRecord()->carrier?->name === 'UPS')
                ->disabled(fn (): bool => ! app(OAuthService::class)->isBrokerConfigured())
                ->tooltip(fn () => app(OAuthService::class)->brokerlessGuidance('Enter your own UPS developer app credentials under Advanced / API App Credentials instead.'))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => app(OAuthService::class)->isAccountConnected($this->record) ? 'Reconnect UPS' : 'Connect UPS')
                ->modalDescription(fn (): string => app(OAuthService::class)->isAccountConnected($this->record)
                    ? 'This will replace the existing OAuth token with a new one. You will be redirected to UPS to re-authorize.'
                    : 'You will be redirected to UPS to authorize access.')
                ->action(function (): void {
                    $url = app(OAuthService::class)->initiateAuthorization('ups', $this->carrierAccountRecord()->id);
                    $this->redirect($url, navigate: false);
                }),

            Action::make('ups_disconnect')
                ->label('Disconnect UPS')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (): bool => $this->carrierAccountRecord()->carrier?->name === 'UPS' && app(OAuthService::class)->isAccountConnected($this->record))
                ->requiresConfirmation()
                ->modalHeading('Disconnect UPS OAuth')
                ->modalDescription('This will remove the OAuth access token. You can reconnect anytime, or the app will fall back to client credentials if configured.')
                ->action(function (): void {
                    app(OAuthService::class)->disconnectAccount($this->record, 'ups');
                    Notification::make()->success()->title('UPS disconnected.')->send();
                    $this->redirect(static::getUrl(['record' => $this->carrierAccountRecord()->id]));
                }),

            DeleteAction::make(),
        ];
    }

    private function carrierAccountRecord(): CarrierAccount
    {
        $record = $this->getRecord();

        if (! $record instanceof CarrierAccount) {
            throw new LogicException('Carrier account actions require a carrier account record.');
        }

        return $record;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Merge dot-notation credentials (USPS fields) into existing credentials
        // to preserve keys not present in the form (e.g. oauth_connected_at).
        if (isset($data['credentials'])) {
            $data['credentials'] = array_merge($this->record->credentials ?? [], $data['credentials']);
        }

        // Virtual credential fields for FedEx and UPS (stored in credentials JSON)
        $credentialFieldMap = [
            'fedex_production_account_number' => 'production_account_number',
            'fedex_sandbox_account_number' => 'sandbox_account_number',
            'ups_account_number' => 'account_number',
        ];

        foreach ($credentialFieldMap as $field => $credentialKey) {
            if (array_key_exists($field, $data)) {
                $data['credentials'] = array_merge(
                    $data['credentials'] ?? $this->record->credentials ?? [],
                    [$credentialKey => $data[$field]]
                );
                unset($data[$field]);
            }
        }

        // Virtual secret credential fields — merge into record before fill/save
        $secretMappings = [
            'usps_adv_client_id' => 'client_id',
            'usps_adv_client_secret' => 'client_secret',
            'fedex_adv_api_key' => 'api_key',
            'fedex_adv_api_secret' => 'api_secret',
            'fedex_adv_sandbox_api_key' => 'sandbox_api_key',
            'fedex_adv_sandbox_api_secret' => 'sandbox_api_secret',
            'ups_adv_client_id' => 'client_id',
            'ups_adv_client_secret' => 'client_secret',
        ];

        foreach ($secretMappings as $formField => $secretKey) {
            if (isset($data[$formField]) && filled($data[$formField])) {
                $this->record->mergeSecret($secretKey, $data[$formField]);
            }
            unset($data[$formField]);
        }

        return $data;
    }

    public function testUspsConnection(): void
    {
        try {
            $pricingType = app(UspsAdapter::class)->detectPricingType($this->record);
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('USPS connection failed')
                ->body($e->getMessage())
                ->send();

            return;
        }

        if ($pricingType === 'CONTRACT') {
            Notification::make()
                ->success()
                ->title('USPS connected — CONTRACT pricing')
                ->body('Negotiated rates are available for this account.')
                ->send();
        } else {
            Notification::make()
                ->warning()
                ->title('USPS connected — RETAIL pricing')
                ->body('Authentication succeeded, but this account does not have EPS contract access. Standard retail rates will be used. Contact USPS to enable negotiated rates.')
                ->send();
        }
    }
}
