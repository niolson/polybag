<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidPackSlipReceiptException;
use App\Services\PackSlips\PackSlipReceipts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackSlipPrintController extends Controller
{
    public function __construct(
        private readonly PackSlipReceipts $receipts,
    ) {}

    /**
     * Redeem the receipt of pack slips that were sent to the printer, or that a
     * user marked printed from the browser view.
     *
     * The receipt names exactly the Shipments and item versions the server drew,
     * for the user who drew them, so it is the only authority needed here.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receipt' => ['required', 'string', 'max:65535'],
        ]);

        try {
            $receipt = $this->receipts->open($validated['receipt'], $request->user());
        } catch (InvalidPackSlipReceiptException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $redemption = $this->receipts->redeem($receipt);

        return response()->json([
            'recorded' => $redemption->recorded,
            'skipped' => $redemption->skipped,
        ]);
    }
}
