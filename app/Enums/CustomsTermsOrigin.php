<?php

namespace App\Enums;

/**
 * Where a resolved duties term or seller tax registration came from
 * (ADR-0008 decision 6).
 */
enum CustomsTermsOrigin: string
{
    /** The Shipment's own value, from its import or a manager's edit. */
    case Order = 'order';

    /** The client's duties policy or tax registration. */
    case Client = 'client';

    /** No choice was made and none is needed: a destination outside the EU ships DDU. */
    case Default = 'default';

    /** A postage source that takes no terms decides them itself (ADR-0008 decision 5). */
    case SourceDecided = 'source_decided';
}
