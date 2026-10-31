<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * An Amazon connection reads every marketplace of its seller it is told to,
 * not one (`connection-identity/01`). Its `settings.marketplace_id` becomes a
 * one-element `marketplace_ids` and the `primary_marketplace_id`.
 *
 * The import account key stops naming the marketplace, so a stored key that
 * named this connection's account and marketplace is rewritten to name the
 * account alone; otherwise every connection would restart its import from the
 * full lookback. A key naming anything else is left to restart, as it would
 * have. The key is computed here, as `DataSource::importAccountKeyFor()`
 * computes it today, so a later change to the model does not change this.
 */
return new class extends Migration
{
    private const AMAZON_SOURCE = 'App\Services\ShipmentImport\Sources\AmazonSource';

    public function up(): void
    {
        $this->rewrite(function (array $settings, ?string $key, ?string $refreshToken): array {
            $marketplaceId = $settings['marketplace_id'] ?? null;
            unset($settings['marketplace_id']);

            if (is_string($marketplaceId) && $marketplaceId !== '') {
                $settings['marketplace_ids'] = [$marketplaceId];
                $settings['primary_marketplace_id'] = $marketplaceId;
            }

            $account = $this->account($settings, $refreshToken);

            if ($key !== null && hash_equals(hash('sha256', $account.'|'.($marketplaceId ?? '')), $key)) {
                $key = hash('sha256', $account);
            }

            return [$settings, $key];
        });
    }

    public function down(): void
    {
        $this->rewrite(function (array $settings, ?string $key, ?string $refreshToken): array {
            $marketplaceIds = is_array($settings['marketplace_ids'] ?? null) ? $settings['marketplace_ids'] : [];
            $primary = $settings['primary_marketplace_id'] ?? ($marketplaceIds[0] ?? null);
            unset($settings['marketplace_ids'], $settings['primary_marketplace_id']);

            if (is_string($primary) && $primary !== '') {
                $settings['marketplace_id'] = $primary;
            }

            $account = $this->account($settings, $refreshToken);

            if ($key !== null && hash_equals(hash('sha256', $account), $key)) {
                $key = hash('sha256', $account.'|'.($settings['marketplace_id'] ?? ''));
            }

            return [$settings, $key];
        });
    }

    /**
     * The account an import reads: the seller ID on an OAuth connection, else
     * the connection's own refresh token, keyed with the app key.
     *
     * @param  array<string, mixed>  $settings
     */
    private function account(array $settings, ?string $refreshToken): string
    {
        return ($settings['auth_mode'] ?? null) === 'authorization_code'
            ? 'partner:'.($settings['amazon_selling_partner_id'] ?? '')
            : 'token:'.hash_hmac('sha256', (string) $refreshToken, (string) config('app.key'));
    }

    /**
     * @param  Closure(array<string, mixed>, ?string, ?string): array{0: array<string, mixed>, 1: ?string}  $rewrite
     */
    private function rewrite(Closure $rewrite): void
    {
        DB::table('data_sources')
            ->where('source_type', self::AMAZON_SOURCE)
            ->orderBy('id')
            ->get(['id', 'settings', 'secret_settings', 'import_account_key'])
            ->each(function (object $row) use ($rewrite): void {
                $settings = is_string($row->settings) ? json_decode($row->settings, true) : null;

                if (! is_array($settings)) {
                    return;
                }

                [$settings, $key] = $rewrite($settings, $row->import_account_key, $this->refreshToken($row->secret_settings));

                DB::table('data_sources')
                    ->where('id', $row->id)
                    ->update(['settings' => json_encode($settings), 'import_account_key' => $key]);
            });
    }

    /**
     * The refresh token in a row's `secret_settings`, stored as the model's
     * `encrypted:array` cast stores it.
     */
    private function refreshToken(?string $secretSettings): ?string
    {
        if ($secretSettings === null || $secretSettings === '') {
            return null;
        }

        try {
            $secrets = json_decode(Crypt::decryptString($secretSettings), true);
        } catch (DecryptException) {
            return null;
        }

        $refreshToken = is_array($secrets) ? ($secrets['refresh_token'] ?? null) : null;

        return is_string($refreshToken) ? $refreshToken : null;
    }
};
