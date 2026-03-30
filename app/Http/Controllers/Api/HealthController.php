<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $healthy = true;

        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $healthy = false;
        }

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'fake_carriers' => (bool) config('app.fake_carriers'),
        ], $healthy ? 200 : 503);
    }
}
