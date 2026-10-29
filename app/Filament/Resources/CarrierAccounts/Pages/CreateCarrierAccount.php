<?php

namespace App\Filament\Resources\CarrierAccounts\Pages;

use App\Filament\Concerns\ReturnsToRecordAwaitingOAuth;
use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Services\OAuthService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateCarrierAccount extends CreateRecord
{
    use ReturnsToRecordAwaitingOAuth;

    protected static string $resource = CarrierAccountResource::class;

    /**
     * A new account is followed by its OAuth trip or its edit page, never a
     * second blank form.
     */
    protected static bool $canCreateAnother = false;

    /** @var array<string, string> Secrets entered at create time, applied in afterCreate(). */
    private array $pendingSecrets = [];

    public function mount(): void
    {
        parent::mount();

        $this->returnToRecordAwaitingOAuth();
    }

    protected function isConnectedThroughOAuth(Model $record): bool
    {
        return $record instanceof CarrierAccount && app(OAuthService::class)->isAccountConnected($record);
    }

    /**
     * Names the OAuth trip when creating leads into one.
     */
    protected function getCreateFormAction(): Action
    {
        $action = parent::getCreateFormAction();
        $default = $action->getLabel();

        return $action->label(function () use ($default): string {
            $carrierName = filled($this->data['carrier_id'] ?? null)
                ? Carrier::whereKey($this->data['carrier_id'])->value('name')
                : null;
            $ownClientId = match ($carrierName) {
                Carrier::USPS => $this->data['usps_adv_client_id'] ?? null,
                Carrier::UPS => $this->data['ups_adv_client_id'] ?? null,
                default => null,
            };

            return app(OAuthService::class)->providerForNewCarrierAccount($carrierName, $ownClientId) === null
                ? $default
                : "Create & connect {$carrierName}";
        });
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $credentialFieldMap = [
            'fedex_production_account_number' => 'production_account_number',
            'fedex_sandbox_account_number' => 'sandbox_account_number',
            'ups_account_number' => 'account_number',
        ];

        foreach ($credentialFieldMap as $field => $credentialKey) {
            if (array_key_exists($field, $data)) {
                $data['credentials'] = array_merge(
                    $data['credentials'] ?? [],
                    [$credentialKey => $data[$field]]
                );
                unset($data[$field]);
            }
        }

        // Virtual secret credential fields — stash filled values for afterCreate(),
        // strip from $data so the model create doesn't see unknown keys.
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
                $this->pendingSecrets[$secretKey] = $data[$formField];
            }
            unset($data[$formField]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Apply any secret credentials stashed during mutateFormDataBeforeCreate().
        if (! empty($this->pendingSecrets)) {
            foreach ($this->pendingSecrets as $key => $value) {
                $this->record->mergeSecret($key, $value);
            }
            $this->record->save();
            $this->pendingSecrets = [];
        }

        // Create a global (null,null) scope so resolveForShipment() can find this
        // account, but only when no other account for the same carrier already
        // holds the global default slot (unique constraint on carrier+location+client).
        $globalTaken = CarrierAccountScope::whereNull('location_id')
            ->whereNull('client_id')
            ->where('carrier_id', $this->record->carrier_id)
            ->exists();

        if (! $globalTaken) {
            $this->record->scopes()->create(['location_id' => null, 'client_id' => null, 'rate_shop' => false]);
        } else {
            Notification::make()
                ->warning()
                ->title('No global scope assigned')
                ->body('Another account is already the global default for this carrier. Open the account after saving and use its Assignments section to assign it to specific locations.')
                ->persistent()
                ->send();
        }
    }

    /**
     * A USPS or UPS account that signs in through OAuth goes straight to the
     * carrier's consent screen; the callback returns to its edit page, where a
     * notification sent here, such as the global-scope warning, is shown. If
     * OAuth cannot start, the account is kept and the edit page says why.
     */
    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();
        $editUrl = CarrierAccountResource::getUrl('edit', ['record' => $record]);

        if (! $record instanceof CarrierAccount) {
            return $editUrl;
        }

        $carrierName = $record->carrier?->name;
        $oauth = app(OAuthService::class);
        $provider = $oauth->providerForNewCarrierAccount($carrierName, $record->secret('client_id'));

        if ($provider === null) {
            return $editUrl;
        }

        try {
            $url = $oauth->initiateAuthorization($provider, $record->id);
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title("Cannot connect {$carrierName}")
                ->body($e->getMessage())
                ->send();

            return $editUrl;
        }

        $this->rememberRecordAwaitingOAuth($record, (string) $carrierName);

        return $url;
    }
}
