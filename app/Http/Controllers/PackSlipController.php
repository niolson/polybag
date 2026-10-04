<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Models\Shipment;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\PackSlips\PackSlipViews;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PackSlipController extends Controller
{
    /**
     * One Shipment's pack slip in the browser, with Mark as printed.
     */
    public function shipment(Request $request, Shipment $shipment, PackSlipRenderer $renderer): View
    {
        abort_unless($request->user()->can('printPackSlip', $shipment), 403);

        return $renderer->view(PackSlipRun::forShipment($shipment->id), $request->user());
    }

    /**
     * Pack slips chosen on Print Pack Slips, in run order, with Mark as printed.
     */
    public function run(Request $request, string $key, PackSlipViews $views, PackSlipRenderer $renderer): View|Response
    {
        $run = $views->get($key);

        if ($run === null) {
            return response()->view('pack-slips.expired', status: 410);
        }

        return $renderer->view($run, $request->user());
    }
}
