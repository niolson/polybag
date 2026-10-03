<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Auth\AzureController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\SsoCallbackController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\LabelPrintController;
use App\Http\Controllers\OAuthCallbackController;
use App\Http\Controllers\PackSlipPrintController;
use App\Http\Controllers\PickBatchController;
use App\Http\Controllers\QzProvisionScriptController;
use App\Http\Controllers\QzSignController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/up', function () {
    // Liveness endpoint for container/web health checks.
    // Dependency-specific readiness checks belong on dedicated diagnostics routes.
    return response()->json(['status' => 'ok']);
});

// Readiness probe for external monitoring: 200 when this instance can reach
// MySQL (and Redis, where it is used), 503 when it cannot, so a monitor can act
// on the status code with no keyword matching. Keep it off /up and out of any
// container healthcheck — a healthcheck that fails on a datastore blip makes
// Docker restart the container, turning an outage into a restart loop.
//
// The response body names which dependency is unreachable, so restrict this
// path to your monitoring at the reverse proxy. See HealthController.
//
// The session middleware is stripped — along with the CSRF middleware, which
// needs a session to issue its cookie — so a Redis outage surfaces as this
// route's own 503 rather than a 500 from the session store. (With
// CACHE_STORE=redis the throttle middleware can still 500 first on a Redis
// outage; that is still non-2xx, and MySQL — the case nothing else catches —
// always reaches the probe.)
//
// Throttled at the same rate as the other unauthenticated routes: generous for a
// monitor polling once a minute, and it caps how many workers a stranger can tie
// up for the probe's timeout while a datastore is unreachable.
Route::get('/api/health', HealthController::class)
    ->middleware('throttle:20,1')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ]);

Route::post('/qz/sign', [QzSignController::class, 'sign'])->name('qz.sign')->middleware(['auth', 'throttle:60,1']);

Route::get('/qz/provision-script/{platform}', [QzProvisionScriptController::class, 'download'])
    ->name('qz.provision-script')
    ->middleware('auth');

// Acknowledgment from the QZ Tray integration that a label reached a printer.
// Batch printing fires one of these per label, so the rate cap is generous.
Route::post('/labels/{package}/printed', [LabelPrintController::class, 'store'])
    ->name('labels.printed')
    ->middleware(['auth', 'throttle:300,1']);

// Redeems a pack slip receipt: from the QZ Tray integration once a job is sent,
// or from Mark as printed on a pack slip view. One request per print job.
Route::post('/pack-slips/printed', [PackSlipPrintController::class, 'store'])
    ->name('pack-slips.printed')
    ->middleware(['auth', 'throttle:120,1']);

Route::middleware(['auth', 'manager'])->group(function (): void {
    Route::get('/pick-batches/{pickBatch}/summary', [PickBatchController::class, 'summary'])
        ->name('pick-batches.summary');
    Route::get('/pick-batches/{pickBatch}/pack-slips', [PickBatchController::class, 'packSlips'])
        ->name('pick-batches.pack-slips');
});

Route::get('/oauth/{provider}/receive', [OAuthCallbackController::class, 'receive'])
    ->name('oauth.receive')
    ->middleware(['auth', 'admin', 'throttle:20,1']);

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

Route::get('/auth/azure/redirect', [AzureController::class, 'redirect'])->name('auth.azure.redirect');
Route::get('/auth/azure/callback', [AzureController::class, 'callback'])->name('auth.azure.callback');

// Throttled: unauthenticated, and every hit proxies an outbound call to the
// external OAuth broker — cap the rate at which it can be driven. See issue 14.
Route::get('/auth/sso/{provider}/receive', [SsoCallbackController::class, 'receive'])
    ->name('auth.sso.receive')
    ->middleware('throttle:20,1');

// Collects browser CSP violation reports for local policy tuning. Enabled only
// in local/testing; see App\Http\Middleware\ContentSecurityPolicy.
if (app()->environment(['local', 'testing'])) {
    Route::post('/csp-report', CspReportController::class)
        ->name('csp.report')
        ->withoutMiddleware([PreventRequestForgery::class]);
}
