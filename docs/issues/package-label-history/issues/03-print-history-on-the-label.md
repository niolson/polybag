# Print history on the label

Status: needs-triage — an enhancement, not part of the core record

Repo: `polybag`

## Problem

`02` projects `packages.label_printed_at` onto the label as `last_printed_at`, which
answers the question the parcel-on-the-bench scenario asks: was this label ever printed?
What it does not answer is "how many times, and when first" — today that is only
recoverable by walking `LabelPrinted` audit rows — and it does not accept a print
acknowledgement that arrives after the label was voided, because `LabelPrintController`
returns 422 for any package that is not `Shipped` before `markLabelPrinted()` is
reached. So a late acknowledgement is dropped and the voided label stays "never
printed", which is wrong but not dangerous.

Whether either matters enough to build is the triage question. Nothing in the
motivating workflow needs a count, and the late-acknowledgement window is the seconds
between a print and a void of the same label.

## If built

Three things the review established, kept here so they are not rediscovered:

- **Attribution needs the label's ID, not a timestamp.** `audit_logs.created_at` and
  `purchased_at` are second-precision, and tracking numbers are not unique across a
  package's labels (a re-ship can reuse one). Reconstructing history from audit rows
  written before this slice is therefore a bounded join — `auditable_id = package_id`,
  `metadata->tracking_number`, `created_at >= purchased_at` — and prints in the same
  second as a re-purchase are ambiguous and should be reported, not guessed. The first
  step of this slice is to put `package_label_id` into the `LabelPrinted` audit
  metadata so everything after it is unambiguous.
- **A count is an atomic `increment()`, not a read-modify-write.** Two acknowledgements
  for one label can arrive together (a double-click, a reprint racing the original).
- **A late acknowledgement changes the endpoint and the policy.** `PrintRequest` would
  carry `package_label_id` from the QZ Tray component; the controller would refuse a
  label that does not belong to the route's package; and authorization would move to
  the label's `purchased_by_user_id`, because `PackagePolicy::printLabel()` allows
  `shipped_by_user_id`, which the void nulled. Lock order stays `packages` then
  `package_labels`, as `02` set it — a print acknowledgement and a void for the same
  package can run concurrently, and a test of that belongs in the `mysql` group, since
  SQLite has no row locks.

## Blocked by

`02`. Touches printing: the PR documents the QZ payload change per AGENTS.md.

## Comments

- **2026-09-14** — Reclassified from `ready-for-agent` to `needs-triage` on review: the
  core record (`02`) records whether a label printed, and count, first-print and
  post-void acknowledgement are additional decisions. The backfill, write and endpoint
  design from the earlier draft is condensed above.
