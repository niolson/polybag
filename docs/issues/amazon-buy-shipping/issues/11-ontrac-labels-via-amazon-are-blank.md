# OnTrac labels bought through Amazon come back without content

Status: needs-info — Amazon-side; needs a report to Amazon or a second account to compare against

Repo: `polybag`

## Problem

Two OnTrac Ground labels bought through Buy Shipping on 2026-09-11, against a fresh
unshipped order, came back with no label content — in both formats:

- **ZPL @300**: 1056 bytes. Tracking number, "Ship To:", "ONTRAC" and a barcode, and
  nothing between "Ship To:" at y=420 and "ONTRAC" at y=697 — no recipient, no sender.
- **PDF 4×6, joined**: page 1 (the label) is a content stream that draws nothing —
  `q 1 0 0 -1 0 432 cm q q Q Q Q`. Page 2 is the pack slip, complete.

Amazon's own copy is the same: `getShipmentDocuments` returned the PDF byte-for-byte, and
Seller Central's "reprint label" for that shipment shows the same blank page. So it is
not our handling of the response.

A USPS Ground Advantage label bought against the same order, same package, same body
apart from the `rateId`, rendered completely (from, to, barcode, PNG 1200×1800). The
difference is the carrier.

Both OnTrac labels were voided; the cancels succeeded and were refunded.

## What is not known

- Whether this is this account (health "At risk" at the time, just out of vacation
  mode), this origin (OnTrac is a regional West-coast carrier and the label may need
  an OnTrac account link Amazon expects to exist), or Amazon's OnTrac renderer.
- Whether the tracking number on the blank label was live with OnTrac — the parcel was
  never tendered.

## What to do

- Ask Amazon (Selling Partner support, Shipping API) with the two shipment IDs, which are
  in `.scratch/amazon-shipping-v2/live-run-2026-09-11.md`.
- Until answered, OnTrac is the cheapest offer on a typical West-coast parcel and a
  packer will pick it. The approval gate (`07`) already keeps it out of unattended
  purchase. Whether to drop OnTrac offers at quote time for attended purchase too is a
  policy call — a blank label is worse than a $0.15 dearer USPS one — but it should be a
  per-installation decision (mapping / approval), not a hard-coded carrier exclusion,
  because another account may well get a working label.

## Related

- [`03`](03-amazon-buy-shipping-adapter.md) — the live run that found it
- [`10`](10-purchase-body-is-refused-by-amazon.md) — the joined-PDF observation made on
  the same label
- [`07`](07-gate-automation-on-approval.md) — why this cannot reach auto-ship unapproved

## Comments

### 2026-09-11 — opened

Found by printing it: purchase 2 of the live run printed a pack slip and nothing else.
