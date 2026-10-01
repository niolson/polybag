# An error after the label is recorded reports a shipped Package as failed

Status: needs-triage

Repo: `polybag`

Severity: low — no money is lost; the label goes unprinted and batch totals are wrong.
Verified: plausible (read, not tested).

## Problem

`markShipped()` commits, then runs `recordAppliedSpecialServices()`,
`Shipment::updateShippedStatus()` and dispatches `PackageShipped`. `AuditLogListener`
handles it in the same request: it isn't queued, and its `$afterCommit = true` only
waits for the commit that has already happened. An
exception from any of those propagates out of `markShipped()` into `buyPostage()`'s
catch blocks. The Package is `Shipped` with its Label, but the caller gets a failure:

- **Ship page:** "Shipping Error" and no print. A second click is refused with "This
  package already has a label… Reprint it", so the operator recovers, but only after
  being told the purchase failed.
- **Batch:** `GenerateLabelJob` marks the item `Failed` and increments
  `failed_shipments`. The Package is shipped, so `handleFailure()` doesn't delete it, but
  the batch's success count and `total_cost` leave the label out, and the batch can
  finish as `Failed` with every label bought.

A related, smaller point: `catch (\RuntimeException $e)` returns
`stateConflict($e->getMessage())` (`EloquentPackageShippingWorkflow.php:532`), and
Laravel's `QueryException` is a `RuntimeException`. A database error inside the
purchase is shown to the packer as raw SQL under "Package State Changed", and batch
items store it as `error_message`.

## What to build

- Make the post-commit work in `markShipped()` best-effort for the purchase's result:
  catch and log around it, or move the listeners to `afterCommit` queued handlers, so a
  committed Label is always reported as shipped.
- Narrow the `RuntimeException` catch to the optimistic-lock failure it is meant for
  (a dedicated exception type), and give database errors the generic message.

## Comments

- 2026-10-01 — Partly overtaken by `fb40b40` (`03`). A database error before the sale is
  now caught as `QueryException` and given the generic message, not shown as raw SQL under
  "Package State Changed". The post-commit half has changed shape: `markShipped()` now runs
  inside `recordBoughtLabel()`'s transaction, so its own commit is a savepoint.
  `updateShippedStatus()` and `recordAppliedSpecialServices()` throwing now rolls the
  whole recording back, and the next attempt recovers the label, which is safe. An
  `afterCommit` listener on `PackageShipped` now runs on the outer commit, inside
  `DB::transaction()`, so if it throws, the label is saved but the packer is told
  "label not recorded". What is left is making that listener step best-effort.
