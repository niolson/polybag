# Shopify's carrier strings don't normalize, so a Shopify UPS label has no carrier

Status: done — 2026-09-08

Repo: `polybag`

## Problem

`10` made the carrier Shopify picked the package's **carrier of record**, read from
`trackingInfo.company`. Shopify returns that field in two different vocabularies
depending on which node is asked, and `CarrierNormalizer` resolves neither of the UPS
spellings:

| Shopify string | Where | Resolves |
|---|---|---|
| `usps` | `ShippingLabel.trackingInfo.company` | yes — by coincidence, it matches the carrier row's name |
| `USPS` | `Fulfillment.trackingInfo.company` | yes |
| `ups_shipping` | `ShippingLabel.trackingInfo.company` | **no** |
| `UPS®` | `Fulfillment.trackingInfo.company` | **no** |

Both failures are observed on real labels bought through PolyBag. The second one shipped:
package 205 carries `carrier = "ups_shipping"` and `normalized_carrier_id = null`, so a
UPS parcel is in the system with no carrier of record, and with it goes the ship-date
cutoff and the export mapping that read from it.

**`ShippingLabel.trackingInfo.company` is Shopify's internal carrier code**, not a carrier
name — `usps`, `ups_shipping`, and by extension the `dhl_express` and `canada_post` in
`ShopifyShippingLabelService::CARRIER_CODES`. This is exactly what `01` question 5 asked
and feared. USPS resolving is luck: its code and its name are the same string. Nothing was
ever normalizing these; the first label just happened to be the one that hides it.

`Fulfillment.trackingInfo.company` is the display name, and carries a registered-trademark
sign on UPS. `CarrierAlias::lookupKey()` lowercases but leaves punctuation, so `UPS®` keys
to `ups®` and matches nothing.

## What to change

Two separate fixes, and they want to stay separate:

1. **Map Shopify's carrier codes.** They are a closed vocabulary Shopify publishes and
   `CARRIER_CODES` already lists, so map them where the Shopify carrier is known to be the
   source rather than teaching the general normalizer four strings that mean nothing
   outside Shopify. `ups_shipping` is not a carrier name any other source would send.
2. **Fold the trademark signs in `lookupKey()`.** That one belongs in the key, not in a
   row: an alias for `ups®` fixes one string and leaves `FedEx®` and `DHL Express®`
   waiting to be found the same way. Keep the folding to characters that are
   unambiguously decoration — stripping all punctuation would fold distinctions in names
   we have not seen.

Then decide what to do about **packages already shipped with an unresolved carrier**.
Package 205 is one, in a development database, so a backfill is optional here and would
not be on a real install.

## Not this issue

`DHL Express` and `Canada Post` have no carrier row in PolyBag at all — Shopify sells
through both. Mapping their codes to nothing is the designed terminal state, not this bug.
If they are ever wanted it is a carrier row plus a `CarrierAlias`, not key folding.

## Acceptance criteria

- [x] A label whose `trackingInfo.company` is `ups_shipping` records UPS as the carrier
- [x] `UPS®` resolves to UPS, from the observed string, and the folding lives in
      `lookupKey()` so `FedEx®` resolves too
- [x] `usps` and `USPS` still resolve, and a test pins that it is not by coincidence
- [x] A code with no carrier row still resolves to null rather than being invented
- [x] Existing alias lookups unchanged

## Comments

### 2026-09-08 — done

Both halves, kept apart as the issue asked.

**The codes are translated where Shopify is known to be the source.**
`ShopifyShippingLabelService::CARRIER_CODES` became `CARRIER_NAMES`, a code-to-name map —
the old constant held the same vocabulary, had no readers, and keeping both would have let
the list and the translation drift. `ShopifyAdapter` runs `trackingInfo.company` through
it before the carrier of record is written; a string that is not one of the four codes
passes through untouched, so `DHL eCommerce` still records as itself. The raw string stays
in `metadata.shopify_tracking_company`: that field is the record of what Shopify said, not
a carrier.

**The trademark folding is in `lookupKey()`**, over `®`, `™` and `℠`, replaced with a
space so a mark inside a name does not glue two words together. Nothing else is stripped.
A migration recomputes `carrier_aliases.lookup_key` from the alias text, because a key
stored with a mark in it would no longer be reachable — no rows needed it locally, and on
an install that has one it is the difference between an alias working and silently not.

`ups_shipping` deliberately still resolves to null through `CarrierNormalizer` on its own.
It is not a carrier name any other source would send, and the adapter has already
translated it by the time a carrier is recorded.

### 2026-09-08 — the refold migration was written, then dropped as unnecessary

The `done` entry above described a migration recomputing `carrier_aliases.lookup_key`. It
is not in the change. Recorded here because deciding *not* to migrate is the kind of thing
that otherwise looks like an oversight.

Review caught a real defect in it first: `carrier_aliases.lookup_key` is unique, folding can
put `Marked Freight` and `Marked™ Freight` on one key, and a row-by-row update would have
raised a duplicate-key error mid-migration. `docker/entrypoint.sh` runs migrations *before*
it execs php-fpm, so that would not have been a failed migration — it would have been an
install that does not boot. It was fixed with a grouping pass and a merge/skip policy, and
then the whole thing was removed, because there is nothing anywhere for it to do:

- `CarrierAliasSeeder` seeds four aliases and none carries a mark, so no seeded install has
  a stale key.
- The `®` strings in `CarrierSeeder` are `CarrierService` names. `CarrierNormalizer` reads
  carrier names and alias rows, never service names.
- No install has hand-entered aliases.

The only row it could have fixed is one typed through Map Carrier Services containing a
mark, on an install that does not exist.

**What replaces it, if such a row ever turns up:** nothing automatic. Its key keeps the
mark, so it stops matching — an alias that quietly does nothing, not an error. Adding the
unmarked spelling in the UI fixes it, and `CarrierAlias::conflictMessage()` already refuses
a new alias that folds onto an existing key, so the unique index is not reachable from the
UI either. If PolyBag ever ships to an install whose alias table we did not write, this is
the migration to write, and the policy it needs is in this file's history.
