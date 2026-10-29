<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Concerns\ReturnsToRecordAwaitingOAuth;
use App\Filament\Resources\DataSources\Concerns\SetsUpOffAmazonShipping;
use App\Filament\Resources\DataSources\DataSourceResource;
use App\Models\DataSource;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateDataSource extends CreateRecord
{
    use ReturnsToRecordAwaitingOAuth;
    use SetsUpOffAmazonShipping;

    protected static string $resource = DataSourceResource::class;

    /**
     * A new connection is followed by its OAuth trip or its edit page, never a
     * second blank form.
     */
    protected static bool $canCreateAnother = false;

    /** @var array<string, string> */
    private const array PROVIDER_NAMES = [
        'shopify' => 'Shopify',
        'sp-api' => 'Amazon',
    ];

    public function mount(): void
    {
        parent::mount();

        $this->returnToRecordAwaitingOAuth();
    }

    protected function isConnectedThroughOAuth(Model $record): bool
    {
        return $record instanceof DataSource && app(OAuthService::class)->isDataSourceConnected($record);
    }

    /**
     * Names the OAuth trip when creating leads into one.
     */
    protected function getCreateFormAction(): Action
    {
        $action = parent::getCreateFormAction();
        $default = $action->getLabel();

        return $action->label(function () use ($default): string {
            $provider = app(OAuthService::class)->providerForNewDataSource(
                $this->data['source_type'] ?? null,
                $this->data['settings'] ?? [],
            );

            return $provider === null ? $default : 'Create & connect '.self::PROVIDER_NAMES[$provider];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->validateMfaRequiredForAmazon($data);

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
     * A connection that signs in through OAuth goes straight to the store's
     * consent screen; the callback returns to its edit page. If OAuth cannot
     * start, the connection is kept and the edit page says why.
     */
    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();
        $editUrl = DataSourceResource::getUrl('edit', ['record' => $record]);

        if (! $record instanceof DataSource) {
            return $editUrl;
        }

        $oauth = app(OAuthService::class);
        $provider = $oauth->providerForNewDataSource($record->source_type, $record->secret_settings ?? []);

        if ($provider === null) {
            return $editUrl;
        }

        try {
            $url = $oauth->initiateAuthorizationForDataSource($provider, $record);
        } catch (RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title('Cannot connect '.self::PROVIDER_NAMES[$provider])
                ->body($e->getMessage())
                ->send();

            return $editUrl;
        }

        $this->rememberRecordAwaitingOAuth($record, self::PROVIDER_NAMES[$provider]);

        return $url;
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

        $message = 'Amazon SP-API connections give access to customer PII, so Multi-Factor Authentication must be required for all users before this connection can be active. Enable it in App Settings → Security first.';

        Notification::make()
            ->title('Multi-Factor Authentication required')
            ->body($message)
            ->danger()
            ->send();

        $this->addError('data.active', $message);

        throw new Halt;
    }
}
