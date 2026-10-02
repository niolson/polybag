# Decide the Shopify pickup-day policy

Status: done — Mon–Fri, as an app-wide default; PR #171

Repo: `polybag`

## Problem

`ShipDateService::getShipDate()` applies two rules, not one: the carrier's cutoff hour and
the carrier's **pickup-day set**. Issue `06` settled the cutoff for Shopify at 8 PM. Nobody
has decided the pickup days, and the current behavior is an accident rather than a choice.

`CarrierSeeder` creates the Shopify carrier row and its `auto` service and stops — it seeds
no `carrier_location` pivot row. So `pickupDaysFor()` falls through to
`DEFAULT_PICKUP_DAYS`, Mon–Fri, and a Shopify package packed on a Saturday is dated
**Monday**. The cutoff never enters into it: the day rule fires first.

That is a two-day shift, against the few-hour shift `06` spent its judgment on. USPS runs
Saturday, Saturday is real DTC volume, and USPS Connect eCommerce rates are the entire
reason the Shopify integration exists.

This is `ready-for-human` for the same reason `06` was: it needs a judgment about how these
shops actually operate, which the code cannot supply.

## What to answer

1. Do the shops we serve hand parcels over on Saturday, or is Mon–Fri right for them?
2. Is this a seeded default on the Shopify carrier row, or is it left entirely to the
   per-location pickup-days config that already exists?
3. Does the answer differ per warehouse — which is the case the per-location config exists
   to serve?

## Worth knowing before deciding

Pickup days are **already operator-editable per carrier per location**, in
`Settings.php:333` and `LocationResource.php:91`. Both enumerate carriers, so Shopify
already appears in them and an operator can already set Mon–Sat today. The question is what
the default should be, not whether the control exists.

A default of Mon–Fri is the conservative direction here in the sense that matters: it never
dates a label for a pickup that does not happen. The cost is a Saturday-packed parcel
carrying Monday's date. Unlike the cutoff decision in `06`, the asymmetry does not obviously
run the other way — Saturday packing volume is the thing that would settle it, and we do not
have that number yet.

My leaning, recorded so it can be argued with: leave Mon–Fri and let per-location config
handle it, because Saturday pickup genuinely varies by warehouse and that config is already
per-warehouse. Seeding Mon–Sat globally would assert something about every install.

## Acceptance criteria

- [x] The policy is written down in ADR-0002 alongside the cutoff decision
- [~] Whatever is decided, Shopify's pickup days are a deliberate value rather than the
      consequence of an unseeded pivot row — deliberate, but as the **app-wide** default in
      `ShipDateService::DEFAULT_PICKUP_DAYS` rather than anything Shopify-specific. The
      premise that this was a Shopify gap was wrong; see the decision below
- [–] If a default is seeded, existing installs are covered by a migration, not only new ones
      — nothing is seeded, so nothing to cover

## Decision — 2026-09-04

**Monday–Friday, as the default for every carrier, with Saturday left to the per-location
config that already exists.** Behavior is unchanged; what changed is that the value is a
recorded decision rather than the consequence of a pivot row nobody seeds.

**1. Do the shops hand parcels over on Saturday?** Some do, and we do not have the Saturday
packing volume that would settle it globally — which is the answer, not an absence of one.
Mon–Fri never dates a label for a pickup that does not happen, and its cost lands only on the
warehouses that do work Saturday, where an operator can already fix it.

**2. Seeded default, or left to per-location config?** Neither, in the end, because the
question contained a false premise. This issue framed the unseeded `carrier_location` row as a
Shopify problem. Nothing seeds that pivot for **any** carrier: USPS, UPS and FedEx run on the
same `DEFAULT_PICKUP_DAYS` fallback on a fresh install. There was no Shopify-shaped gap, only
an app-wide default nobody had written down. Seeding a row for Shopify alone would have meant
creating one carrier's worth of data purely to make a statement enforceable — and, given that
`Settings.php:733` deletes pivot rows omitted from the repeater, a row one unrelated save away
from being destroyed along with its `last_end_of_day_at`. So the value stays in code and the
ADR says it is deliberate.

**3. Does the answer differ per warehouse?** Yes, which is why the exception belongs in the
per-location config rather than in a global seed. Seeding Mon–Sat would assert something about
every install that is true of only some.

Note the asymmetry does *not* run the way it did for the cutoff in `06`. There, conservatism
was the expensive direction because the pessimistic case was the common one. Here the
conservative default is also the cheap one.

## What shipped

PR #171.

- ADR-0002 amended with the decision, the false premise, and why no migration is needed. The
  "pickup days are not decided here" paragraph now points forward to it rather than silently
  contradicting it.
- `ShipDateService::DEFAULT_PICKUP_DAYS` carries the policy and its reasoning.
- Both pickup-day repeaters (`Settings.php`, `LocationResource.php`) name the Mon–Fri default
  in their section description. They start empty and list only configured carriers, so an
  operator wanting Saturday would otherwise see no row to reason from — the default was
  invisible, not merely implicit.
- `pickupDaysFor()` no longer passes an empty day set through, and the form requires at least
  one day. See below.

Four tests in `ShipDateServiceTest` and one in `SettingsTest`; both guards verified by
reverting each in turn rather than assumed.

## Found while deciding

**An empty pickup-day set dated labels for Sundays.** A `carrier_location` row can hold one —
`endShippingDay()` inserts without pickup days, and a saved form with nothing ticked stored
`[]` — and `pickupDaysFor()` passed it straight through, since `??` only catches null and the
raw column `"[]"` is truthy. `getNextPickupDay()` then found no day in its 7-day loop and hit
its tomorrow fallback, whatever day tomorrow was. Fixed here because this decision makes that
config the whole policy: it had one silently-wrong state reachable by an operator doing
something that looked reasonable.

Code review caught a second claim that came with the first and was also wrong: the fix was
described, in the helper text and the ADR alike, as making removal of a row the way to say a
carrier never collects at a location. It is not — `pickupDaysFor()` treats a missing pivot as
the default too, so removal resets rather than disables, and an operator following that
guidance would have generated ship dates for pickups that never happen.

There is no such state, and there should not be: a package shipped on that carrier still needs
a ship date, and no honest one exists if the carrier is held to collect on no day at all.
Keeping a carrier out of a location is what carrier account scoping does. The corrected helper
text says what removal actually does, since the natural reading is the wrong one, and a test
pins both directions.

## Related

- `06-shopify-ship-date-policy` — the cutoff half of the same policy, settled at 8 PM
