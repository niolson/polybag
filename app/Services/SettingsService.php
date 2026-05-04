<?php

namespace App\Services;

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
        $settings = $this->getAllCached();

        return $settings[$key] ?? $default;
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
