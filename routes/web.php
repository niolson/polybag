<?php

use App\Http\Controllers\OAuthCallbackController;
use App\Http\Controllers\QzSignController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/up', function () {
    $checks = [];

    try {
        DB::connection()->getPdo();
        $checks['db'] = 'ok';
    } catch (Throwable) {
        $checks['db'] = 'failed';
    }

    try {
        Redis::ping();
        $checks['redis'] = 'ok';
    } catch (Throwable) {
        $checks['redis'] = 'failed';
    }

    $healthy = $checks['db'] === 'ok' && $checks['redis'] === 'ok';

    return response()->json($checks, $healthy ? 200 : 503);
});

Route::post('/qz/sign', [QzSignController::class, 'sign'])->name('qz.sign')->middleware(['auth', 'throttle:60,1']);

Route::get('/oauth/{provider}/callback', [OAuthCallbackController::class, 'callback'])
    ->name('oauth.callback')
    ->middleware('auth');
