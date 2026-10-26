<?php

namespace App\Services\PackSlips;

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Holds a run being sent to the print bridge, so the browser can fetch its print jobs
 * one request at a time instead of receiving every PDF in the request that started it.
 *
 * The run is stored server-side under a random key, for the user who started it. A job
 * request names only the key and a position, so the browser never chooses which
 * Shipments are drawn.
 */
class PackSlipPrintRuns
{
    /**
     * Long enough to send a run of the largest size a job at a time, short enough
     * that a forgotten tab cannot draw slips an hour later.
     */
    public const int LIFETIME_SECONDS = 3600;

    /**
     * Stage the run and describe its jobs for the browser: how many slips each holds,
     * and where to fetch it.
     *
     * @return list<array{count: int, url: string}>
     */
    public function stage(PackSlipRun $run, User $user): array
    {
        $key = Str::random(40);

        Cache::put($this->cacheKey($key), [
            'user' => $user->id,
            'shipmentIds' => $run->shipmentIds,
            'toteCodes' => $run->toteCodes,
            'title' => $run->title,
        ], self::LIFETIME_SECONDS);

        return array_map(
            fn (PackSlipRun $chunk, int $index): array => [
                'count' => $chunk->count(),
                'url' => route('pack-slips.print-jobs.show', ['key' => $key, 'index' => $index], absolute: false),
            ],
            $chunks = $run->chunk(PackSlipRenderer::SLIPS_PER_PRINT_JOB),
            array_keys($chunks),
        );
    }

    /**
     * The slips of one print job, or null when the run has expired, belongs to
     * another user, or has no such job.
     */
    public function job(string $key, int $index, User $user): ?PackSlipRun
    {
        $stored = Cache::get($this->cacheKey($key));

        if (! is_array($stored) || ($stored['user'] ?? null) !== $user->id) {
            return null;
        }

        $run = new PackSlipRun(
            array_values($stored['shipmentIds']),
            $stored['toteCodes'],
            $stored['title'],
        );

        return $run->chunk(PackSlipRenderer::SLIPS_PER_PRINT_JOB)[$index] ?? null;
    }

    private function cacheKey(string $key): string
    {
        return 'pack-slip-print-run:'.$key;
    }
}
