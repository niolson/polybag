<?php

namespace App\Support;

/**
 * Gives the process room to report a fatal error, such as running out of
 * memory, before it exits (`import-memory/02`).
 *
 * Laravel's shutdown handler reports a fatal with only a 32 KB reserve, and
 * loading the classes that reporting needs can run out of memory again. A
 * fatal inside a shutdown function cancels every shutdown function after it:
 * the log line, the Sentry event and {@see FailQueuedJobOnFatalError} are
 * all lost. PHP runs shutdown functions in the order they were registered,
 * so this is registered from `bootstrap/app.php`, ahead of Laravel's.
 */
final class FatalErrorHeadroom
{
    /** Room to report the error and fail the job: autoloading, logging, a Sentry send, a notification. */
    public const int BYTES = 32 * 1024 * 1024;

    public const array FATAL_ERROR_TYPES = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];

    private static bool $registered = false;

    public static function registerBeforeLaravelReportsFatalErrors(): void
    {
        if (self::$registered) {
            return;
        }

        register_shutdown_function(static function (): void {
            if (self::lastErrorWasFatal()) {
                self::raise();
            }
        });

        self::$registered = true;
    }

    public static function lastErrorWasFatal(): bool
    {
        $error = error_get_last();

        return $error !== null && in_array($error['type'], self::FATAL_ERROR_TYPES, true);
    }

    /**
     * Raise the limit to what the process holds now plus the headroom. Never
     * lowers it, and leaves an unlimited process alone.
     */
    public static function raise(): void
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit === '-1') {
            return;
        }

        $raised = memory_get_usage(true) + self::BYTES;

        if ($raised > self::bytes($limit)) {
            ini_set('memory_limit', (string) $raised);
        }
    }

    private static function bytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
