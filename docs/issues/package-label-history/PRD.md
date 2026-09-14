# Package label history

Status: reference

Implements ADR-0004 (`docs/adr/0004-package-label-as-a-record.md`). Background for the
issues in this directory; not a work item.

## The problem in one scenario

A packer buys a label, prints it, sticks it on the parcel, then voids it — wrong service,
jammed printer, customer changed the order. The parcel with the dead label is still on
the bench. When it turns up later, scanning its tracking number into PolyBag finds
nothing: `Package::clearShipping()` nulled every shipping column, and the audit row that
was meant to preserve them recorded nulls, because the model is refreshed before the
event fires. See the ADR's Context for the verification.

The same gap means a voided label's cost has nowhere to be reconciled against when the
carrier bills and then refunds it.

## What changes

A `package_labels` table, one row per purchase, written inside `markShipped()` and marked
voided inside `clearShipping()`. It holds the scalar facts of the purchase — tracking
number, carrier, service, cost, who bought it, whether it printed, who voided it and why
— and not the label document, which stays on `packages` as today. The `packages`
columns stay as the projection of the active label, permanently, so no reader of them
changes. "At most one unvoided label per Package" is database-enforced by a
generated-column unique index; "`Shipped` ⇔ exactly one unvoided label" is asserted by
each of the four writers before commit; and an integrity command reports any shipped
package whose projection disagrees with its label. Labels are deleted with their
Package. ADR-0004 decisions 4, 6 and 7 say which is which and why.

## Sequence

| # | Slice | Status | Depends on |
|---|---|---|---|
| [`01`](issues/01-record-the-voided-label-in-the-audit-log.md) | Fix the audit capture so a void records what it voided, from the locked row | ready-for-agent | nothing — ships first, on its own |
| [`02`](issues/02-package-labels-table-and-the-one-active-label-invariant.md) | Table, model, factory, backfill, the four write paths, void reason and user, `source_label_reference`, invariant, integrity command, the two Filament forms that could edit the projection, fixtures, MySQL CI job. **No reader changes** | ready-for-agent | ADR accepted, `01` |
| [`03`](issues/03-print-history-on-the-label.md) | Print count, first print, and acknowledgements that arrive after a void | needs-triage | `02` |
| [`04`](issues/04-void-provenance.md) | What each carrier says back on void | ready-for-human | access to each source's sandbox or live account |
| [`05`](issues/05-label-history-on-the-package-and-tracking-lookup.md) | Label history on `ViewPackage`; tracking lookup resolves through voided labels | ready-for-agent | `02` |

`01` is the only history any void gets until `02` lands, and `02` builds on its row-lock
snapshot. `02` is where the invariant is complete and tested, and it carries the two
things that cannot wait a release: the void reason and user (a void between releases
would lose them for good) and the source's own label identifier (both sources strip it
on void). `05` is the user-facing payoff and needs only `02`. `03` and `04` are
additional decisions, not steps.

The refund side — a voided label with a cost is a refund to expect — is one question
with `shopify-shipping-carrier/05` and lives there.

## Explicitly not in scope

- Moving any reader off the `packages` columns. ADR-0004 decision 3 makes that
  projection permanent.
- Copying or moving the label documents. ADR-0004 decision 6 keeps them on `packages`;
  the row duplicates none of the document or address payload governed by the current
  PII purge, so that purge is untouched. Reopen only on a concrete operational need.
- Labels that outlive their Package. ADR-0004 decision 7: a deleted or archived Package
  takes its labels with it. Reopening this means adding delete guards and an archive
  export, not changing the schema.
- Recovering labels voided before `02`. The audit rows hold nulls; nothing can be
  reconstructed and no `legacy` state is invented.
- A `PackageLabel` Filament resource of its own. History is shown on the Package it
  belongs to (`05`); a label is not something anyone browses independently of its
  parcel.

## Comments

- **2026-09-14** — Cut from nine issues to five on review. `06` folded into
  `shopify-shipping-carrier/05`; `07`–`09` (the three-release document move) removed
  with the decision not to copy the documents. `02` cut from thirty-plus criteria to the
  record, the invariant, the writers and the fixtures.
