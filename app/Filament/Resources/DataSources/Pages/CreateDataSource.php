<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Resources\DataSources\DataSourceResource;
use App\Models\DataSource;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateDataSource extends CreateRecord
{
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
