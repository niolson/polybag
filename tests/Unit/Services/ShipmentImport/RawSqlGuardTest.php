<?php

use App\Services\ShipmentImport\RawSqlGuard;

it('allows the permitted statement type', function (array $allowed, string $sql): void {
    expect(fn () => RawSqlGuard::assertStatementType($sql, $allowed, 'test query'))
        ->not->toThrow(InvalidArgumentException::class);
})->with([
    'select read' => [RawSqlGuard::READ, 'SELECT * FROM shipments WHERE id = :id'],
    'lowercase select' => [RawSqlGuard::READ, 'select id, name from shipments'],
    'update mark-exported' => [RawSqlGuard::MARK_EXPORTED, "update shipments set exported = 'y' where id = :shipment_reference"],
    'insert mark-exported' => [RawSqlGuard::MARK_EXPORTED, 'INSERT INTO export_log (ref) VALUES (:shipment_reference)'],
    'insert export' => [RawSqlGuard::EXPORT, 'insert into package_export (tracking_num) values (:tracking_number)'],
    'trailing semicolon' => [RawSqlGuard::READ, 'SELECT 1;'],
    'leading comment' => [RawSqlGuard::READ, "-- pull open orders\nSELECT * FROM shipments"],
    'block comment' => [RawSqlGuard::READ, '/* open orders */ SELECT * FROM shipments'],
    'keyword inside a string' => [RawSqlGuard::READ, "SELECT * FROM orders WHERE note <> 'delete; drop' AND status = 'it''s open'"],
    'keyword inside a comment' => [RawSqlGuard::READ, "SELECT * FROM orders -- never delete these\nWHERE id = :id"],
    'comment at the end' => [RawSqlGuard::READ, 'SELECT * FROM orders --'],
    'arithmetic' => [RawSqlGuard::READ, 'SELECT qty - 1, price * - 1 FROM orders'],
    'quoted identifiers' => [RawSqlGuard::READ, 'SELECT [Print Date], "use", `exec` FROM orders'],
    'keyword as part of a name' => [RawSqlGuard::READ, 'SELECT delete_flag, drop_ship, created_at, updated_at FROM orders'],
    'subquery and union' => [RawSqlGuard::READ, 'SELECT id FROM a WHERE id IN (SELECT id FROM b) UNION ALL SELECT id FROM c'],
    'row lock' => [RawSqlGuard::READ, 'SELECT id FROM orders FOR UPDATE'],
    'table hint and paging' => [RawSqlGuard::READ, 'SELECT id FROM orders WITH (NOLOCK) ORDER BY id OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY'],
    'case expression' => [RawSqlGuard::READ, "SELECT CASE WHEN a = 1 THEN 'x' ELSE 'y' END AS kind, REPLACE(sku, '-', '') FROM orders"],
    'insert from select' => [RawSqlGuard::EXPORT, 'INSERT INTO export_log (ref) SELECT ref FROM orders WHERE ref = :shipment_reference'],
    'insert or update' => [RawSqlGuard::EXPORT, 'INSERT INTO t (ref, n) VALUES (:ref, 1) ON DUPLICATE KEY UPDATE n = n + 1'],
]);

it('rejects a disallowed statement type', function (array $allowed, string $sql): void {
    expect(fn () => RawSqlGuard::assertStatementType($sql, $allowed, 'test query'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'delete as read' => [RawSqlGuard::READ, 'DELETE FROM shipments WHERE 1=1'],
    'delete as mark-exported' => [RawSqlGuard::MARK_EXPORTED, 'DELETE FROM shipments WHERE id = :shipment_reference'],
    'drop as export' => [RawSqlGuard::EXPORT, 'DROP TABLE package_export'],
    'truncate' => [RawSqlGuard::MARK_EXPORTED, 'TRUNCATE shipments'],
    'create as export' => [RawSqlGuard::EXPORT, 'CREATE TABLE evil (id INT)'],
    'cte hiding a delete' => [RawSqlGuard::READ, 'WITH x AS (SELECT 1) DELETE FROM shipments'],
    'comment hiding a delete' => [RawSqlGuard::READ, '/* SELECT */ DELETE FROM shipments'],
    'statement stacking' => [RawSqlGuard::MARK_EXPORTED, "UPDATE shipments SET exported = 'y' WHERE id = :shipment_reference; DROP TABLE shipments"],
    'second read statement without a semicolon' => [RawSqlGuard::READ, 'SELECT * FROM orders DELETE FROM orders'],
    'second write statement without a semicolon' => [RawSqlGuard::MARK_EXPORTED, 'UPDATE orders SET x = 1 WHERE ref = :shipment_reference DROP TABLE orders'],
    'procedure call after a write' => [RawSqlGuard::EXPORT, "INSERT INTO t (a) VALUES (:a) EXEC xp_cmdshell 'whoami'"],
    'dynamic sql after a write' => [RawSqlGuard::EXPORT, "INSERT INTO t (a) VALUES (:a) EXEC('DROP TABLE t')"],
    'insert after a read' => [RawSqlGuard::READ, 'SELECT * FROM orders INSERT INTO t VALUES (1)'],
    'update after a read' => [RawSqlGuard::READ, 'SELECT * FROM orders UPDATE orders SET x = 1'],
    'select into a table' => [RawSqlGuard::READ, 'SELECT * INTO copy_of_orders FROM orders'],
    'select into a file' => [RawSqlGuard::READ, "SELECT * FROM orders INTO OUTFILE '/tmp/orders.csv'"],
    'mysql executable comment' => [RawSqlGuard::READ, '/*! DELETE FROM orders */'],
    'mysql executable comment after a select' => [RawSqlGuard::READ, 'SELECT 1 /*!50000 , (SELECT 1) */'],
    'optimizer hint' => [RawSqlGuard::READ, 'SELECT /*+ MAX_EXECUTION_TIME(1) */ * FROM orders'],
    'only a comment' => [RawSqlGuard::READ, '/* nothing */'],
    'unclosed comment' => [RawSqlGuard::READ, 'SELECT 1 /* DELETE'],
    'unclosed string' => [RawSqlGuard::READ, "SELECT 'abc"],
    'backslash in a string' => [RawSqlGuard::READ, "SELECT 'a\\b' AS x"],
    'semicolon in a bracket' => [RawSqlGuard::READ, 'SELECT [a;] DELETE'],
    'hash' => [RawSqlGuard::MARK_EXPORTED, "UPDATE #t SET a = 1\nDELETE FROM orders"],
    'dash comment without whitespace' => [RawSqlGuard::READ, 'SELECT 1 --x; DELETE FROM orders'],
    'quote in a dash comment without whitespace' => [RawSqlGuard::READ, "SELECT 1 --'\n; DELETE FROM orders; -- '"],
    'quote in a hash comment' => [RawSqlGuard::READ, "SELECT 1 #'\n; DELETE FROM orders; #'"],
    'quote after a nested comment' => [RawSqlGuard::READ, "SELECT 1 /* /* */ ' */ ; DELETE FROM orders; -- '"],
    'dollar quote' => [RawSqlGuard::READ, "SELECT $$'$$; DELETE FROM orders; -- '"],
    'tagged dollar quote' => [RawSqlGuard::READ, "SELECT \$x\$'\$x\$; DELETE FROM orders; -- '"],
    'quote in a bracketed name' => [RawSqlGuard::READ, "SELECT [a'] , ' ; DELETE FROM orders; -- '"],
    'quote in a backticked name' => [RawSqlGuard::READ, "SELECT `a'` , ' ; DELETE FROM orders; -- '"],
]);

it('allows an empty or whitespace-only value (nullable field)', function (): void {
    RawSqlGuard::assertStatementType('', RawSqlGuard::READ, 'test query');
    RawSqlGuard::assertStatementType('   ', RawSqlGuard::READ, 'test query');
})->throwsNoExceptions();
