# A void the source accepted can fail to record, and can't be retried

Status: needs-triage

Repo: `polybag`

Severity: low. The trigger is a database failure between two steps, but the result is a
Package stuck `Shipped` on a dead Label, with no way out in the UI.
Verified: plausible (read, not tested).

## Problem

`EloquentPackageLabelWorkflow::voidLabel()` asks the postage source to void, then calls
`clearShipping()`. The two steps aren't linked. If `clearShipping()` throws for any reason
other than the "already voided" race (a deadlock, a lost connection, the
`LogicException` for a broken Label invariant), the generic `catch (\Exception)` reports
"Cancel Error". The Label is then:

- voided and refunded at the carrier, or cancelled at Amazon;
- still active in PolyBag, with the Package `Shipped`, on the next End of Day list, and
  exportable.

The operator's retry asks the source again, and the source now refuses: USPS, UPS and
FedEx don't void a label twice. On Amazon it is worse, because
`AmazonPostageSource::voidLabel()` has already removed the shipment ID from the Package's
metadata before returning, so the retry fails with "records no Amazon shipment". Nothing
gets the Package back to `Unshipped`. `app:verify-label-integrity` can't detect it: the
Package and its Label agree with each other, and both are wrong.

`ShopifyFulfillmentSynchronizer::applyVoid()` also saves before it clears, but it
recovers: the next poll finds the fulfillment by tracking number, not by the identifiers
it removed, and tries the void again.

This is area A's `03` for the void: the source has acted and PolyBag hasn't recorded it.

## What to build

- When the source confirmed the void and the local clear failed, say so, not
  "Cancel Error": the label is void at the carrier and the Package needs to be
  un-shipped, with a way to do it. Either retry `clearShipping()` without asking the
  source again, or let a manager mark a Label voided with a reason and no source call.
  The second also covers labels voided directly on the carrier's own site.
- Move the metadata change in `AmazonPostageSource::voidLabel()` into
  `clearShipping()`'s transaction, for example as a list of metadata keys to drop, so it
  commits or rolls back with the void. Do the same in `applyVoid()` for consistency.

## Comments
