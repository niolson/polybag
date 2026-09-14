# Void provenance: the carrier-side references

Status: ready-for-human — the only work here is characterising live void responses

Repo: `polybag`

## Problem

`02` records who voided and why (`VoidReason`: `operator` or `voided_upstream`), because
a label voided between releases would otherwise lose them for good. It also records
`source_label_reference` at purchase — the Shopify label ID, the Amazon shipment ID —
because both sources strip it from `packages.metadata` on void and nothing could backfill
it later. What `02` does not record is what the carrier **said back** when the label was
voided, because nobody has yet written down what each carrier says.

The two void origins, for reference:

| Origin | Path | Reason |
|---|---|---|
| An operator, from Pack (cancel last label), the Packages list, or `ViewPackage` | `EloquentPackageLabelWorkflow::voidLabel()` | `operator` |
| Shopify voided it upstream | `ShopifyFulfillmentSynchronizer` | `voided_upstream` |

(A failed print after purchase is *not* a third: `befb0f0` refuses a second purchase
rather than voiding the first, so the operator voids explicitly and it is `operator`.)

## Evidence to gather

- Void a label against each available source's sandbox or live account and record what
  the response actually contains. USPS, UPS, FedEx, Shopify and Amazon each answer a
  void differently; record a confirmation or refund reference where one exists and
  record that there is none where it does not. Do not invent one.

Only after that evidence exists should a follow-up decide whether `LabelVoidResult` and
`package_labels` gain a `void_reference`; storage is not part of this issue.

## Acceptance criteria

- [ ] A table in this file's Comments records, per source, what the void response
      actually contains, before anything is stored from it

## Blocked by

Access to each source's sandbox or live account. The evidence gathering does not depend
on `02`; only a later implementation that stores a reference would.

## Comments

- **2026-09-14** — Reclassified from `ready-for-agent` to `ready-for-human` on review:
  the single acceptance criterion is research, and `06`'s reconciliation question (now
  folded into `shopify-shipping-carrier/05`) is what would consume the answer.
