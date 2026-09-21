<?php

namespace App\Filament\Resources\CarrierAccounts\Pages;

use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use App\Models\CarrierAccountScope;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCarrierAccount extends CreateRecord
{
    protected static string $resource = CarrierAccountResource::class;

    /** @var array<string, string> Secrets entered at create time, applied in afterCreate(). */
    private array $pendingSecrets = [];

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
                ->body('Another account is already the global default for this carrier. Use the Location Assignments section to assign this account to specific locations.')
                ->persistent()
                ->send();
        }
    }
}
