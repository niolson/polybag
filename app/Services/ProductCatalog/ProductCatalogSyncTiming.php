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
 */
final class ProductCatalogSyncTiming
{
    /** After this long, a job takes no further page and hands on to the next. */
    public const SLICE_SECONDS = 60;

    /**
     * A job's own timeout: the slice, plus the page in hand when the slice
     * ran out, which may wait out Shopify's throttling (up to 30 seconds a
     * wait). Well under the `imports` connection's `retry_after` of 1,860.
     */
    public const JOB_TIMEOUT = 300;

    /** How long a job's overlap lock outlives a worker killed mid-slice. */
    public const LOCK_SECONDS = self::JOB_TIMEOUT + 60;

    /**
     * How long a running sync may go without saving a page before it counts
     * as interrupted and a new sync may start: one slice's timeout, plus one
     * order import's (`RunDataSourceImportJob::$timeout`, 1,800) that the
     * next slice may be queued behind, plus a margin.
     */
    public const STALE_AFTER_SECONDS = self::JOB_TIMEOUT + 1800 + 60;
}
