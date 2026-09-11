# Shopify's carrier strings don't normalize, so a Shopify UPS label has no carrier

Status: done — 2026-09-08

Repo: `polybag`

## Problem

`10` made the carrier Shopify picked the package's **carrier of record**, read from
`trackingInfo.company`. Shopify returns that field in two different vocabularies depending
on which node is asked, and `CarrierNormalizer` resolved neither UPS spelling:

| Shopify string | Where | Resolved |
|---|---|---|
| `usps` | `ShippingLabel.trackingInfo.company` | yes — by coincidence, it matches the carrier row's name |
| `USPS` | `Fulfillment.trackingInfo.company` | yes |
| `ups_shipping` | `ShippingLabel.trackingInfo.company` | **no** |
| `UPS®` | `Fulfillment.trackingInfo.company` | **no** |

Both failures were observed on real labels, and the second shipped: package 205 carried
`carrier = "ups_shipping"` with `normalized_carrier_id = null` — a UPS parcel with no
carrier of record, and with it the ship-date cutoff and the export mapping that read from
it.

**`ShippingLabel.trackingInfo.company` is Shopify's internal carrier code**, not a carrier
name. USPS resolving was luck: its code and its name are the same string, so the first
label hid the defect. `Fulfillment.trackingInfo.company` is the display name and carries a
registered-trademark sign on UPS, which `CarrierAlias::lookupKey()` left in the key.

## What shipped

Two fixes, kept apart because they belong at different layers.

**Shopify's codes are translated where Shopify is known to be the source.**
`CARRIER_CODES` became `CARRIER_NAMES`, a code-to-name map — the old constant held the
same vocabulary and had no readers, and keeping both would have let the list and the
translation drift. `ShopifyAdapter` runs `trackingInfo.company` through it before the
carrier of record is written; a string that is not one of the four codes passes through
untouched, so `DHL eCommerce` still records as itself. The raw string stays in
`metadata.shopify_tracking_company`, which is the record of what Shopify said rather than
a carrier. `ups_shipping` deliberately still resolves to null through `CarrierNormalizer`
on its own — no other source would send it.

**Trademark folding lives in `lookupKey()`**, over `®`, `™` and `℠`, replaced with a space
so a mark inside a name does not glue two words together. Nothing else is stripped:
an alias row for `ups®` would have fixed one string and left `FedEx®` and `DHL Express®`
waiting to be found the same way.

- [x] `ups_shipping` records UPS as the carrier
- [x] `UPS®` resolves to UPS, and the folding makes `FedEx®` resolve too
- [x] `usps` and `USPS` still resolve, with a test pinning that it is not coincidence
- [x] A code with no carrier row resolves to null rather than being invented
- [x] Existing alias lookups unchanged

**`DHL Express` and `Canada Post` have no carrier row at all.** Mapping their codes to
nothing is the designed terminal state, not a bug. If they are ever wanted it is a carrier
row plus a `CarrierAlias`, not key folding.

## Comments

- **2026-09-08** — both halves shipped as specified.
- **2026-09-08** — a migration recomputing `carrier_aliases.lookup_key` was written, then
  **dropped as unnecessary**, which is recorded because deciding not to migrate otherwise
  looks like an oversight. Review first caught a real defect in it: `lookup_key` is unique,
  folding can put `Marked Freight` and `Marked™ Freight` on one key, and a row-by-row
  update would have raised a duplicate-key error mid-migration — and since
  `docker/entrypoint.sh` migrates before it execs php-fpm, that is an install that does not
  boot, not a failed migration. It was fixed with a grouping pass and then removed outright,
  because no row anywhere needs it: the seeded aliases carry no marks, the `®` strings in
  `CarrierSeeder` are `CarrierService` names (which `CarrierNormalizer` never reads), and no
  install has hand-entered aliases. If such a row ever turns up its key keeps the mark and
  quietly stops matching; adding the unmarked spelling in the UI fixes it, and
  `CarrierAlias::conflictMessage()` already refuses a new alias that folds onto an existing
  key. The policy this needs, if it is ever written, is in git history.

## Related

- `10` — made `trackingInfo.company` the carrier of record
- `01` — question 5, which asked exactly this and feared exactly this answer
