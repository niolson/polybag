<?php

namespace App\Contracts;

use App\DataTransferObjects\PackageLabels\LabelReprintResult;
use App\DataTransferObjects\PackageLabels\LabelVoidResult;
use App\Models\Package;
use App\Models\User;

interface PackageLabelWorkflow
{
    /**
     * Void the active label through the postage source that sold it, if the user
     * may void any package's label.
     */
    public function voidLabel(Package $package, User $user): LabelVoidResult;

    /**
     * Void a label the user bought themselves, or any label for a manager — the
     * packing station's "void last label" command.
     */
    public function voidOwnLabel(Package $package, User $user): LabelVoidResult;

    /**
     * Record the active label as voided without asking the postage source, for
     * a manager: a void the source accepted that PolyBag failed to record, or
     * one made on the source's own site.
     */
    public function recordVoid(Package $package, User $user): LabelVoidResult;

    public function labelForReprint(Package $package, User $user): LabelReprintResult;

    /**
     * Record that a label physically reached a printer.
     *
     * @return bool Whether this was a reprint (the label had been printed before)
     */
    public function markLabelPrinted(Package $package, ?User $user = null): bool;
}
