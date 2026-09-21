<?php

namespace App\Models;

use App\Http\Integrations\Fedex\FedexConnector;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class CarrierAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_id',
        'name',
        'credentials',
        'secret_credentials',
        'active',
    ];

    protected $hidden = [
        'secret_credentials',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'credentials' => 'array',
            'secret_credentials' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (CarrierAccount $account): void {
            if ($account->wasChanged('carrier_id')) {
                $account->restampScopes();
            }

            if ($account->wasChanged(['credentials', 'secret_credentials'])) {
                $account->clearTokenCaches();
            }
        });

        static::deleted(function (CarrierAccount $account): void {
            $account->clearTokenCaches();
        });
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return HasMany<CarrierAccountScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(CarrierAccountScope::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function credential(string $key): mixed
    {
        return $this->credentials[$key] ?? null;
    }

    public function secret(string $key): mixed
    {
        return $this->secret_credentials[$key] ?? null;
    }

    public function mergeCredential(string $key, mixed $value): void
    {
        $this->credentials = array_merge($this->credentials ?? [], [$key => $value]);
    }

    public function mergeSecret(string $key, mixed $value): void
    {
        $this->secret_credentials = array_merge($this->secret_credentials ?? [], [$key => $value]);
    }

    public function fedexAccountNumber(?string $environment = null): ?string
    {
        $environment ??= (bool) app(SettingsService::class)->get('sandbox_mode', false)
            ? 'sandbox'
            : 'production';

        $accountNumber = $this->credential($environment.'_account_number');

        if (filled($accountNumber)) {
            return (string) $accountNumber;
        }

        $legacyAccountNumber = $this->credential('account_number');

        if (blank($legacyAccountNumber)) {
            return null;
        }

        if (filled($this->secret('child_key'))) {
            $childEnvironment = $this->credential('child_env') ?? 'production';

            if ($childEnvironment !== $environment) {
                return null;
            }
        }

        return (string) $legacyAccountNumber;
    }

    /**
     * A digest of who this account bills as.
     *
     * An offer records the account that quoted it by id, but the adapters
     * read the billing identity — UPS's and FedEx's `account_number`, USPS's
     * `eps_account` and `crid` — fresh from `credentials` at purchase, and
     * those are editable. The same row with a different account number is a
     * different payer, so the offer stores this beside the id and the
     * purchase compares.
     *
     * Secrets are excluded on purpose. `OAuthService` writes refreshed tokens
     * into `secret_credentials` through {@see mergeSecret()}, and a rotated
     * client secret for the same account number still bills the same account;
     * neither may retire a quote. `updated_at` would move on every one of
     * those, which is why it is not used either.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'carrier_id' => $this->carrier_id,
            'credentials' => self::sortedRecursively($this->credentials ?? []),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function sortedRecursively(array $values): array
    {
        ksort($values);

        return array_map(
            fn (mixed $value): mixed => is_array($value) ? self::sortedRecursively($value) : $value,
            $values,
        );
    }

    public function connectionStatus(): string
    {
        return match ($this->carrier?->name) {
            'USPS', 'UPS' => filled($this->secret('oauth_token')) ? 'Connected' : 'Needs Setup',
            'FedEx' => $this->hasUsableCredentials() ? 'Connected' : 'Needs Setup',
            default => 'Active',
        };
    }

    public function hasUsableCredentials(): bool
    {
        return match ($this->carrier?->name) {
            'FedEx' => filled($this->fedexAccountNumber())
                && (
                    $this->hasFedexChildCredentialsForActiveEnvironment()
                    || $this->hasDirectFedexCredentials()
                ),
            'USPS' => filled($this->credential('crid'))
                && (
                    filled($this->secret('oauth_token'))
                    || (
                        filled($this->secret('client_id'))
                        && filled($this->secret('client_secret'))
                    )
                ),
            'UPS' => filled($this->credential('account_number'))
                && (
                    filled($this->secret('oauth_token'))
                    || (
                        filled($this->secret('client_id'))
                        && filled($this->secret('client_secret'))
                    )
                ),
            default => false,
        };
    }

    /**
     * Whether this FedEx account has direct client key/secret credentials for the
     * currently active environment. In sandbox mode the connector authenticates
     * with the sandbox_api_key/sandbox_api_secret pair, so those must gate
     * configuration too — otherwise a sandbox-only account is treated as unset up.
     *
     * @see FedexConnector::getParentCredentials()
     */
    private function hasDirectFedexCredentials(): bool
    {
        $isSandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);

        if ($isSandbox) {
            return filled($this->secret('sandbox_api_key'))
                && filled($this->secret('sandbox_api_secret'));
        }

        return filled($this->secret('api_key'))
            && filled($this->secret('api_secret'));
    }

    private function hasFedexChildCredentialsForActiveEnvironment(): bool
    {
        if (blank($this->secret('child_key')) || blank($this->secret('child_secret'))) {
            return false;
        }

        $childEnvironment = $this->credential('child_env') ?? 'production';
        $activeEnvironment = (bool) app(SettingsService::class)->get('sandbox_mode', false)
            ? 'sandbox'
            : 'production';

        return $childEnvironment === $activeEnvironment;
    }

    /**
     * Returns eligible CarrierAccount(s) for a shipment in priority order.
     *
     * Resolution priority (most specific first):
     *   1. (location, client)  — explicit client override at this location
     *   2. (location, null)    — location default
     *   3. (null, client)      — client default across all locations
     *   4. (null, null)        — global default for this carrier
     *
     * When the winning scope has rate_shop=true, the location-default account is
     * also returned so the caller can fetch rates from both and pick the cheapest.
     *
     * @return Collection<int, CarrierAccount>
     */
    public static function resolveForShipment(
        int $carrierId,
        ?int $locationId,
        ?int $clientId,
    ): Collection {
        $scopes = CarrierAccountScope::with('carrierAccount')
            ->whereHas('carrierAccount', fn (Builder $q) => $q->where('active', true))
            ->where('carrier_id', $carrierId)
            ->where(function (Builder $q) use ($locationId, $clientId): void {
                $q->where(fn ($q) => $q->where('location_id', $locationId)->where('client_id', $clientId))
                    ->orWhere(fn ($q) => $q->where('location_id', $locationId)->whereNull('client_id'))
                    ->orWhere(fn ($q) => $q->whereNull('location_id')->where('client_id', $clientId))
                    ->orWhere(fn ($q) => $q->whereNull('location_id')->whereNull('client_id'));
            })
            ->get();

        if ($scopes->isEmpty()) {
            return new Collection;
        }

        $priority = fn (CarrierAccountScope $scope): int => match (true) {
            $scope->location_id === $locationId && $scope->client_id === $clientId => 0,
            $scope->location_id === $locationId && $scope->client_id === null => 1,
            $scope->location_id === null && $scope->client_id === $clientId => 2,
            default => 3,
        };

        $sorted = $scopes->sortBy($priority);
        $bestScope = $sorted->first();
        $result = $bestScope->carrierAccount->newCollection([$bestScope->carrierAccount]);

        if ($bestScope->rate_shop && $locationId !== null) {
            $locationDefault = $sorted->first(
                fn (CarrierAccountScope $s): bool => $priority($s) === 1
                    && $s->carrierAccount->isNot($bestScope->carrierAccount)
            );

            if ($locationDefault) {
                $result->push($locationDefault->carrierAccount);
            }
        }

        return $result;
    }

    /**
     * Move this account's scopes to the carrier it now belongs to.
     *
     * `carrier_id` is denormalized onto `carrier_account_scopes` so the unique
     * index can enforce one account per (carrier, location, client), and the
     * scope derives it in its own `saving` hook — which cannot see the *account*
     * changing carriers. Left alone, the scope keeps pointing at the old
     * carrier, and `resolveForShipment()` then hands a FedEx account to a USPS
     * shipment and finds nothing at all for a FedEx one.
     *
     * A scope whose new tuple is already taken is deleted rather than moved: the
     * index would reject it, and a row naming a carrier this account no longer
     * belongs to can only resolve wrongly. The account is left visibly unscoped
     * for the operator to place deliberately, which is the honest state — they
     * moved it onto a carrier whose default was already spoken for.
     */
    private function restampScopes(): void
    {
        $stale = $this->scopes()->where('carrier_id', '!=', $this->carrier_id)->get();

        foreach ($stale as $scope) {
            if ($this->tupleTaken($scope)) {
                logger()->warning('Dropped a carrier account scope whose slot was taken on the new carrier', [
                    'carrier_account_id' => $this->id,
                    'carrier_account_scope_id' => $scope->id,
                    'from_carrier_id' => $scope->carrier_id,
                    'to_carrier_id' => $this->carrier_id,
                ]);

                $scope->delete();

                continue;
            }

            $scope->carrier_id = $this->carrier_id;
            $scope->save();
        }
    }

    /**
     * Whether another scope already holds this one's (location, client) slot on
     * the carrier this account moved to. Compared with `whereNull` rather than
     * `where(..., null)`, since a global scope's columns are null and SQL will
     * not match those with an equality test.
     */
    private function tupleTaken(CarrierAccountScope $scope): bool
    {
        return CarrierAccountScope::query()
            ->where('carrier_id', $this->carrier_id)
            ->where(fn (Builder $query) => $scope->location_id === null
                ? $query->whereNull('location_id')
                : $query->where('location_id', $scope->location_id))
            ->where(fn (Builder $query) => $scope->client_id === null
                ? $query->whereNull('client_id')
                : $query->where('client_id', $scope->client_id))
            ->whereKeyNot($scope->getKey())
            ->exists();
    }

    private function clearTokenCaches(): void
    {
        // USPS per-account caches. Token caches are namespaced by environment,
        // so both variants must go — a credential change invalidates each one.
        foreach (['', '_sandbox'] as $env) {
            Cache::forget("usps_payment_authorization_token{$env}:{$this->id}");
            Cache::forget("usps_payment_authorization_token{$env}:global");
            Cache::forget("usps_authenticator{$env}");
            Cache::forget("usps_authenticator{$env}:{$this->id}");
        }
        Cache::forget("usps_oauth_token:{$this->id}");
        // Detected CONTRACT/RETAIL pricing tier — must be re-probed after a credential change.
        Cache::forget("usps_pricing_type:{$this->id}");

        // FedEx: global caches, per-account parent caches, and child-key cache.
        foreach (['', '_sandbox'] as $env) {
            Cache::forget("fedex_authenticator{$env}");
            Cache::forget("fedex_authenticator{$env}:{$this->id}");
        }
        if ($childKey = $this->secret('child_key')) {
            $env = $this->credential('child_env') ?? 'production';
            Cache::forget('fedex_authenticator_child_'.$env.'_'.hash('sha256', $childKey));
        }

        // UPS: global caches + per-account caches (used when account has its own client_id)
        foreach (['', '_sandbox'] as $env) {
            Cache::forget("ups_authenticator{$env}");
            Cache::forget("ups_authenticator{$env}:{$this->id}");
        }
        Cache::forget('ups_oauth_token');
        Cache::forget("ups_oauth_token:{$this->id}");
    }
}
