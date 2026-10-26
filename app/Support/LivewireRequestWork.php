<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What a Livewire request asked for, by name only: the component, the methods
 * it called and the properties it updated. Every Livewire round trip posts to
 * the same URL, so this is what tells two of them apart in a log. Parameters
 * and values carry recipient data and are never returned. The one exception is
 * the name of a Filament action being mounted, which is code, not data.
 */
final class LivewireRequestWork
{
    /** Methods whose first parameter names a Filament action. */
    private const ACTION_METHODS = ['mountAction', 'mountTableAction', 'callMountedAction'];

    /**
     * @return list<array{component: string, calls: list<string>, updates: list<string>}>
     */
    public static function of(Request $request): array
    {
        $components = $request->input('components');

        if (! is_array($components)) {
            return [];
        }

        $work = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $snapshot = is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;

            $work[] = [
                'component' => (string) ($snapshot['memo']['name'] ?? 'unknown'),
                'calls' => array_values(array_map(self::callName(...), array_filter((array) ($component['calls'] ?? []), is_array(...)))),
                'updates' => array_map(strval(...), array_keys((array) ($component['updates'] ?? []))),
            ];
        }

        return $work;
    }

    /**
     * @param  array<string, mixed>  $call
     */
    private static function callName(array $call): string
    {
        $method = (string) ($call['method'] ?? 'unknown');
        $action = $call['params'][0] ?? null;

        return in_array($method, self::ACTION_METHODS, true) && is_string($action)
            ? "{$method}:{$action}"
            : $method;
    }
}
