<?php

namespace App\Services\PackageLabels;

use App\Models\Package;
use App\Models\PackageLabel;
use Illuminate\Support\Facades\Session;

/**
 * The Label this browser session last bought: what the REPRINTLAST and
 * VOIDLAST commands act on (ADR-0007, decision 4). Remembered by Label, not
 * Package, so that once it is voided and the Package bought again, the
 * commands refuse rather than act on the replacement.
 */
class SessionLastLabel
{
    private const string KEY = 'last_label_id';

    /**
     * Remember the Label just bought for a Package.
     */
    public function remember(Package $package): void
    {
        $labelId = $package->activeLabel()->value('id');

        $labelId === null ? Session::forget(self::KEY) : Session::put(self::KEY, $labelId);
    }

    /**
     * The Package whose active Label is the one last bought here, or why the
     * commands have nothing to act on.
     */
    public function package(): Package|string
    {
        $labelId = Session::get(self::KEY);
        $label = $labelId === null ? null : PackageLabel::with('package.shipment')->find($labelId);

        return match (true) {
            $label?->package === null => 'No label has been bought in this session.',
            $label->isVoided() => "The last label bought in this session ({$label->tracking_number}) has been voided.",
            default => $label->package,
        };
    }

    public function forget(): void
    {
        Session::forget(self::KEY);
    }
}
