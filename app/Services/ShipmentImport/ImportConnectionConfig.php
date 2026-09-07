<?php

namespace App\Services\ShipmentImport;

/**
 * Builds a Laravel database connection config from the flat `settings` of a
 * Database `DataSource`.
 *
 * Each driver takes a different shape, and the wrong keys are not harmless:
 * Laravel's Postgres connector writes `charset` into the DSN as
 * `client_encoding`, so a MySQL-flavoured `utf8mb4` makes every PostgreSQL
 * connection fail at connect time. `collation` and `strict` are MySQL-only, and
 * SQL Server needs its TLS arguments instead. Both the import runtime
 * (`DataSourceFactory`) and the form's Test Connection action build their
 * connection here so the connection being tested is the connection that runs.
 */
class ImportConnectionConfig
{
    /** @var array<string, string> Selectable drivers, keyed by Laravel driver name. */
    public const DRIVERS = [
        'mysql' => 'MySQL / MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'SQL Server',
        'sqlite' => 'SQLite',
    ];

    /** @var array<string, int> Conventional listening port per driver. */
    private const DEFAULT_PORTS = [
        'mysql' => 3306,
        'pgsql' => 5432,
        'sqlsrv' => 1433,
    ];

    /**
     * The conventional port for a driver, used as the form default when the
     * driver is switched. Null for drivers that do not connect over TCP.
     */
    public static function defaultPort(?string $driver): ?int
    {
        return self::DEFAULT_PORTS[$driver] ?? null;
    }

    /**
     * Whether the driver connects over TCP, and so can be probed for
     * reachability and routed through an SSH tunnel.
     */
    public static function usesHost(?string $driver): bool
    {
        return isset(self::DEFAULT_PORTS[$driver ?? '']);
    }

    /**
     * Bound how long a connection attempt may take, using whichever mechanism the
     * driver actually supports.
     *
     * pdo_sqlsrv rejects `PDO::ATTR_TIMEOUT` outright — it throws
     * `SQLSTATE[IMSSP]: An unsupported attribute was designated on the PDO
     * object` before it ever reaches the server, so passing it would make every
     * SQL Server connection fail regardless of whether the credentials are good.
     * Its connect timeout is the `LoginTimeout` DSN keyword instead, which
     * Laravel exposes as `login_timeout`. Note that msodbcsql retries a failed
     * connection once, so the wall-clock bound is roughly twice `$seconds`.
     *
     * @param  array<string, mixed>  $config  A config from {@see self::build()}.
     * @return array<string, mixed>
     */
    public static function withConnectTimeout(array $config, int $seconds): array
    {
        return match ($config['driver'] ?? null) {
            'sqlsrv' => $config + ['login_timeout' => $seconds],
            'sqlite' => $config,
            default => $config + ['options' => [\PDO::ATTR_TIMEOUT => $seconds]],
        };
    }

    /**
     * Bound how long a single statement may run on the server, for a connection
     * whose queries run inside a web request. A connect timeout does not cover
     * this: a query that reaches the server can then block indefinitely on a lock
     * or an unhelpful plan, holding a PHP worker until the reverse proxy gives up
     * with a 504.
     *
     * Best effort by design — a server that rejects the cap should still be
     * previewable, so a failure to set it is swallowed. SQL Server has no
     * server-side statement timeout to SET; pdo_sqlsrv aborts the query from the
     * client side instead, via an attribute on the PDO handle, and `LOCK_TIMEOUT`
     * is not a substitute because it only covers time spent blocked.
     */
    public static function applyStatementTimeout(\PDO $pdo, ?string $driver, int $seconds): void
    {
        if ($driver === 'sqlsrv') {
            if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
                $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $seconds);
            }

            return;
        }

        foreach (self::statementTimeoutStatements($driver, $seconds) as $statement) {
            try {
                $pdo->exec($statement);

                return;
            } catch (\PDOException) {
                // Try the next spelling, if there is one.
            }
        }
    }

    /**
     * Statements that cap server-side execution time, in the order to try them.
     * More than one only because MySQL and MariaDB disagree: MySQL 5.7.8+ spells
     * it `max_execution_time` in milliseconds, MariaDB spells it
     * `max_statement_time` in seconds, and neither recognises the other's
     * variable — so which one works identifies the server.
     *
     * @return list<string>
     */
    public static function statementTimeoutStatements(?string $driver, int $seconds): array
    {
        return match ($driver) {
            'mysql' => [
                'SET SESSION max_execution_time = '.($seconds * 1000),
                'SET SESSION max_statement_time = '.$seconds,
            ],
            'pgsql' => ['SET statement_timeout = '.($seconds * 1000)],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $settings  Flat DataSource settings (`db_*` keys).
     * @param  string|null  $password  Resolved separately — it lives in the encrypted secrets.
     * @return array<string, mixed>
     */
    public static function build(array $settings, ?string $password = null): array
    {
        $driver = $settings['db_driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => $settings['db_database'] ?? '',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ];
        }

        $config = [
            'driver' => $driver,
            'host' => $settings['db_host'] ?? '127.0.0.1',
            'port' => (int) (($settings['db_port'] ?? null) ?: self::defaultPort($driver)),
            'database' => $settings['db_database'] ?? null,
            'username' => $settings['db_username'] ?? null,
            'password' => $password,
            'prefix' => '',
        ];

        return match ($driver) {
            'pgsql' => $config + [
                'charset' => 'utf8',
                'sslmode' => 'prefer',
                'search_path' => ($settings['db_schema'] ?? null) ?: 'public',
            ],
            // msodbcsql18 flipped the default to Encrypt=yes, so an on-prem
            // SQL Server with a self-signed certificate now fails the handshake
            // unless the certificate is explicitly trusted.
            'sqlsrv' => $config + [
                'encrypt' => ($settings['db_encrypt'] ?? true) ? 'yes' : 'no',
                'trust_server_certificate' => ($settings['db_trust_server_certificate'] ?? false) ? 'yes' : 'no',
            ],
            default => $config + [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ],
        };
    }
}
