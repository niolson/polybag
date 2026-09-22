<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Resources\DataSources\Concerns\SetsUpOffAmazonShipping;
use App\Filament\Resources\DataSources\DataSourceResource;
use App\Models\DataSource;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateDataSource extends CreateRecord
{
    use SetsUpOffAmazonShipping;

    protected static string $resource = DataSourceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->validateMfaRequiredForAmazon($data);

        /**
         * Fulfillment-order import is deliberately not enabled here. It is a
         * one-way switch that requires Shopify scopes and mapped locations the
         * source cannot have at creation time, and enabling it blindly makes a
         * misconfigured source import nothing at all, silently: the fulfillment
         * order query returns an empty list rather than an error when the token
         * lacks `read_merchant_managed_fulfillment_orders`. It is turned on
         * later through ShopifyFulfillmentOrderActivationService, which checks
         * those prerequisites first.
         */
        $submitted = $data['settings'] ?? [];

        $secrets = [];

        foreach (DataSource::SECRET_SETTINGS_KEYS as $key) {
            if (array_key_exists($key, $submitted) && filled($submitted[$key])) {
                $secrets[$key] = $submitted[$key];
            }
            unset($submitted[$key]);
        }

        $data['settings'] = $submitted;
        $data['secret_settings'] = $secrets ?: null;

        return $data;
    }

    /**
     * A blank Client in multi-client mode means the connection is shared across
     * every client, as the field says. `HasDefaultClient` stamps the default
     * client on every new row, so it is cleared again here — otherwise a
     * shared Amazon connection would get a default-client assignment instead
     * of a global one, and never sell postage for other clients' orders.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        if (app(SettingsService::class)->get('multi_client_enabled', false) && blank($data['client_id'] ?? null)) {
            $record->forceFill(['client_id' => null])->saveQuietly();
        }

        return $record;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof DataSource && $record->isAmazon() && $record->offers_off_amazon_shipping) {
            $this->setUpOffAmazonShipping($record);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateMfaRequiredForAmazon(array $data): void
    {
        if (($data['source_type'] ?? null) !== AmazonSource::class || ! ($data['active'] ?? false)) {
            return;
        }

        if (app(SettingsService::class)->get('require_mfa', false)) {
            return;
        }

        $message = 'Amazon SP-API connections give access to customer PII, so Multi-Factor Authentication must be required for all users before this connection can be active. Enable it in App Settings → Authentication first.';

        Notification::make()
            ->title('Multi-Factor Authentication required')
            ->body($message)
            ->danger()
            ->send();

        $this->addError('data.active', $message);

        throw new Halt;
    }
}
