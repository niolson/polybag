<?php

namespace App\Enums;

/**
 * Whether a postage source may sell beyond a shipping method's listed services
 * — ADR-0006 decision 5, `carrier-catalog-reset/09`.
 *
 * One column for every kind, read differently by each: for Shopify, `Any` is
 * offering `auto`, Shopify's own choice; for Amazon Buy Shipping it is *any
 * service* (`13`). Direct takes only `None`. Each kind declares what it
 * accepts ({@see PostageSourceKind::acceptedUnlistedServices()}), and the form
 * labels the choice per kind so nobody sees the shared name.
 */
enum UnlistedServices: string
{
    case None = 'none';
    case Any = 'any';
}
