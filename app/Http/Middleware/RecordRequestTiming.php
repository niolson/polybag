<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs how long each request held its php-fpm worker and how much memory it
 * peaked at, when `LOG_REQUEST_TIMING` is on (`capacity-audit/01`).
 *
 * Every Livewire round trip posts to the same URL, so a Livewire request is
 * also logged by what it did: the component, the methods it called and the
 * properties it updated. Names only — parameters and values carry recipient
 * data and are never logged. The one exception is the name of a Filament
 * action being mounted, which is code, not data.
 */
class RecordRequestTiming
{
    /** Methods whose first parameter names a Filament action. */
    private const ACTION_METHODS = ['mountAction', 'mountTableAction', 'callMountedAction'];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * After the response has gone to the browser, but while the worker is
     * still held: the peak includes building and sending the response.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('app.request_timing')) {
            return;
        }

        $startedAt = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        Log::channel('request-timing')->info('request', array_filter([
            'method' => $request->method(),
            'route' => $request->route()?->getName() ?? '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'livewire' => $request->hasHeader('X-Livewire') ? $this->livewireWork($request) : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return list<array{component: string, calls: list<string>, updates: list<string>}>
     */
    private function livewireWork(Request $request): array
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
                'calls' => array_values(array_map($this->callName(...), array_filter((array) ($component['calls'] ?? []), is_array(...)))),
                'updates' => array_map(strval(...), array_keys((array) ($component['updates'] ?? []))),
            ];
        }

        return $work;
    }

    /**
     * @param  array<string, mixed>  $call
     */
    private function callName(array $call): string
    {
        $method = (string) ($call['method'] ?? 'unknown');
        $action = $call['params'][0] ?? null;

        return in_array($method, self::ACTION_METHODS, true) && is_string($action)
            ? "{$method}:{$action}"
            : $method;
    }
}
