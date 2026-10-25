<?php

use App\Logging\CustomizeFormatter;
use App\Logging\DeepNormalizerTap;
use App\Logging\PiiRedactionTap;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

// During unit tests, several channels below are written to directly by name
// (e.g. Log::channel('fedex-validation')) regardless of the default channel,
// so they'd otherwise fill up their production log files on every test run.
// Route them into the same storage/logs/testing.log instead.
$isTesting = env('APP_ENV') === 'testing';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'tap' => [CustomizeFormatter::class],
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'testing' => [
            'driver' => 'single',
            'tap' => [CustomizeFormatter::class],
            'path' => storage_path('logs/testing.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'shipment-import' => [
            'driver' => $isTesting ? 'single' : 'daily',
            'path' => $isTesting ? storage_path('logs/testing.log') : storage_path('logs/shipment-import.log'),
            'level' => env('SHIPMENT_IMPORT_LOG_LEVEL', 'info'),
            'days' => 14,
            'replace_placeholders' => true,
        ],

        // One JSON line per request when LOG_REQUEST_TIMING is on: wall time and
        // peak memory, for sizing the php-fpm pool (`capacity-audit/01`).
        'request-timing' => [
            'driver' => $isTesting ? 'single' : 'daily',
            'path' => $isTesting ? storage_path('logs/testing.log') : storage_path('logs/request-timing.log'),
            'level' => 'info',
            'days' => 7,
            'formatter' => JsonFormatter::class,
        ],

        // Browser CSP violation reports (report-uri), for local policy tuning.
        'csp' => [
            'driver' => $isTesting ? 'single' : 'daily',
            'path' => $isTesting ? storage_path('logs/testing.log') : storage_path('logs/csp.log'),
            'level' => 'debug',
            'days' => 14,
            'replace_placeholders' => true,
        ],

        /*
        | Carrier validation channels log full API request/response payloads.
        | PII (names, addresses, phones) is redacted by default via
        | PiiRedactionTap. Set CARRIER_API_LOGGING=true to log unredacted —
        | needed for FedEx certification runs, which require real evidence —
        | and only for that or local debugging, never left on in production.
        */

        'fedex-validation' => [
            'driver' => $isTesting ? 'single' : 'daily',
            'path' => $isTesting ? storage_path('logs/testing.log') : storage_path('logs/fedex-validation.log'),
            'level' => 'debug',
            'days' => 14,
            'replace_placeholders' => true,
            'tap' => [DeepNormalizerTap::class, PiiRedactionTap::class],
        ],

        'usps-validation' => [
            'driver' => $isTesting ? 'single' : 'daily',
            'path' => $isTesting ? storage_path('logs/testing.log') : storage_path('logs/usps-validation.log'),
            'level' => 'debug',
            'days' => 14,
            'replace_placeholders' => true,
            'tap' => [DeepNormalizerTap::class, PiiRedactionTap::class],
        ],

        'google-validation' => [
            'driver' => $isTesting ? 'single' : 'daily',
            'path' => $isTesting ? storage_path('logs/testing.log') : storage_path('logs/google-validation.log'),
            'level' => 'debug',
            'days' => 14,
            'replace_placeholders' => true,
            'tap' => [DeepNormalizerTap::class, PiiRedactionTap::class],
        ],

        'ups-validation' => [
            'driver' => $isTesting ? 'single' : 'daily',
            'path' => $isTesting ? storage_path('logs/testing.log') : storage_path('logs/ups-validation.log'),
            'level' => 'debug',
            'days' => 14,
            'replace_placeholders' => true,
            'tap' => [DeepNormalizerTap::class, PiiRedactionTap::class],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Carrier API Logging (unredacted)
    |--------------------------------------------------------------------------
    |
    | When true, the carrier validation channels above log full unredacted
    | payloads. Needed for FedEx certification runs; otherwise PII is
    | redacted by PiiRedactionTap. Never leave this true in production.
    |
    */

    'carrier_api_logging' => env('CARRIER_API_LOGGING', false),

];
