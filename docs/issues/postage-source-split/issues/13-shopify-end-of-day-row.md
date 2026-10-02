# Decide what the Shopify row on End of Day should show

Status: wontfix

Repo: `polybag`

## Problem

`EndOfDay` builds its summary from `Carrier::active()`, one row per carrier. The Shopify
carrier row is active — it has to be, because `ShopifyAdapter::getRates()` only advertises
services hanging off it — so Shopify gets a row like every other carrier. That row is now
permanently half-empty, and the half that is empty is empty *by construction* rather than
because nothing shipped today.

Its package count filters `where('carrier', 'Shopify')` **and** `boughtOnCarrierAccount()`, and
after the postage-source split neither can ever match:

- No package records `carrier = 'Shopify'` any more. Slice `03` made a Shopify purchase record
  the physical carrier of record, falling back to the uppercased requested carrier code, or to
  null for an unconstrained `auto` purchase. `Shopify` is not among the outcomes.
- Even if one did, `boughtOnCarrierAccount()` excludes it — that is exactly the manifest
  eligibility rule slices `02` and `07` built.

So the count reads `—` forever. `Generate Manifest` correctly never appears, because
`policyFor('Shopify')` returns null now that `ShopifyAdapter` no longer implements
`CarrierPolicy`, and the summary defaults `supports_manifest` to false.

## Why this is not simply "hide the row"

**End Shipping Day on that row is load-bearing.** Slice `06` settled the Shopify cutoff at 8 PM
partly *because* the escape hatch already existed: `end-of-day.blade.php` renders End Shipping
Day for every active carrier, `getShipDate()` checks `last_end_of_day_at` **before** the cutoff,
and an operator who knows today's Shopify hand-off has gone can already advance the date without
anyone adding a setting. That reasoning is recorded in ADR-0002's 2026-09-03 amendment and is
the stated reason an operator-controlled cutoff was rejected. Removing the row would quietly
remove the argument.

So the row is genuinely half-meaningful: the control is wanted, the count is noise. That is the
decision to make, not a bug to fix.

## What to answer

1. Is a permanently `—` count actively misleading, or merely untidy? It reads as "nothing
   shipped on Shopify today" when the truth is "this page does not count Shopify labels, and
   cannot".
2. If it should say something instead, what? Options, roughly in increasing effort:
   - Label the count as not applicable for resale-channel postage, keeping the control.
   - Count Shopify-bought packages for the day *without* implying manifest eligibility — a
     different number with a different meaning, which risks reintroducing the conflation this
     whole feature removed.
   - Split the row's presentation: a manifest section for carriers we tender to, and a
     ship-date section for every active carrier, since End Shipping Day is a ship-date control
     that happens to live on a manifest page.
3. Does the same question apply to any future resale channel? Amazon Buy Shipping will land in
   the same position: an active `Carrier` row whose packages are never manifest-eligible. If so,
   the answer should key on postage-source capability rather than on the name `Shopify`.

## Worth knowing before deciding

- This is presentation only. No count, gate or manifest is wrong today — `02` and `07` verified
  the exclusion behavior and it is covered by `ManifestServiceTest`, `EndOfDayTest` and
  `EndOfDayManifestTest`.
- Nothing here is urgent in the ordinary sense, but it gets *more* confusing as soon as the
  first real Shopify label is bought: the row will sit at `—` on a day when Shopify labels
  demonstrably shipped, which is when someone will read it as a defect.

## History

Raised in `06` as "Shopify's empty End of Day row", deferred there to `07` on the grounds that
it belonged with the dispatch work. `07` shipped 2026-09-03 (PR #164) without touching it, and
its `What shipped` section does not mention it. Filed on its own 2026-09-04 after a review of
this directory found it had fallen between the two.

## Blocked by

None. `07` merged 2026-09-03 (PR #164).

## Related

- `06-shopify-ship-date-policy` — why End Shipping Day on this row matters
- `07-dispatch-by-postage-source` — the postage-source manifest gate

## Comments

- **2026-10-02** — **Wontfix: superseded by `carrier-catalog-reset`.** `09` deleted the
  `Shopify` carrier row (migration `remove_shopify_carrier`), so End of Day has no Shopify
  row to show. `08` answered the question this issue posed: a Shopify label is counted on
  its carrier of record's row, and an `auto` purchase is dated by the connection's *Date
  Shopify's choice as* carrier, so the escape hatch `06` relied on is that carrier's End
  Shipping Day. The one gap `08` left is filed as `end-of-day-count/01`.
