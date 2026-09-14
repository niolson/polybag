# ADR-0004: Record each purchased label as its own row; the Package carries the active one

## Status

Proposed — 2026-09-13. Revised under review 2026-09-13/14, then cut back on 2026-09-14 to
the domain decisions: an earlier draft had turned every edge the review found into a
first-class commitment, and the mechanics now live in the implementing issues. Would amend
ADR-0002: the carrier-of-record, service-evidence and postage-source columns on
`packages` gain a second home, and this document says which is authoritative.

## Context

A label purchase writes twenty-nine columns onto `packages` — tracking number, carrier of
record, service and its evidence, cost, the label document, print state, tracking state —
and a void nulls every one of them and returns the row to `Unshipped`
(`Package::clearShipping()`). That is deliberate: the Package row *is* the packing work,
and re-shipping after a void reuses the same Package, with its items and measurements.

What it costs is the history. After a void, PolyBag holds nothing about the label that
was bought: not the tracking number, not the cost, not whether it was printed. The audit
log was meant to keep this — `AuditLogListener::handlePackageCancelled()` records
`old_values` — but `clearShipping()` refreshes the model before dispatching the event, so
the listener records four nulls. Every operator void in every deployment to date has
left no lookupable trace on the Package or its audit record. (A Shopify-upstream void
keeps the tracking number in its own audit metadata, and a batch-shipped label keeps its
facts on `label_batch_items`; neither is a lookup.)

The operational consequence is concrete. A label is printed, stuck to a parcel, and voided
— wrong service, jammed printer, a customer changed the order. The parcel with the dead
label still exists. When it turns up, scanning its tracking number into PolyBag answers
nothing. Nor can a carrier invoice be reconciled: a voided label is billed and then
refunded, and PolyBag has no record that either should be expected.

Two facts shape the fix.

**The write side is four methods, once two forms stop being writers.** Every purchase
goes through `Package::markShipped()` and every void through `Package::clearShipping()`
(the Shopify fulfillment synchronizer voids through the same method). The third is
`Package::recordInferredService()`, which rewrites the service columns after the fact.
The fourth is `EloquentPackageLabelWorkflow::markLabelPrinted()`, which stamps
`label_printed_at` when the printer reports back. Two Filament forms also let a manager
edit `tracking_number`, `cost` and `status` by hand — already a data-integrity hole, and
one a label record makes visible — so they stop being writers in the first implementing
slice. Four development and test tools insert shipped packages directly; they are
fixtures, not paths, and are kept honest.

**The read side is everywhere.** Package tables, the Ship page, reports, exports, the
manifest, the print controller, service inference and the PII purge all read the columns
on `packages`.

## Decision

**1. A purchased label is a record of its own: `package_labels`.** One row per purchase,
written inside `markShipped()`'s transaction. It carries the *scalar* facts of the
purchase: tracking number, postage source and its pointer, carrier of record (free text,
per ADR-0002) and normalized carrier, service and its evidence, cost, label format, the
postage source's own identifier for the label, who bought it and when, and when it was
last printed. It is never deleted by a void.

**2. A void marks the row, it does not clear it.** `clearShipping()` sets `voided_at`,
who voided and why on the active row, then nulls the Package columns exactly as it does
today. The Package returns to `Unshipped`; the label remains, voided.

**3. The `packages` columns are the projection of the active label, and the projection
is permanent.** A shipped Package's scalar columns are a copy of its one unvoided label.
This is not a migration in progress — no reader is expected to move to `package_labels`,
now or later. The projection exists because every table, report and export wants it and
a join on a one-to-one buys nothing. The label row is authoritative when the two
disagree. A Package that is not shipped has no unvoided label, and that is all the
invariant says about it: fixtures and tests set projected values on unshipped packages
freely, and forbidding that would buy nothing for the history.

**4. At most one active label per Package is database-enforced. `Shipped` ⇔ exactly one
active label is an application transition invariant.** The two are different strengths
and the ADR says so rather than letting the stronger sentence borrow the weaker one's
guarantee.

| Guarantee | Mechanism | Strength |
|---|---|---|
| No Package has two unvoided labels | A generated column that is `package_id` while unvoided and NULL once voided, with a unique index | Database. Holds against any writer, including one that does not exist yet |
| `Shipped` ⇔ exactly one unvoided label | Each of the four writing methods leaves the pair consistent inside its own transaction and asserts so before commit | Application, per transition. Holds so long as every writer is one of the four |
| A shipped Package's projection equals its label's | An integrity command that names every disagreement, run on a schedule | Detection, not prevention |

A stronger form — an `active_label_id` on `packages` with a CHECK against status — was
rejected: it is a circular foreign key that complicates every delete, backfill and fresh
install, and it still cannot check that the label it points at is unvoided.

**5. Voids that predate this record are gone.** The backfill creates one label row for
every currently-shipped Package from its columns. Labels voided before the backfill
cannot be recovered — the audit rows that should name them hold nulls — and no `legacy`
state is invented to say so. A Package with no label rows and a void in its audit trail
is what a pre-history void looks like.

**6. The label documents are not part of the record.** `label_data` and
`customs_form_data` stay on `packages` and are nulled on void as today. The motivating
workflow needs the tracking number, cost, carrier, service, void state and whether the
label printed — not the document bytes. Keeping the documents out of `package_labels`
means the row duplicates none of the document or address payload governed by the
current PII purge, so the purge has no new table to visit and the two largest columns
on `packages` are not duplicated per purchase. Document history is a separate product
question, to be reopened only on a concrete operational need — reprinting a voided
label is not one; a voided label must not be reprinted.

**7. Labels follow their Package.** A Package deleted through any path — the delete
actions, the failure cleanups after a failed purchase, `shipments:archive` — takes its
label rows with it by cascade, and the archive's CSV export does not gain a labels file.
This is a product decision, not a mechanical one: the alternative — labels survive
ordinary deletion and only the archive may remove them, with guards on every delete path
— is stronger history at the cost of four guards, an export and a cleanup-path rule, and
nothing today asks for it. The delete actions refuse a *shipped* Package and tell the
user to void first (the relation manager's bulk delete does not, and the first
implementing slice gives it the same guard); after a void the delete succeeds and the
history goes with the row, and that is accepted. The failure cleanups cannot reach a
Package with history: Pack and the batch job opt out of cleanup, and Manual Ship cleans
up only the Package it just created. Revisiting this means adding guards, not changing
the schema.

### Terminology

**Label** — one purchased instance of postage for a Package: its tracking number, cost,
and the carrier of record it was bought as. A Package has at most one active Label; a
voided Label stays as history. *Avoid*: shipping label (ambiguous with the Shopify
product), label data (the document, which lives on the Package).

Added to `CONTEXT.md` in the implementing slice.

## Options considered

**Fix the audit capture only.** Necessary — it is the only history any void gets until
the label table lands, and it ships first — but not sufficient. A JSON column searched
with `LIKE` is not a lookup, the audit log is pruned on a different schedule than label
forensics want, and it cannot answer "was this printed" or "what was refunded."

**A void-only record.** Write a row only on void. Smaller, and it gets the tracking
lookup. Rejected because label facts would then live in two shapes — the active one on
`packages`, the dead ones in a table with different columns. The full record costs one
more `INSERT` in a method that is already the choke point.

**Never reuse a Package: a void spawns a new one.** The cleanest history and the most
expensive: `PackageItem`, exports, pick batches, drafts and
`Shipment::updateShippedStatus()` all key off Package identity, and the common void is
a reprint-after-a-jam that should not redo the packing. Rejected.

**Move every reader to `package_labels`.** Rejected as a goal — see decision 3. A plan
that leaves "finish the migration" as an open item invites someone to break fourteen
files for no gain.

**Copy the documents onto the label row and later move them.** An earlier draft's
decision 6. It duplicated document PII — so the purge had to learn the table in the
same release — and moving the documents off `packages` afterwards was three releases
of reader, writer and drop. Rejected as work no current need justifies.

**Labels survive deletion; only the archive removes them.** An earlier draft's decision
5a. See decision 7 for why the weaker policy was chosen.

## Trade-off

Two copies of the same scalar facts, kept consistent by construction rather than by a
join. The write side is narrow enough that "by construction" is credible: four methods,
each transactional, each building both writes from one column list. What is given up is
the ability to say the label row is *the* source of truth in the sense that nothing else
holds the data; it is the source of truth in the sense that it wins a disagreement.

The `->shipped()` factory state sets Package columns directly and is used in over forty
test files. It must create the matching label row, or the invariant is false throughout
the suite. This is the largest single cost of the change and it is a fixture, not a
feature.

## Consequences

- A tracking number scanned off a voided label resolves to its Package through an
  indexed column.
- A carrier invoice line for a voided label has a row to reconcile against, with the
  cost and the void time. `shopify-shipping-carrier/05` gains a place to stand.
- `markShipped()` gains one insert inside its existing transaction. `clearShipping()`
  gains one update inside its. `recordInferredService()` and `markLabelPrinted()` gain a
  transaction each and a second write on the active label. None gains a caller.
- A migration that backfills the table while a previous image's queue worker is still
  shipping leaves a shipped Package with no label row. That is a deployment ordering
  question — stop the workers before migrating — not a runtime one; the integrity
  command reports anything that slipped through and can repair that one case on request.
  New queue and scheduler containers wait for the app healthcheck, and therefore the
  migration, before starting.
- The user references on a label null on user deletion, as the package's and the audit
  log's do. The history keeps the time and loses the name.
- Both engines enforce the unique index on the generated column, but MySQL is the one
  production relies on and the suite runs on SQLite, so the migration and the constraint
  need to be exercised against MySQL once, in CI.
- Global search on packages searches `packages` columns only; finding a voided label's
  tracking number means a custom results query, not an added attribute.

## Implementation

Tracked as issues under `docs/issues/package-label-history/`, sliced so that the audit
fix ships alone and first, the table and invariant ship with no reader changes, and the
user-facing history follows. This document records the decision and why; it is not a
checklist and does not get updated as work lands.
