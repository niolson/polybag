<?php

namespace App\Http\Controllers;

use App\Models\PickBatch;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\PickBatchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PickBatchController extends Controller
{
    public function summary(PickBatch $pickBatch): View
    {
        $rows = app(PickBatchService::class)->summaryRows($pickBatch);

        return view('pick-batches.summary', compact('pickBatch', 'rows'));
    }

    public function packSlips(Request $request, PickBatch $pickBatch, PickBatchService $pickBatches, PackSlipRenderer $renderer): View
    {
        return $renderer->view($pickBatches->packSlipRun($pickBatch), $request->user());
    }
}
