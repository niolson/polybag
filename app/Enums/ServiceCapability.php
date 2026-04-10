<?php

namespace App\Enums;

enum ServiceCapability
{
    /**
     * The adapter translates this service into carrier-specific API fields.
     */
    case Supported;

    /**
     * The carrier explicitly prohibits this service (policy or legal restriction).
     * Carriers with any Prohibited service will be excluded from rate results,
     * and a reason will be shown to the user.
     */
    case Prohibited;

    /**
     * The carrier may support this service but it hasn't been wired up yet.
     * The request proceeds without the service — no warning shown.
     */
    case NotImplemented;
}
