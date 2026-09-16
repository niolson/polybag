<?php

namespace App\Exceptions\Carriers;

/**
 * A rate's metadata names a product the adapter cannot place in any packaging.
 *
 * Thrown by an adapter's packaging classifier instead of defaulting the rate
 * to the shipper's own packaging — ADR-0005 decision 3's invariant that an
 * indicator the classifier does not recognise must not fall through. At rate
 * shopping the adapter's own filter keeps this from happening; at purchase the
 * metadata is browser-restated, and the workflow turns this into a refusal.
 */
class UnclassifiablePackagingException extends CarrierException {}
