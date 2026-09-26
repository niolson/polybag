<?php

namespace App\Contracts;

use App\Models\CarrierAccountScope;
use App\Models\DataSource;

/**
 * A direct integration whose account is a connection ({@see DataSource}),
 * chosen by a {@see CarrierAccountScope} row that targets it.
 *
 * The sibling of {@see UsesCarrierAccount}. The sale is direct — one carrier,
 * priced and known before purchase — but the credentials are a connection's,
 * so the Carrier Account form sends the carrier to Connections and
 * `CarrierAccount` refuses it (ADR-0006 options M and N,
 * `carrier-catalog-reset/15`).
 */
interface UsesConnectionAccount {}
