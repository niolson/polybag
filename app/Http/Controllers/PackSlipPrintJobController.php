<?php

namespace App\Http\Controllers;

use App\Services\GotenbergService;
use App\Services\PackSlips\PackSlipPrintRuns;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PackSlipPrintJobController extends Controller
{
    /**
     * Draw one print job of a staged run: its PDF and the receipt the browser redeems
     * once the print bridge reports the job sent. Drawing records nothing as printed.
     */
    public function show(Request $request, string $key, int $index, PackSlipPrintRuns $runs, PackSlipRenderer $renderer, SettingsService $settings): JsonResponse
    {
        abort_unless($settings->packSlipsEnabled(), 403);

        $job = $runs->job($key, $index, $request->user());

        if ($job === null) {
            return response()->json(['error' => 'This print run has expired. Print the pack slips again.'], 410);
        }

        try {
            return response()->json($renderer->printJob($job, $request->user())->toBrowserPayload());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => GotenbergService::UNAVAILABLE], 503);
        }
    }
}
