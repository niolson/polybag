<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Saloon\Enums\PipeOrder;
use Saloon\Exceptions\StrayRequestException;
use Saloon\Http\PendingRequest;

abstract class TestCase extends BaseTestCase
{
    /** Whether the Saloon guard below has been added to this process's global middleware. */
    private static bool $saloonGuardInstalled = false;

    /** Whether the running test may build Saloon requests with no mock client. */
    private static bool $straySaloonRequestsAllowed = false;

    /** The first stray Saloon request the running test tried to send, if any. */
    private static ?string $straySaloonRequest = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$straySaloonRequestsAllowed = false;
        self::$straySaloonRequest = null;

        // `tests/External` exists to make live calls.
        if (str_contains(static::class, '\\Tests\\External\\')) {
            self::$straySaloonRequestsAllowed = true;

            return;
        }

        // No test may reach a live API. A request nobody faked, such as the
        // Shopify lookup every import now ends with (`order-reconciliation/06`),
        // fails instead of leaving the machine, through Laravel's HTTP client
        // or through Saloon.
        Http::preventStrayRequests();
        self::installSaloonGuard();
    }

    /**
     * Fail a test that tried to send a stray Saloon request, even when the
     * code under test caught the exception: an import records a failed
     * Shopify lookup as an error and completes, so the throw alone would
     * pass a test that never mocked the lookup.
     */
    protected function tearDown(): void
    {
        $stray = self::$straySaloonRequest;
        self::$straySaloonRequest = null;

        parent::tearDown();

        if ($stray !== null) {
            $this->fail("The test sent a Saloon request nothing mocked: {$stray}");
        }
    }

    /**
     * Let the running test build Saloon requests with no mock client: one that
     * drives a custom sender, which Saloon's own faking would bypass. Reset
     * before every test.
     */
    public static function allowStraySaloonRequests(): void
    {
        self::$straySaloonRequestsAllowed = true;
    }

    /**
     * Saloon's own `Config::preventStrayRequests()` adds a middleware on every
     * call, and its global middleware outlives each test, so it would pile up
     * one per test. This adds one guard per process and reads a flag reset
     * for every test instead.
     */
    private static function installSaloonGuard(): void
    {
        if (self::$saloonGuardInstalled) {
            return;
        }

        Config::globalMiddleware()->onRequest(static function (PendingRequest $pendingRequest): void {
            if (! self::$straySaloonRequestsAllowed && ! $pendingRequest->hasMockClient()) {
                self::$straySaloonRequest ??= $pendingRequest->getMethod()->value.' '.$pendingRequest->getUri();

                throw new StrayRequestException;
            }
        }, 'polybagPreventStrayRequests', PipeOrder::LAST);

        self::$saloonGuardInstalled = true;
    }
}
