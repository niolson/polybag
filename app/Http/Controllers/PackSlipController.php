<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Models\Shipment;
use App\Services\PackSlips\PackSlipRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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
}
