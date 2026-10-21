<?php

namespace App\Services;

use App\Enums\AppMode;
use App\Models\DataSource;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'app_settings';

    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Request-scoped in-memory cache to avoid repeated database
     * cache lookups within the same request (the cache driver is
     * database, so every Cache::remember() is a DB query).
     *
     * Instance properties (not static) so they reset naturally per
     * request. Register this service as a singleton in the container
     * to share within a single request.
     */
    private ?array $resolved = null;

    /**
     * Get a setting value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'sandbox_mode' => $this->resolveSandboxMode($default),
            'suppress_printing' => $this->resolveSuppressPrinting($default),
            'multi_client_enabled' => (bool) config('features.multi_client_enabled', $default),
            'multi_location_enabled' => (bool) config('features.multi_location_enabled', $default),
            default => $this->getAllCached()[$key] ?? $default,
        };
    }

    /**
     * Whether carrier integrations should use their sandbox environment.
     * The single source of truth for the shared sandbox_mode toggle.
     */
    public function isSandboxMode(): bool
    {
        return (bool) $this->get('sandbox_mode', false);
    }

    /**
     * Whether PolyBag prints pack slips. Off for a tenant whose ERP prints its own,
     * which hides every pack slip screen and indicator. On unless saved off, so an
     * install that never saved it keeps its pack slips.
     */
    public function packSlipsEnabled(): bool
    {
        return (bool) $this->get('pack_slips_enabled', true);
    }

    /**
     * Whether Amazon-specific screens and columns are shown. On when the
     * `amazon_features_enabled` setting is on, which an install importing
     * Amazon orders through a Database connection turns on by hand, or while
     * any Amazon connection is active. Computed, never written back: turning
     * the last Amazon connection off hides them again unless the setting is on.
     * Hiding only; imports, rules and packing behave the same either way.
     */
    public function amazonFeaturesEnabled(): bool
    {
        return (bool) $this->get('amazon_features_enabled', false) || DataSource::hasActiveAmazonConnection();
    }

    private function resolveSandboxMode(mixed $default): bool
    {
        return match (AppMode::current()) {
            AppMode::Production => false,
            AppMode::Demo => true,
            AppMode::Dev => (bool) ($this->getAllCached()['sandbox_mode'] ?? $default),
        };
    }

    private function resolveSuppressPrinting(mixed $default): bool
    {
        if (AppMode::current() !== AppMode::Dev) {
            return false;
        }

        return (bool) ($this->getAllCached()['suppress_printing'] ?? $default);
    }

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value, ?string $type = null, bool $encrypted = false, ?string $group = null): void
    {
        $setting = Setting::find($key);

        if ($setting) {
            // Update existing - preserve type/encrypted/group if not specified
            $setting->type = $type ?? $setting->type;
            $setting->encrypted = $encrypted ?: $setting->encrypted;
            $setting->group = $group ?? $setting->group;
            $setting->value = $value;
            $setting->save();
        } else {
            // Create new
            Setting::create([
                'key' => $key,
                'value' => $value,
                'type' => $type ?? 'string',
                'encrypted' => $encrypted,
                'group' => $group,
            ]);
        }

        $this->clearCache();
    }

    /**
     * Set multiple settings at once.
     *
     * @param  array<string, mixed>  $settings
     */
    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $setting = Setting::find($key);

            if ($setting) {
                $setting->value = $value;
                $setting->save();
            }
        }

        $this->clearCache();
    }

    /**
     * Clear the settings cache.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->resolved = null;
    }

    /**
     * Get all settings, cached for performance.
     *
     * @return array<string, mixed>
     */
    private function getAllCached(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()
                ->pluck('value', 'key')
                ->all();
        });
    }
}
