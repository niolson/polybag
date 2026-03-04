<?php

use App\Http\Controllers\QzSignController;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
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

Route::prefix('api')->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)->group(function () {
    Route::get('/health', function () {
        $status = ['status' => 'ok'];

        try {
            DB::connection()->getPdo();
            $status['db'] = 'ok';
        } catch (\Throwable $e) {
            $status['db'] = 'failed';
            $status['status'] = 'degraded';
        }

        $status['fake_carriers'] = config('app.fake_carriers');

        return response()->json($status, $status['status'] === 'ok' ? 200 : 503);
    });

    Route::post('/test/create-package', function () {
        abort_unless(config('app.fake_carriers'), 404);

        $shipment = Shipment::whereDoesntHave('packages', fn ($q) => $q->whereNotNull('shipped_at'))
            ->whereNotNull('postal_code')
            ->first();

        abort_unless($shipment, 422, 'No shippable shipment found');

        $boxSize = BoxSize::first();

        $package = Package::create([
            'shipment_id' => $shipment->id,
            'box_size_id' => $boxSize?->id,
            'weight' => 1.5,
            'length' => $boxSize?->length ?? 10,
            'width' => $boxSize?->width ?? 8,
            'height' => $boxSize?->height ?? 4,
        ]);

        return response()->json(['package_id' => $package->id]);
    });
});

Route::post('/qz/sign', [QzSignController::class, 'sign'])->name('qz.sign')->middleware(['auth', 'throttle:60,1']);
