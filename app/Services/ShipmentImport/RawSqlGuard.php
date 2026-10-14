<?php

namespace App\Services\ShipmentImport;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Constrains admin-authored raw SQL (the DataSource query fields) to a single
 * statement of an allowed type, so a field meant to SELECT/UPDATE/INSERT cannot
 * be turned into a DELETE/DROP/TRUNCATE/ALTER/etc. These queries run
 * automatically on a schedule against a configured — possibly a customer's
 * production — database, so the leading statement type is the meaningful trust
 * boundary. See security review issue 07.
 *
 * Reads are limited to plain SELECT (not WITH: a CTE can lead a DELETE/UPDATE on
 * MySQL 8 / Postgres, which would defeat the restriction).
 *
 * This is a guard against mistakes and casual misuse, not a sandbox: a SELECT can
 * still call a function with side effects. The database user's own grants are
 * the real limit (docs/data-sources/database.md).
 */
class RawSqlGuard
{
    /** @var list<string> Allowed leading keyword for read (fetch) queries. */
    public const READ = ['SELECT'];

    /** @var list<string> Allowed leading keyword for the mark-exported write. */
    public const MARK_EXPORTED = ['UPDATE', 'INSERT'];

    /** @var list<string> Allowed leading keyword for the export write. */
    public const EXPORT = ['INSERT', 'UPDATE'];

    /**
     * @param  list<string>  $allowedKeywords  Upper-case leading keywords.
     *
     * @throws InvalidArgumentException
     */
    public static function assertStatementType(string $sql, array $allowedKeywords, string $fieldLabel): void
    {
        $error = self::validate($sql, $allowedKeywords, $fieldLabel);

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
    }

    /**
     * A validation rule enforcing the same policy on a form field. Returned as a
     * ValidationRule object (not a bare closure) so Filament passes it straight to
     * the validator instead of trying to evaluate it as a dynamic-rule closure.
     *
     * @param  list<string>  $allowedKeywords
     */
    public static function rule(array $allowedKeywords, string $fieldLabel): ValidationRule
    {
        return new class($allowedKeywords, $fieldLabel) implements ValidationRule
        {
            /**
             * @param  list<string>  $allowedKeywords
             */
            public function __construct(
                private readonly array $allowedKeywords,
                private readonly string $fieldLabel,
            ) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! is_string($value)) {
                    return;
                }

                try {
                    RawSqlGuard::assertStatementType($value, $this->allowedKeywords, $this->fieldLabel);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }
        };
    }

    /**
     * Keywords that only ever begin a statement outside the three allowed types.
     * They are refused anywhere in a query, not only at the start: SQL Server
     * runs a batch of statements written one after another with no ";" between
     * them, so the leading keyword alone does not bound what runs.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYWORDS = [
        'ALTER', 'BACKUP', 'BEGIN', 'BULK', 'CHECKPOINT', 'COMMIT', 'CREATE', 'DBCC', 'DEALLOCATE',
        'DECLARE', 'DELETE', 'DENY', 'DROP', 'EXEC', 'EXECUTE', 'GOTO', 'GRANT', 'KILL', 'MERGE',
        'PRINT', 'RAISERROR', 'READTEXT', 'RECONFIGURE', 'RESTORE', 'REVERT', 'REVOKE', 'ROLLBACK',
        'SETUSER', 'SHUTDOWN', 'THROW', 'TRUNCATE', 'UPDATETEXT', 'USE', 'WAITFOR', 'WHILE', 'WRITETEXT',
    ];

    /**
     * @param  list<string>  $allowedKeywords
     */
    private static function validate(string $sql, array $allowedKeywords, string $fieldLabel): ?string
    {
        // Empty is allowed through — the query fields are nullable and their
        // presence is gated elsewhere (the importer only runs a query if set).
        if (trim($sql) === '') {
            return null;
        }

        $code = self::code($sql);

        if (! is_string($code)) {
            return "The {$fieldLabel} {$code['error']}";
        }

        if (trim($code) === '') {
            return "The {$fieldLabel} contains only comments.";
        }

        // Reject statement stacking: a single optional trailing semicolon is fine.
        if (str_contains(rtrim(rtrim($code), ';'), ';')) {
            return "The {$fieldLabel} must be a single SQL statement (remove the extra \";\").";
        }

        $keyword = self::leadingKeyword($code);
        $allowed = implode(' or ', $allowedKeywords);

        if ($keyword === null || ! in_array($keyword, $allowedKeywords, true)) {
            return "The {$fieldLabel} must be a single {$allowed} statement — other statement types (DELETE, DROP, TRUNCATE, ALTER, …) are not allowed.";
        }

        preg_match_all('/[A-Z0-9_@#$]+/', strtoupper($code), $matches);
        $words = $matches[0];

        $forbidden = array_values(array_intersect($words, self::FORBIDDEN_KEYWORDS));

        // A read never writes: no INSERT, no UPDATE other than a FOR UPDATE row
        // lock, and no SELECT … INTO a table or a file on the database server.
        if ($allowedKeywords === self::READ) {
            foreach ($words as $index => $word) {
                if ($word === 'INSERT' || $word === 'INTO' || ($word === 'UPDATE' && ($words[$index - 1] ?? null) !== 'FOR')) {
                    $forbidden[] = $word;
                }
            }
        }

        if ($forbidden !== []) {
            return "The {$fieldLabel} must be a single {$allowed} statement — it may not contain {$forbidden[0]}. Put a column or table with that name in quotes.";
        }

        return null;
    }

    private static function leadingKeyword(string $sql): ?string
    {
        if (preg_match('/^\s*([a-zA-Z]+)/', $sql, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * The query with string literals, quoted identifiers and comments blanked
     * out, leaving only what every supported database reads as SQL — or the
     * reason the query is refused.
     *
     * Only text all four drivers agree is not code is blanked: `--` comments
     * need the whitespace MySQL requires after them, and `#` is left as code
     * because it starts a temporary table name on SQL Server. Where the drivers
     * disagree on a literal's end (MySQL reads a backslash as an escape, the
     * others do not) the query is refused rather than guessed at. Bracketed
     * identifiers keep their ";" so a bracket cannot hide one.
     *
     * @return string|array{error: string}
     */
    private static function code(string $sql): string|array
    {
        $code = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === '/' && $next === '*') {
                $marker = $sql[$i + 2] ?? '';

                if ($marker === '!' || $marker === '+') {
                    return ['error' => 'may not contain a MySQL executable comment or optimizer hint ("/*!" or "/*+").'];
                }

                $end = strpos($sql, '*/', $i + 2);

                if ($end === false) {
                    return ['error' => 'has a comment that is never closed.'];
                }

                $code .= str_repeat(' ', $end + 2 - $i);
                $i = $end + 2;

                continue;
            }

            if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                $end = strcspn($sql, "\r\n", $i);
                $code .= str_repeat(' ', $end);
                $i += $end;

                continue;
            }

            $close = match ($char) {
                "'", '"', '`' => $char,
                '[' => ']',
                default => null,
            };

            if ($close === null) {
                $code .= $char;
                $i++;

                continue;
            }

            $j = $i + 1;

            while (true) {
                if ($j >= $length) {
                    return ['error' => 'has a quoted string or name that is never closed.'];
                }

                if ($sql[$j] === '\\' && $char !== '`' && $char !== '[') {
                    return ['error' => 'may not contain a backslash inside a quoted string.'];
                }

                if ($sql[$j] === $close) {
                    // A doubled closing character is an escaped one, inside the literal.
                    if (($sql[$j + 1] ?? '') === $close) {
                        $j += 2;

                        continue;
                    }

                    break;
                }

                $j++;
            }

            $literal = substr($sql, $i, $j + 1 - $i);
            $code .= $char === '['
                ? preg_replace('/[^;]/', ' ', $literal)
                : str_repeat(' ', strlen($literal));
            $i = $j + 1;
        }

        return $code;
    }
}
