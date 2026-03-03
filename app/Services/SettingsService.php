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
     */
    private static ?array $resolved = null;

    private static ?array $resolvedGroups = null;

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
     * Get all settings in a group.
     *
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        $groupMap = $this->getGroupMapCached();
        $settings = $this->getAllCached();

        return collect($groupMap)
            ->filter(fn ($settingGroup) => $settingGroup === $group)
            ->intersectByKeys($settings)
            ->map(fn ($settingGroup, $key) => $settings[$key])
            ->all();
    }

    /**
     * Clear the settings cache.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.'_groups');
        static::$resolved = null;
        static::$resolvedGroups = null;
    }

    /**
     * Get all settings, cached for performance.
     *
     * @return array<string, mixed>
     */
    private function getAllCached(): array
    {
        if (static::$resolved !== null) {
            return static::$resolved;
        }

        return static::$resolved = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()
                ->pluck('value', 'key')
                ->all();
        });
    }

    /**
     * Get key => group mapping, cached alongside settings.
     *
     * @return array<string, string|null>
     */
    private function getGroupMapCached(): array
    {
        if (static::$resolvedGroups !== null) {
            return static::$resolvedGroups;
        }

        return static::$resolvedGroups = Cache::remember(self::CACHE_KEY.'_groups', self::CACHE_TTL, function () {
            return Setting::pluck('group', 'key')
                ->all();
        });
    }
}
