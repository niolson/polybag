<?php

namespace App\Http\Controllers;

use App\Models\PickBatch;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PickBatchController extends Controller
{
    public function summary(PickBatch $pickBatch): View
    {
        $rows = app(PickBatchService::class)->summaryRows($pickBatch);

        return view('pick-batches.summary', compact('pickBatch', 'rows'));
    }

    public function packSlips(Request $request, PickBatch $pickBatch, PickBatchService $pickBatches, PackSlipRenderer $renderer, SettingsService $settings): View
    {
        abort_unless($settings->packSlipsEnabled(), 403);

        return $renderer->view($pickBatches->packSlipRun($pickBatch), $request->user());
    }
}
