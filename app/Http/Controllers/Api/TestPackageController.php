<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use Illuminate\Http\JsonResponse;

class TestPackageController extends Controller
{
    public function __invoke(): JsonResponse
    {
        abort_unless(config('app.fake_carriers'), 404);

        $shipment = Shipment::whereDoesntHave('packages', fn ($q) => $q->whereNotNull('shipped_at'))
            ->whereNotNull('postal_code')
            ->first();

        if (! $shipment) {
            $shippingMethod = ShippingMethod::query()
                ->whereHas('carrierServices')
                ->first();

            abort_unless($shippingMethod, 422, 'No shippable shipment found');

            $shipment = Shipment::factory()->validated()->create([
                'shipping_method_id' => $shippingMethod->id,
                'address1' => '10 Main St',
                'city' => 'Memphis',
                'state_or_province' => 'TN',
                'postal_code' => '38116',
                'country' => 'US',
            ]);
        }

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
    }
}
