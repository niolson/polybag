<?php

namespace Tests\Fixtures\Queue;

use Illuminate\Support\Facades\DB;

/**
 * Runs the process out of memory, a PHP fatal error rather than an exception,
 * for tests that need a queue worker to die the way an oversized import does.
 */
final class ExhaustsMemory
{
    /** The cache key written inside the transaction, which a rollback must undo. */
    public const string WRITTEN_INSIDE_TRANSACTION = 'written-inside-a-transaction-that-ran-out-of-memory';

    public static function now(): never
    {
        $chunks = [];

        while (true) {
            $chunks[] = str_repeat('x', 1024 * 1024);
        }
    }

    public static function insideTransaction(): never
    {
        DB::transaction(function (): never {
            DB::table('cache')->insert([
                'key' => self::WRITTEN_INSIDE_TRANSACTION,
                'value' => '1',
                'expiration' => now()->addHour()->getTimestamp(),
            ]);

            self::now();
        });
    }
}
