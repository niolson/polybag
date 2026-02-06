<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'app_settings';

    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = self::getAllCached();

        return $settings[$key] ?? $default;
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, mixed $value, ?string $type = null, bool $encrypted = false, ?string $group = null): void
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

        self::clearCache();
    }

    /**
     * Set multiple settings at once.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $setting = Setting::find($key);

            if ($setting) {
                $setting->value = $value;
                $setting->save();
            }
        }

        self::clearCache();
    }

    /**
     * Get all settings in a group.
     *
     * @return array<string, mixed>
     */
    public static function getGroup(string $group): array
    {
        $settings = self::getAllCached();

        return collect($settings)
            ->filter(fn ($value, $key) => self::getGroupForKey($key) === $group)
            ->all();
    }

    /**
     * Clear the settings cache.
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get all settings, cached for performance.
     *
     * @return array<string, mixed>
     */
    private static function getAllCached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()
                ->pluck('value', 'key')
                ->all();
        });
    }

    /**
     * Get the group for a key from the database.
     */
    private static function getGroupForKey(string $key): ?string
    {
        return Setting::where('key', $key)->value('group');
    }
}
