<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\DataTransferObjects\QueryPreviewResult;
use App\Enums\AuditAction;
use App\Exceptions\PermanentExportException;
use App\Models\AuditLog;
use App\Services\ShipmentImport\FieldMapper;
use App\Services\ShipmentImport\RawSqlGuard;
use App\Services\SshTunnel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DatabaseSource implements DataSourceInterface, ExportDestinationInterface
{
    private array $config;

    private FieldMapper $fieldMapper;

    private ?SshTunnel $tunnel = null;

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
        $errors = [];

        try {
            $shipments = $this->previewShipmentRows($limit);
        } catch (\Throwable $e) {
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
                } catch (\Throwable $e) {
                    $errors['Items query'] = $e->getMessage();
                }
            }
        }

        return new QueryPreviewResult($shipments, $items, $itemsReference, $errors);
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

    public function exportPackage(array $data): void
    {
        $exportConfig = $this->config['export'] ?? [];

        if (empty($exportConfig['query'])) {
            throw new InvalidArgumentException('Export query is not configured for database source.');
        }

        $query = $this->normalizeQuery($exportConfig['query']);
        RawSqlGuard::assertStatementType($query, RawSqlGuard::EXPORT, 'export query');

        // Only pass parameters that the query actually references,
        // so the field_mapping can be a superset of what the query needs.
        preg_match_all('/:(\w+)/', $query, $matches);
        $queryParams = array_flip($matches[1]);
        $filteredData = array_intersect_key($data, $queryParams);

        // Log the bound parameter keys only — values may contain shipment PII.
        $this->executeLogged(
            'export_package',
            $query,
            array_keys($filteredData),
            fn (): int => $this->runCappedStatement($this->config['connection'], $query, $filteredData),
            [],
            fn (int $affected): array => ['affected_rows' => $affected],
        );
    }

    public function validateExportConfiguration(): void
    {
        $exportConfig = $this->config['export'] ?? [];

        if (empty($exportConfig['enabled'])) {
            throw new InvalidArgumentException('Export is not enabled for database source.');
        }

        if (empty($exportConfig['query'])) {
            throw new InvalidArgumentException('Export query is not configured for database source.');
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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

        $this->tunnel = SshTunnel::fromConfig([
            'ssh_host' => $sshConfig['host'],
            'ssh_port' => (int) ($sshConfig['port'] ?? 22),
            'ssh_user' => $sshConfig['user'],
            'ssh_key' => $sshConfig['key'],
            'remote_host' => $sshConfig['remote_host'] ?? $dbConfig['host'],
            'remote_port' => (int) ($sshConfig['remote_port'] ?? $dbConfig['port']),
            'known_hosts_entry' => $sshConfig['host_key'],
            'known_hosts_file' => $sshConfig['known_hosts_file'] ?? storage_path('app/private/ssh/import_known_hosts'),
        ]);

        $localPort = $this->tunnel->open();

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
