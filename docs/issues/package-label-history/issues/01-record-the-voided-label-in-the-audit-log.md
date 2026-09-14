# Record the voided label in the audit log

Status: done

Repo: `polybag`

## Problem

`AuditLogListener::handlePackageCancelled()` records `old_values` of tracking number,
carrier, service and cost from `$event->package`. `Package::clearShipping()` calls
`$this->refresh()` inside its transaction and dispatches `PackageCancelled` after it, and
the listener is `afterCommit`. By the time it reads the model, every value is null.
Verified with a throwaway test on 2026-09-12:

```
old_values => ["tracking_number" => null, "carrier" => null, "service" => null, "cost" => null]
```

So the audit trail preserves nothing about a voided label. No test asserts the values,
which is why it has been silent.

`ShopifyFulfillmentSynchronizer` gets this right on its own path — it captures the
tracking number into a local before calling `clearShipping()` and writes it into
`metadata`. The event path should do the same.

## What to build

Capture the values from the **database row, inside the transaction, under a row lock** —
not from `$this`. The model instance the caller holds may predate a print acknowledgement
(`label_printed_at` is written by a separate request when the printer reports back) or a
tracking refresh from the queue. `label_printed_at` is the field that matters most here
— it is what distinguishes a number in a database from a parcel with a dead label on it
— and it is exactly the one most likely to be stale on the instance.

- In `clearShipping()`, first statement of the transaction:
  `DB::table('packages')->where('id', $this->id)->lockForUpdate()->first()`. Capture
  `tracking_number`, `carrier`, `normalized_carrier_id`, `service`, `service_evidence`,
  `cost`, `postage_source`, `label_printed_at`, `shipped_at` and `ship_date` from that
  row. (`ship_date` is not nulled by the void today — `02` starts nulling it — so it is
  captured here for the same reason as the others.) Then the
  existing conditional `UPDATE`. The lock is on the package's own row and lasts for a
  transaction that already exists; `02` orders every writer's locks the same way — the
  package row first — and this is the first of them.
- `PackageCancelled` gains a `voidedLabel` payload (a DTO under
  `app/DataTransferObjects/` — match the convention there) built from that snapshot.
- `handlePackageCancelled()` records `old_values` from the payload and never reads the
  model.

Do not change the Shopify synchronizer's own `AuditLog::record()` call; it already works
and it records a different reason. It will also now get correct `old_values` via the
event, for free.

## Acceptance criteria

- [x] A void records the pre-void tracking number, carrier, service, cost and print
      state in `old_values` on the `PackageCancelled` audit row
- [x] A test asserts those values are non-null and equal to what the Package held before
      the void — the test that should have existed
- [x] A test sets `label_printed_at` on the database row *after* loading the model
      instance and before voiding, and asserts the audit row shows it printed — the
      staleness case
- [x] The Shopify-voided path still records its `reason` metadata and now also records
      non-null `old_values` via the event
- [x] `PackageCancelledTest` continues to pass unchanged

## Blocked by

None. Ships first, on its own, before ADR-0004 is accepted — it is the only history any
void gets until `02` lands.

## Comments

- **2026-09-14** — Done. `Package::clearShipping()` now opens its transaction with
  `SELECT … FOR UPDATE` on the package row and builds a `VoidedLabel` DTO
  (`app/DataTransferObjects/PackageLabels/VoidedLabel.php`) from that row before the
  conditional `UPDATE`; the DTO rides on `PackageCancelled` as `$voidedLabel` and
  `AuditLogListener::handlePackageCancelled()` writes `old_values` from
  `VoidedLabel::toArray()` — all ten fields named above, timestamps as ISO 8601, cost as
  a two-decimal string to match what the `decimal:2` cast on `Package` reports. The
  listener no longer reads the model. Tests: `tests/Feature/AuditPackageCancelledTest.php`
  (the values test and the stale-instance test) and one new case in
  `ShopifyFulfillmentSynchronizerTest` asserting the event row carries the label and the
  synchronizer's reason row still follows it. `PackageCancelledTest` is untouched.
