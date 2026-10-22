<?php

namespace App\Enums;

use App\Services\StoreWriteGuard;

/**
 * Whether PolyBag may write to a connection's store in the current mode
 * (`live-store-demo`), as {@see StoreWriteGuard} answers it.
 *
 * `Unknown` is a Shopify store whose plan could not be read, so whether it is
 * a development store is not known. It never permits a write. A caller that
 * can ask again later, such as an export, tells it apart from `Refused`, so a
 * passing Shopify failure does not become a permanent refusal.
 */
enum StoreWritePermission
{
    case Allowed;
    case Refused;
    case Unknown;
}
