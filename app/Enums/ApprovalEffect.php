<?php

namespace App\Enums;

/**
 * What a `service_approvals` row does to the services it matches.
 *
 * An `Allow` row is an approval; a `Deny` row is an exception carved out of
 * one. A service is approved when at least one allow matches it and no deny
 * does — a deny always wins, whatever else is on file. Most-specific-wins was
 * considered and rejected (ADR-0003 decision 3): it makes a row's effect
 * depend on which other rows exist.
 */
enum ApprovalEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
