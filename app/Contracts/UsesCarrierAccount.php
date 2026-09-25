<?php

namespace App\Contracts;

use App\Models\CarrierAccount;

/**
 * A direct integration whose account is a {@see CarrierAccount}.
 *
 * Declared by the adapter, not inferred from the carrier, because not every
 * direct integration keeps its credentials there: Amazon Shipping's account
 * is a scoped Amazon connection (ADR-0006 decision 1). Only carriers whose
 * adapter declares this can have a carrier account.
 */
interface UsesCarrierAccount {}
