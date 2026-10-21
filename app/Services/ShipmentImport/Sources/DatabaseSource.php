<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\ClosesRecordsClosedAtSource;
use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\DataTransferObjects\QueryPreviewResult;
use App\DataTransferObjects\ShipmentImport\SourceClosureResult;
use App\Enums\AuditAction;
use App\Exceptions\PermanentExportException;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Services\ShipmentImport\DatabaseClosedRecordCloser;
use App\Services\ShipmentImport\FieldMapper;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\RawSqlGuard;
use App\Services\SshTunnel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class DatabaseSource implements ClosesRecordsClosedAtSource, DataSourceInterface, ExportDestinationInterface
{
    private array $config;

    private FieldMapper $fieldMapper;

    private ?SshTunnel $tunnel = null;

    /** Why the tunnel would not open, so a batch does not retry it per record. */
    private ?Throwable $tunnelFailure = null;

    /** Status query executions this run, for its one audit entry. */
    private int $statusLookups = 0;

    private int $failedStatusLookups = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->fieldMapper = new FieldMapper($this->config['field_mapping'] ?? []);
    }

    public function validateConfiguration(): void
    {
        $connection = $this->config['connection'] ?? null;

        if (! $connection) {
            throw new InvalidArgumentException('Database connection is not configured.');
        }

        $this->openTunnelIfConfigured();

        // Test connection
        try {
            DB::connection($connection)->getPdo();
        } catch (\Exception $e) {
            $this->closeTunnel();
            logger()->error('Import database connection failed', [
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidArgumentException('Cannot connect to import database. Check connection settings.');
        }
    }

    public function fetchShipments(): Collection
    {
        $connection = $this->config['connection'];

        // Use custom query if provided
        if (! empty($this->config['shipments_query'])) {
            $query = $this->normalizeQuery($this->config['shipments_query']);
            RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, 'custom shipments query');
            $results = $this->executeLogged(
                'fetch_shipments',
                $query,
                [],
                fn () => DB::connection($connection)->select($query),
            );
        } else {
            $results = $this->shipmentsTableQuery()->get();
        }

        // Map external fields to internal fields
        return collect($results)->map(fn ($row): array => $this->mapShipmentRow($row));
    }

    /**
     * Apply the configured field mapping to one raw source row. Shared with the
     * preview so what an operator sees in the modal is exactly what an import
     * would hand the importer.
     *
     * @return array<string, mixed>
     */
    private function mapShipmentRow(object|array $row): array
    {
        $mapped = $this->fieldMapper->mapShipment($row);

        // Carry over the raw client column value so the importer can resolve
        // the correct Client for per-row multi-client database imports.
        if ($clientColumn = $this->config['client_column'] ?? null) {
            $rawRow = (array) $row;
            $mapped['_client_column_value'] = $rawRow[$clientColumn] ?? null;
        }

        return $mapped;
    }

    public function fetchShipmentItems(string $sourceRecordId): Collection
    {
        $connection = $this->config['connection'];

        // Use custom query if provided
        if (! empty($this->config['shipment_items_query'])) {
            $query = $this->normalizeQuery($this->config['shipment_items_query']);
            RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, 'custom items query');
            $results = $this->executeLogged(
                'fetch_shipment_items',
                $query,
                ['shipment_reference'],
                fn () => DB::connection($connection)->select($query, ['shipment_reference' => $sourceRecordId]),
                ['shipment_reference' => $sourceRecordId],
            );
        } else {
            // Default: lookup by shipment_id field matching the reference
            $results = DB::connection($connection)
                ->table($this->config['shipment_items_table'])
                ->where('shipment_id', $sourceRecordId)
                ->get();
        }

        return collect($results)->map(function ($row): array {
            return $this->fieldMapper->mapShipmentItem($row);
        });
    }

    /**
     * Ask the status query about every Shipment of this source still open here,
     * and close those it reports shipped or cancelled (`order-reconciliation/08`).
     * Without a status query there is nothing to ask.
     *
     * The query runs once per open Shipment, so it is audited once per run
     * rather than once per execution: the query, how many Shipments it asked
     * about, and how many lookups failed.
     */
    public function closeRecordsClosedAtSource(DataSource $dataSource, Collection $shipments): SourceClosureResult
    {
        if (empty($this->config['status_query'])) {
            return new SourceClosureResult;
        }

        $query = $this->normalizeQuery($this->config['status_query']);
        RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, 'status query');

        $this->statusLookups = 0;
        $this->failedStatusLookups = 0;

        try {
            return app(DatabaseClosedRecordCloser::class)->close(
                $dataSource,
                fn (string $sourceRecordId): array => $this->lookUpStatus($query, $sourceRecordId),
            );
        } finally {
            $this->auditStatusLookups($query);
        }
    }

    /**
     * The run's one audit entry for the status query, when it ran at all.
     */
    private function auditStatusLookups(string $query): void
    {
        if ($this->statusLookups === 0) {
            return;
        }

        $this->logQueryExecution(
            'lookup_status',
            $query,
            ['shipment_reference'],
            $this->failedStatusLookups > 0 ? 'failed' : 'success',
            ['shipments_checked' => $this->statusLookups, 'failed_lookups' => $this->failedStatusLookups],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lookUpStatus(string $query, string $sourceRecordId): array
    {
        $this->statusLookups++;

        try {
            $rows = DB::connection($this->config['connection'])->select($query, ['shipment_reference' => $sourceRecordId]);
        } catch (Throwable $e) {
            $this->failedStatusLookups++;

            throw $e;
        }

        return array_values(array_map(fn (object|array $row): array => (array) $row, $rows));
    }

    public function getFieldMapping(): array
    {
        return $this->config['field_mapping'] ?? [];
    }

    /**
     * The table-based shipments query, used when no custom SQL is configured.
     */
    private function shipmentsTableQuery(): Builder
    {
        $query = DB::connection($this->config['connection'])
            ->table($this->config['shipments_table']);

        // Apply filters
        foreach ($this->config['filters'] ?? [] as $field => $values) {
            if (is_array($values)) {
                $query->whereIn($field, $values);
            } else {
                $query->where($field, $values);
            }
        }

        return $query;
    }

    /**
     * Execute the read queries and return a bounded sample of what they return —
     * each row as its raw source columns and as the internal fields the
     * configured field mapping resolves them to, so a mismatched mapping shows up
     * here rather than as a surprise mid-import.
     *
     * Only the read queries run. mark-exported and export are writes and are
     * never previewed. Each query is guarded and audited exactly as a real import
     * would guard and audit it; the returned rows are customer order data and are
     * deliberately kept out of both the audit trail and the logs.
     *
     * A failing query is reported against its label rather than aborting the whole
     * preview, so a broken items query still shows the shipment rows.
     */
    public function preview(int $limit = 5): QueryPreviewResult
    {
        $limit = max(1, $limit);

        $shipments = [];
        $items = [];
        $itemsReference = null;
        $statusRows = [];
        $statusReference = null;
        $errors = [];

        try {
            $shipments = $this->previewShipmentRows($limit);
        } catch (Throwable $e) {
            $errors['Shipments query'] = $e->getMessage();
        }

        if ($shipments !== []) {
            // Bind the items query the way the importer does: from the resolved
            // source record ID of the shipment being previewed.
            $reference = $this->sourceRecordIdFor($shipments[0]['mapped']);

            $itemsConfigured = ! empty($this->config['shipment_items_query'])
                || ! empty($this->config['shipment_items_table']);

            if ($reference !== null && $itemsConfigured) {
                $itemsReference = $reference;

                try {
                    $items = $this->previewItemRows($reference, $limit);
                } catch (Throwable $e) {
                    $errors['Items query'] = $e->getMessage();
                }
            }

            if ($reference !== null && ! empty($this->config['status_query'])) {
                $statusReference = $reference;

                try {
                    $statusRows = $this->previewStatusRows($reference, $limit);
                } catch (Throwable $e) {
                    $errors['Status query'] = $e->getMessage();
                }
            }
        }

        return new QueryPreviewResult($shipments, $items, $itemsReference, $errors, $statusRows, $statusReference);
    }

    /**
     * @return list<array{raw: array<string, mixed>, mapped: array<string, mixed>}>
     */
    private function previewShipmentRows(int $limit): array
    {
        if (! empty($this->config['shipments_query'])) {
            $query = $this->normalizeQuery($this->config['shipments_query']);
            RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, 'custom shipments query');

            $rows = $this->executeLogged(
                'preview_shipments',
                $query,
                [],
                fn (): array => $this->fetchBoundedRows($query, [], $limit),
                [],
                fn (array $rows): array => ['preview_rows' => count($rows)],
            );
        } else {
            $builder = $this->shipmentsTableQuery()->limit($limit);

            $rows = $this->executeLogged(
                'preview_shipments',
                $builder->toSql(),
                [],
                fn (): array => $this->takeRows($builder->cursor(), $limit),
                [],
                fn (array $rows): array => ['preview_rows' => count($rows)],
            );
        }

        return array_map(fn (array $row): array => [
            'raw' => $row,
            'mapped' => $this->mapShipmentRow($row),
        ], $rows);
    }

    /**
     * @return list<array{raw: array<string, mixed>, mapped: array<string, mixed>}>
     */
    private function previewItemRows(string $sourceRecordId, int $limit): array
    {
        if (! empty($this->config['shipment_items_query'])) {
            $query = $this->normalizeQuery($this->config['shipment_items_query']);
            RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, 'custom items query');

            // Unlike a real import, the bound reference is left out of the audit
            // metadata: it is read from a previewed row, and previewed rows stay
            // in the modal.
            $rows = $this->executeLogged(
                'preview_shipment_items',
                $query,
                ['shipment_reference'],
                fn (): array => $this->fetchBoundedRows($query, ['shipment_reference' => $sourceRecordId], $limit),
                [],
                fn (array $rows): array => ['preview_rows' => count($rows)],
            );
        } else {
            $builder = DB::connection($this->config['connection'])
                ->table($this->config['shipment_items_table'])
                ->where('shipment_id', $sourceRecordId)
                ->limit($limit);

            $rows = $this->executeLogged(
                'preview_shipment_items',
                $builder->toSql(),
                ['shipment_id'],
                fn (): array => $this->takeRows($builder->cursor(), $limit),
                [],
                fn (array $rows): array => ['preview_rows' => count($rows)],
            );
        }

        return array_map(fn (array $row): array => [
            'raw' => $row,
            'mapped' => $this->fieldMapper->mapShipmentItem($row),
        ], $rows);
    }

    /**
     * The status query's rows for the previewed shipment. It has no field
     * mapping, so the "mapped" side is just the columns the lookup reads, found
     * by name ignoring case: empty when the query's aliases are wrong.
     *
     * @return list<array{raw: array<string, mixed>, mapped: array<string, mixed>}>
     */
    private function previewStatusRows(string $sourceRecordId, int $limit): array
    {
        $query = $this->normalizeQuery($this->config['status_query']);
        RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, 'status query');

        // As for the items preview, the bound reference stays out of the audit.
        $rows = $this->executeLogged(
            'preview_status',
            $query,
            ['shipment_reference'],
            fn (): array => $this->fetchBoundedRows($query, ['shipment_reference' => $sourceRecordId], $limit),
            [],
            fn (array $rows): array => ['preview_rows' => count($rows)],
        );

        return array_map(fn (array $row): array => [
            'raw' => $row,
            'mapped' => array_intersect_key(
                array_change_key_case($row, CASE_LOWER),
                array_flip(DatabaseClosedRecordCloser::COLUMNS),
            ),
        ], $rows);
    }

    /**
     * Execute an admin-authored read query and take at most $limit rows.
     *
     * The statement runs verbatim — appending a LIMIT would rewrite it, and the
     * three supported drivers spell the clause differently (LIMIT / TOP / FETCH
     * FIRST) — so the bound is applied by stopping consumption instead.
     *
     * Stopping consumption is only half a bound on MySQL, which is why this drops
     * to PDO rather than using the connection's `cursor()`: pdo_mysql buffers the
     * entire result set into the worker's memory at execute() unless the handle
     * is switched to unbuffered mode, so previewing an unfiltered table would
     * hold all of it in memory before the first five rows were read — measured at
     * ~15 MB per 100k rows, and it is the operator's query that decides how many
     * there are. The same option passed to prepare() is ignored; it has to be set
     * on the handle, and is restored afterwards because import connections
     * outlive a preview. pdo_sqlsrv streams by default, and libpq offers PDO no
     * equivalent short of a server-side cursor, which would mean rewriting the
     * statement.
     *
     * @param  array<string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function fetchBoundedRows(string $query, array $bindings, int $limit): array
    {
        $connection = DB::connection($this->config['connection']);
        $pdo = $connection->getPdo();

        $unbuffered = $connection->getDriverName() === 'mysql'
            && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');

        if ($unbuffered) {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $statement = $pdo->prepare($query);
            $statement->execute($bindings);

            $rows = [];

            while (count($rows) < $limit && ($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $rows[] = $row;
            }

            // An unbuffered statement holds the connection until its result set
            // is released, and the items preview runs on this connection next.
            $statement->closeCursor();

            return $rows;
        } finally {
            if ($unbuffered) {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }

    /**
     * Consume at most $limit rows from a lazy result set. Used for the
     * table-based preview, where the query is ours and carries a real LIMIT — the
     * bound here is only a guard against that changing.
     *
     * @param  iterable<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function takeRows(iterable $rows, int $limit): array
    {
        $collected = [];

        foreach ($rows as $row) {
            $collected[] = (array) $row;

            if (count($collected) >= $limit) {
                break;
            }
        }

        return $collected;
    }

    /**
     * The reference an import would key this shipment by, mirroring
     * ShipmentRowPreparer: the mapped source record ID, falling back to the
     * shipment reference.
     *
     * @param  array<string, mixed>  $mapped
     */
    private function sourceRecordIdFor(array $mapped): ?string
    {
        $reference = $mapped['source_record_id'] ?? $mapped['shipment_reference'] ?? null;

        return filled($reference) ? (string) $reference : null;
    }

    public function markExported(string $sourceRecordId): bool
    {
        $markExported = $this->config['mark_exported'] ?? [];

        if (empty($markExported['enabled']) || empty($markExported['query'])) {
            return false;
        }

        $query = $this->normalizeQuery($markExported['query']);
        RawSqlGuard::assertStatementType($query, RawSqlGuard::MARK_EXPORTED, 'mark-exported query');
        $this->executeLogged(
            'mark_exported',
            $query,
            ['shipment_reference'],
            fn (): int => $this->runCappedStatement(
                $this->config['connection'],
                $query,
                ['shipment_reference' => $sourceRecordId],
            ),
            ['shipment_reference' => $sourceRecordId],
            fn (int $affected): array => ['affected_rows' => $affected],
        );

        return true;
    }

    public function getDestinationName(): string
    {
        return 'database';
    }

    public function exportPackage(array $data): ?string
    {
        $exportConfig = $this->config['export'] ?? [];

        if (empty($exportConfig['query'])) {
            throw new InvalidArgumentException('Export query is not configured for this connection.');
        }

        $query = $this->normalizeQuery($exportConfig['query']);
        RawSqlGuard::assertStatementType($query, RawSqlGuard::EXPORT, 'export query');

        // Bind only what the query names, so the export can supply more than
        // any one query needs. A name it cannot supply is refused here, by
        // name, rather than left for PDO to reject as HY093.
        $queryParams = array_flip(self::namedParameters($query));
        $unsupplied = array_keys(array_diff_key($queryParams, $data));

        if ($unsupplied !== []) {
            throw new PermanentExportException(
                'Export query names '.implode(', ', array_map(fn (string $name): string => ':'.$name, $unsupplied))
                .', which the export does not supply.'
            );
        }

        $filteredData = array_intersect_key($data, $queryParams);

        // Held open for this driver's life, so a batch sharing one driver
        // shares one tunnel. See PackageExportService::batch().
        $this->openTunnelIfConfigured();

        // Log the bound parameter keys only — values may contain shipment PII.
        $this->executeLogged(
            'export_package',
            $query,
            array_keys($filteredData),
            fn (): int => $this->runCappedStatement($this->config['connection'], $query, $filteredData),
            [],
            fn (int $affected): array => ['affected_rows' => $affected],
        );

        return null;
    }

    /**
     * The named parameters a query binds, in order of first use.
     *
     * Quoted text and comments are skipped, and so is a `::` cast, the way PDO
     * skips them, so `'12:30'` or Postgres's `created_at::date` names nothing.
     *
     * @return list<string>
     */
    public static function namedParameters(string $query): array
    {
        $code = preg_replace(
            "/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.|\"\")*\"|`[^`]*`|--[^\\n]*|\\/\\*.*?\\*\\//s",
            ' ',
            $query,
        ) ?? $query;

        preg_match_all('/(?<!:):(\w+)/', $code, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Refuse an export query that names a parameter the export cannot supply.
     */
    public static function exportQueryParametersRule(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! is_string($value)) {
                    return;
                }

                $unsupplied = array_diff(DatabaseSource::namedParameters($value), PackageExportService::ExportValues);

                if ($unsupplied !== []) {
                    $fail('Export Query names '.implode(', ', array_map(fn (string $name): string => ':'.$name, $unsupplied))
                        .', which the export does not supply.');
                }
            }
        };
    }

    public function validateExportConfiguration(): void
    {
        $exportConfig = $this->config['export'] ?? [];

        if (empty($exportConfig['enabled'])) {
            throw new InvalidArgumentException('Export is not enabled for this connection.');
        }

        if (empty($exportConfig['query'])) {
            throw new InvalidArgumentException('Export query is not configured for this connection.');
        }

        // Test connection
        $connection = $this->config['connection'] ?? null;

        if (! $connection) {
            throw new InvalidArgumentException('Database connection is not configured.');
        }

        $this->openTunnelIfConfigured();

        try {
            DB::connection($connection)->getPdo();
        } catch (\Exception $e) {
            $this->closeTunnel();
            logger()->error('Export database connection failed', [
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidArgumentException('Cannot connect to export database. Check connection settings.');
        }
    }

    /**
     * Run an admin-authored raw SQL query and record its execution outcome, so
     * scheduled import/export runs leave an accurate trace of what actually ran
     * and whether it succeeded. The query is logged only after it resolves — a
     * failure is recorded with status `failed` and the exception is re-thrown, so
     * a failed query never leaves an audit record claiming it executed.
     *
     * @template TResult
     *
     * @param  list<string>  $parameterKeys
     * @param  callable(): TResult  $run
     * @param  array<string, mixed>  $extra
     * @param  (callable(TResult): array<string, mixed>)|null  $successMetadata  Derive extra
     *                                                                           audit metadata (e.g. affected-row count) from the result on success.
     * @return TResult
     */
    private function executeLogged(string $operation, string $query, array $parameterKeys, callable $run, array $extra = [], ?callable $successMetadata = null)
    {
        try {
            $result = $run();
        } catch (Throwable $e) {
            $this->logQueryExecution($operation, $query, $parameterKeys, 'failed', $extra);
            throw $e;
        }

        if ($successMetadata !== null) {
            $extra = array_merge($extra, $successMetadata($result));
        }

        $this->logQueryExecution($operation, $query, $parameterKeys, 'success', $extra);

        return $result;
    }

    /**
     * Run a single-record write inside a transaction, rolling back (and throwing)
     * if it affects more rows than the configured cap. mark-exported and export
     * both run once per record, so a query that touches many rows means a missing
     * or too-broad WHERE — this bounds the blast radius of such a misconfiguration
     * even though the statement type itself is allowed. See security review issue 07.
     */
    private function runCappedStatement(string $connection, string $query, array $bindings): int
    {
        $cap = $this->maxAffectedRows();

        return DB::connection($connection)->transaction(function () use ($connection, $query, $bindings, $cap): int {
            $affected = DB::connection($connection)->affectingStatement($query, $bindings);

            if ($affected > $cap) {
                throw new PermanentExportException(
                    "Query affected {$affected} rows, exceeding the configured limit of {$cap} — rolled back."
                );
            }

            return $affected;
        });
    }

    /**
     * Per-source cap on how many rows a single mark-exported/export write may
     * affect. Defaults to 1 (these are per-record operations); raise it only for a
     * source whose schema legitimately stores multiple rows per shipment.
     */
    private function maxAffectedRows(): int
    {
        return max(1, (int) ($this->config['max_affected_rows'] ?? 1));
    }

    /**
     * Record a raw-SQL execution to the audit log. Captures the query's identity
     * (hash + truncated preview), bound parameter keys, and success/failure
     * status — never secret values or PII. See security review issue 18.
     *
     * @param  list<string>  $parameterKeys
     * @param  array<string, mixed>  $extra
     */
    private function logQueryExecution(string $operation, string $query, array $parameterKeys, string $status, array $extra = []): void
    {
        try {
            AuditLog::record(
                AuditAction::DataSourceQueryExecuted,
                metadata: array_merge([
                    'operation' => $operation,
                    'status' => $status,
                    'connection' => $this->config['connection'] ?? null,
                    'data_source_id' => $this->config['data_source_id'] ?? null,
                    'query_hash' => hash('sha256', $query),
                    'query_preview' => Str::limit($query, 300),
                    'parameters' => $parameterKeys,
                ], $extra),
            );
        } catch (Throwable $e) {
            // Audit logging must never break an import/export run.
            logger()->warning('Failed to record DataSource query audit log', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Replace non-breaking spaces and other Unicode whitespace variants with
     * ASCII spaces so the query executes correctly on MySQL/MariaDB.
     */
    private function normalizeQuery(string $query): string
    {
        return str_replace("\u{00A0}", ' ', $query);
    }

    /**
     * Open an SSH tunnel if configured and override the DB connection host/port.
     */
    private function openTunnelIfConfigured(): void
    {
        if ($this->tunnel !== null) {
            return;
        }

        if ($this->tunnelFailure !== null) {
            throw $this->tunnelFailure;
        }

        $sshConfig = $this->config['ssh'] ?? [];

        if (empty($sshConfig['enabled'])) {
            return;
        }

        foreach (['host', 'user', 'key', 'host_key'] as $required) {
            if (empty($sshConfig[$required])) {
                throw new InvalidArgumentException("SSH tunnel config missing: ssh.{$required}");
            }
        }

        $connection = $this->config['connection'];
        $dbConfig = config("database.connections.{$connection}");

        $tunnel = app()->make(SshTunnel::class, [
            'sshHost' => $sshConfig['host'],
            'sshPort' => (int) ($sshConfig['port'] ?? 22),
            'sshUser' => $sshConfig['user'],
            'sshKey' => $sshConfig['key'],
            'remoteHost' => $sshConfig['remote_host'] ?? $dbConfig['host'],
            'remotePort' => (int) ($sshConfig['remote_port'] ?? $dbConfig['port']),
            'knownHostsEntry' => $sshConfig['host_key'],
            'knownHostsFile' => $sshConfig['known_hosts_file'] ?? storage_path('app/private/ssh/import_known_hosts'),
        ]);

        try {
            $localPort = $tunnel->open();
        } catch (Throwable $e) {
            $this->tunnelFailure = $e;

            throw $e;
        }

        $this->tunnel = $tunnel;

        // Point the DB connection through the tunnel
        config([
            "database.connections.{$connection}.host" => '127.0.0.1',
            "database.connections.{$connection}.port" => $localPort,
        ]);

        // Purge any cached connection so it reconnects through the tunnel
        DB::purge($connection);
    }

    /**
     * Close the SSH tunnel and restore the DB connection config.
     */
    public function closeTunnel(): void
    {
        if ($this->tunnel === null) {
            return;
        }

        $this->tunnel->close();
        $this->tunnel = null;

        // Purge the tunneled connection
        $connection = $this->config['connection'] ?? null;
        if ($connection) {
            DB::purge($connection);
        }
    }

    public function __destruct()
    {
        $this->closeTunnel();
    }
}
