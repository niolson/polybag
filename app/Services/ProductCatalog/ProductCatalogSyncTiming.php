<?php

namespace App\Services\ProductCatalog;

/**
 * How long a product catalog sync's pieces may take (`product-catalog-sync/02`).
 *
 * A sync runs as a chain of short jobs on the `imports` queue, which on-prem
 * has one worker. Each job writes pages for about a minute, then queues the
 * next job at the back of the queue, so an order import queued meanwhile runs
 * before the sync carries on. A minute is a few thousand variants on Shopify
 * (250 to a page) while keeping an order import's wait short when a sale
 * floods the queue.
 *
 * None of these keeps two syncs apart: the run does that, by its ID, under a
 * lock on the connection's row ({@see ProductCatalogSyncRuns}). So the limits
 * on waiting are generous: too short fails a healthy run, too long only delays
 * replacing one whose job was truly lost.
 */
final class ProductCatalogSyncTiming
{
    /** After this long, a job takes no further page and hands on to the next. */
    public const SLICE_SECONDS = 60;

    /**
     * A job's own timeout: the slice, plus a page started just before the
     * slice ran out that is throttled every time Shopify allows (nine waits
     * of up to 30 seconds, 270 in all), plus 90 seconds for the requests
     * themselves. Under the `imports` connection's `retry_after` of 1,860.
     */
    public const JOB_TIMEOUT = self::SLICE_SECONDS + 270 + 90;

    /** How long a job's overlap lock outlives a worker killed mid-slice. */
    public const LOCK_SECONDS = self::JOB_TIMEOUT + 60;

    /**
     * How long a running sync may go without saving a page before it counts
     * as interrupted, and how long a slice may wait to run before it gives
     * up and fails its run: six hours.
     *
     * The scheduler queues an import for every importing connection on every
     * tick, each allowed 30 minutes, so in a sale the next slice can sit
     * behind several of them; six hours covers a dozen connections each
     * running its full half hour ahead of it. A worker killed mid-slice is
     * not what this waits for: the queue hands its job out again after
     * `retry_after`. This is for a job lost outright, which is rare, and a
     * new sync then resumes from the lost run's cursor.
     */
    public const STALE_AFTER_SECONDS = 6 * 3600;
}
