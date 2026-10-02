# Decide and apply the Shopify ship-date policy

Status: done — 8 PM on the Shopify carrier row

Repo: `polybag`

## Problem

ADR-0002 decision 3 says Shopify needs "a conservative source-level policy — the earliest
relevant cutoff across the carriers it might pick." *Relevant* is not decided, and cannot be
decided from the code: we do not yet know which carriers Shopify can select for a given shop.

Learning after the fact that Shopify chose USPS cannot retroactively change the label's ship
date, so the policy has to be conservative by construction.

This is `ready-for-human` because it needs a judgment about which carriers are in play and
what the operational cost of being wrong in each direction is — an early ship date misses a
pickup, a late one delays the parcel.

## What to answer

1. Which carriers can Shopify Shipping actually select for the shops we serve? The purchase
   response is the only evidence, so this may have to wait on
   `shopify-shipping-carrier/01-verify-first-live-label-purchase`.
2. Is the policy "earliest cutoff among candidates", a fixed conservative hour, or a
   source-level setting the operator controls?
3. What happens when the chosen carrier turns out to have a later cutoff than assumed — is
   the mismatch worth surfacing, or is it noise?

## Acceptance criteria

- [x] The policy is written down in the ADR, not only in code
- [~] Shopify packages get a ship date from that policy rather than the default pickup days —
      the **cutoff** now comes from the policy; the **pickup days** are still the Mon–Fri
      default, which turned out to be a second decision rather than part of this one. Split
      out to `12`
- [x] The choice is expressed once, in one place, not spread across the adapter and the service

## Blocked by

- `05-ship-date-cutoffs-by-normalized-carrier`

## Decision — 2026-09-03

**8 PM local, held as `pickup_cutoff_hour` on the Shopify carrier row.** Behavior is
unchanged; what changed is that the value is now a decision recorded in ADR-0002 and stored
as data, rather than an interim constant with an open question attached.

**1. Which carriers can Shopify pick?** The premise above was stale — this did not need the
live purchase. Schema introspection had already bounded the set, and it is in the code as
`ShopifyShippingLabelService::CARRIER_CODES`: `usps`, `ups_shipping`, `dhl_express`,
`canada_post`, no FedEx. What `shopify-shipping-carrier/01` adds is how often Shopify
overrides `preferredRateSelection` — evidence for tuning the hour later, not for choosing the
policy. `06` was never actually blocked on it.

**2. What shape?** A fixed hour on the carrier row. Not derived from other carriers' cutoffs:
that would make Shopify's ship dates a function of which unrelated carriers happen to have one
configured, which is the coupling `05` deliberately backed out of. Not an operator setting
either — see below.

The reasoning for 8 PM rather than something lower is that "conservative by construction" was
assuming a symmetry that is not there. Assuming a later cutoff than the carrier Shopify picked
mis-dates one label by a day. Assuming an earlier one — low enough to cover DHL Express —
re-dates *every* Shopify package packed after that hour, including the USPS ones that are the
whole point, in the heaviest packing window. The rare case is the cheap one to be wrong about.

**3. Surface the mismatch?** No. The ship date is fixed by the time `trackingCompany` comes
back, so there is nothing actionable at the pack station.
`metadata.shopify_tracking_company` already records what Shopify picked, so the override rate
is a query if it ever matters.

An operator-controlled cutoff was considered and dropped, because the escape hatch already
exists: **End Shipping Day is ungated for Shopify** (`end-of-day.blade.php:66` renders it for
every active carrier), and `last_end_of_day_at` is checked *before* the cutoff in
`getShipDate()`. An operator who knows today's pickup has gone can already advance the date
without a setting.

## What shipped

- `2026_09_03_120000_set_shopify_pickup_cutoff_hour` — backfills existing installs, matching
  the Shopify row by name or by an alias resolving to it, and leaving a cutoff that is already
  set alone. Covered by `ShopifyPickupCutoffBackfillTest`, including the renamed-carrier case,
  because a silent failure here would drop the cutoff for every tenant at once.
- `CarrierSeeder` seeds `pickup_cutoff_hour => 20` on the Shopify row for new installs;
  `CarrierFactory::shopify()` added to match `usps()`.
- `ShipDateService` loses `BLIND_POSTAGE_INTERIM_CUTOFF_HOUR`, `cutoffHourFor()` and
  `isBlindPostageSource()`. The cutoff lookup is now `$carrier?->pickup_cutoff_hour` inline,
  and the service holds **no Shopify branch at all** — which is what acceptance criterion 3
  was asking for.
- ADR-0002 amended with the decision and the asymmetry reasoning.

One test changed meaning rather than being adjusted to pass. "applies the interim Shopify
cutoff even with no Shopify carrier row" asserted a fallback that the row-based policy does not
have. That path is unreachable: `ShopifyAdapter::getRates()` only advertises services hanging
off the Shopify carrier row, so no row means no rate offered and no label bought. The test now
asserts the honest behavior and records why the case cannot arise.

`pickup_cutoff_hour` is still not exposed in the carrier form. `05` held it back because an
operator-set value would have been ambiguous against the interim path; that ambiguity is gone
now, so exposing it is coherent — but nothing needs it, so it stays reference data.

## Deliberately not decided here

**Pickup days.** The same policy has a second half that this issue never mentioned: nothing
seeds a `carrier_location` row for Shopify, so it takes the Mon–Fri default and a
Saturday-packed package is dated Monday regardless of any cutoff. That is a two-day shift
against this issue's few-hour one. Filed as `12-shopify-pickup-days-policy`.

**Shopify's empty End of Day row.** `EndOfDay.php:76` counts with
`postage_source = CarrierAccount`, so the Shopify row permanently shows `—` packages while
still offering End Shipping Day. Reads oddly; belongs with `07`. — **`07` shipped without
touching it, 2026-09-03.** Filed on its own as `13-shopify-end-of-day-row` on 2026-09-04.

**Whether Shopify accepts a midnight `shippingDatetime`.** After the cutoff we send tomorrow
at 00:00. Worth confirming Shopify does something sensible with it during
`shopify-shipping-carrier/01`. — **Recorded there as question 9 on 2026-09-04**; it had been
pointed at that issue but never written into it.
