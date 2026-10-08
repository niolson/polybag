<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\ShipRequest;

/**
 * A seller whose adapter puts {@see ShipRequest::$customsTerms} on the wire:
 * the duties term, the seller tax registration, the recipient tax ID and the
 * export filing.
 *
 * The shipping workflow snapshots what a label declared into
 * `package_labels.customs_terms` only for such an adapter. A snapshot for one
 * that sends nothing would record terms the carrier never saw. An adapter
 * adopts this when it sends all of them (`international-customs-terms/06`–`08`).
 */
interface SendsCustomsTerms {}
