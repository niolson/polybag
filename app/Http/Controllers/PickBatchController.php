<?php

namespace App\Http\Controllers;

use App\Models\PickBatch;
use Illuminate\View\View;

class PickBatchController extends Controller
{
    public function summary(PickBatch $pickBatch): View
    {
        $pickBatch->load('pickBatchShipments.shipment.shipmentItems.product');

        // Aggregate quantity per product across all shipments in the batch
        $rows = collect();

        foreach ($pickBatch->pickBatchShipments as $pivot) {
            foreach ($pivot->shipment->shipmentItems as $item) {
                $key = $item->product_id;

                if ($rows->has($key)) {
                    $row = $rows->get($key);
                    $row['quantity'] += $item->quantity;
                    $row['tote_codes'][] = $pivot->tote_code;
                    $rows->put($key, $row);
                } else {
                    $rows->put($key, [
                        'bin_location' => $item->product?->bin_location,
                        'sku' => $item->product?->sku ?? '—',
                        'product_name' => $item->product?->name ?? '—',
                        'quantity' => $item->quantity,
                        'tote_codes' => [$pivot->tote_code],
                    ]);
                }
            }
        }

        // Deduplicate tote codes per row
        $rows = $rows->map(function (array $row) {
            $row['tote_codes'] = array_values(array_unique(array_filter($row['tote_codes'])));
            natsort($row['tote_codes']);
            $row['tote_codes'] = array_values($row['tote_codes']);

            return $row;
        });

        // Sort: located items first (natural sort on bin_location), then unlocated by product name
        $located = $rows->filter(fn ($r) => filled($r['bin_location']))->values();
        $unlocated = $rows->filter(fn ($r) => blank($r['bin_location']))->values();

        $locatedArray = $located->toArray();
        usort($locatedArray, fn ($a, $b) => strnatcasecmp($a['bin_location'], $b['bin_location']));

        $unlocatedArray = $unlocated->toArray();
        usort($unlocatedArray, fn ($a, $b) => strcasecmp($a['product_name'], $b['product_name']));

        $rows = array_merge($locatedArray, $unlocatedArray);

        return view('pick-batches.summary', compact('pickBatch', 'rows'));
    }

    public function packSlips(PickBatch $pickBatch): View
    {
        $pickBatch->load('pickBatchShipments.shipment.shipmentItems.product');

        $pivotRows = $pickBatch->pickBatchShipments->sortBy('tote_code');

        return view('pick-batches.pack-slips', compact('pickBatch', 'pivotRows'));
    }
}
