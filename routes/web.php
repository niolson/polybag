<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\TestPackageController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\OAuthCallbackController;
use App\Http\Controllers\QzSignController;
use Illuminate\Support\Facades\Route;

Route::get('/up', function () {
    // Liveness endpoint for container/web health checks.
    // Dependency-specific readiness checks belong on dedicated diagnostics routes.
    return response()->json(['status' => 'ok']);
});

Route::post('/qz/sign', [QzSignController::class, 'sign'])->name('qz.sign')->middleware(['auth', 'throttle:60,1']);

Route::get('/oauth/{provider}/receive', [OAuthCallbackController::class, 'receive'])
    ->name('oauth.receive')
    ->middleware(['auth', 'admin']);

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

Route::prefix('api')->group(function () {
    if (app()->environment(['local', 'testing'])) {
        Route::get('/health', HealthController::class);
    }

    if (app()->environment(['local', 'testing'])) {
        Route::post('/test/create-package', TestPackageController::class);
    }
});
