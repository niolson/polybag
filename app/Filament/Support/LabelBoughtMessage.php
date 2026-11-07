<?php

namespace App\Filament\Support;

use App\Models\Package;

/**
 * The body of the toast that says a Label was bought: the carrier's logo and the
 * service as the Packages list names it, with the cost, then the tracking number.
 *
 * Read off the shipped Package rather than the purchase response, so the toast
 * calls the service what the rest of the app does. A blind Shopify purchase
 * reports no service at purchase, and the toast says so; its cost, unknown
 * until recovered later, is left out.
 */
class LabelBoughtMessage
{
    public static function for(Package $package): string
    {
        $package = $package->fresh(['activeLabel.carrierService']) ?? $package;

        $carrier = filled($package->carrier)
            ? CarrierLogoColumn::html($package->carrier, 'h-6 max-w-[4rem]')
            : '';
        $service = $package->serviceDisplayName() ?? 'Service not reported';

        $cost = $package->cost !== null ? ' · $'.number_format((float) $package->cost, 2) : '';

        return '<span class="flex items-center gap-2">'.$carrier.'<span>'.e($service).$cost.'</span></span>'
            .(filled($package->tracking_number) ? '<span class="block mt-1">Tracking '.e($package->tracking_number).'</span>' : '');
    }
}
