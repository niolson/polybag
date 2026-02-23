<?php

use App\Http\Controllers\QzSignController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/up', function () {
    $checks = [];

    try {
        DB::connection()->getPdo();
        $checks['db'] = 'ok';
    } catch (\Throwable $e) {
        $checks['db'] = 'failed: '.$e->getMessage();
    }

    try {
        Redis::ping();
        $checks['redis'] = 'ok';
    } catch (\Throwable $e) {
        $checks['redis'] = 'failed: '.$e->getMessage();
    }

    $healthy = $checks['db'] === 'ok' && $checks['redis'] === 'ok';

    return response()->json($checks, $healthy ? 200 : 503);
});

Route::post('/qz/sign', [QzSignController::class, 'sign'])->name('qz.sign')->middleware(['auth', 'throttle:60,1']);
