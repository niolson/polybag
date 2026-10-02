# Address validation routing: free carrier validators first, paid ones as fallback

Status: needs-triage
Created: 2026-09-30

## Problem Statement

Every imported US Shipment has its address validated, and the two validators PolyBag
uses cost money per request. USPS address validation now costs money, and Google Address
Validation is billed per request beyond its monthly free allowance. FedEx and UPS offer
address validation free to their account holders, but PolyBag cannot use them yet, and
using them is not as simple as putting them first in the chain:

- **The carriers' agreements limit when their validation may be used.** FedEx allows it
  only for shipments tendered to FedEx. UPS allows it only for a package intended for
  UPS, and requires a liability notice wherever its results are shown. So which
  validator may run depends on the Shipment, not just its country.
- **The validator chain is one fixed list, chosen by country alone.** It is USPS, then
  Google if enabled. It cannot say "FedEx for this Shipment but not that one".
- **An address no validator can settle is re-validated every five minutes, forever.**
  The scheduled validation run selects every US Shipment that is not `checked`, and an
  inconclusive result deliberately leaves `checked` false so a later validator can try.
  With Google enabled, Google usually ends that loop by setting `checked`. With Google
  off, or with more validators that can each be inconclusive, the same paid request
  repeats on every run.
- **Nothing records which validator produced a result.** The UPS notice cannot be shown
  only on UPS results, and nobody can see how often a paid validator was reached.
- **Google wrongly fails addresses in countries that don't use a state.** PolyBag sends
  the stored state to Google for every country. For countries whose addresses carry no
  state, such as Germany, Poland, Portugal and Slovenia, Google then reports the address
  as incomplete, and PolyBag records it as not deliverable. In testing, every real address
  in those four countries failed this way, and 7 of 8 passed once resent without the
  state.
- **International Shipments are never validated automatically.** The scheduled run
  selects US Shipments only. Outside the US, an address is validated only when someone
  asks for it on the Shipment or in Manual Ship.

## Solution

Choose the validators for each Shipment from what its shipping method allows and what
each validator is known to be good at, and try the free ones first:

- **US and Puerto Rico:** FedEx when allowed, then UPS when allowed, then USPS, then
  Google.
- **Other countries:** FedEx when allowed and the country is on FedEx's trusted list, then
  Google when Google supports the country, then FedEx as a last resort for countries
  Google rejects.

A validator that cannot settle the address hands it to the next one, as today. Each
result records which validator produced it. A Shipment that every validator has tried is
not tried again on the schedule, only when someone asks. Google stops being sent a state
for countries whose addresses don't use one. Once that is in place, international
Shipments are validated on the schedule too.

Deliverability stops calling a reference-data match "deliverable": it gains "Verified"
and "Couldn't verify". Every validator's answer is recorded, FedEx is also asked on the
Shipments it may validate, and the answers are compared with what actually happened, so
the routing can be revised from real orders.

The FedEx and UPS validators already exist and are tested against mocked responses, but
nothing calls them: they cannot be put in the fixed chain without breaking the carriers'
terms.

## User Stories

1. As an operator, I want addresses validated by a free carrier validator whenever the
   Shipment may use one, so that I pay for fewer USPS and Google requests.
2. As an operator, I want FedEx address validation used only for Shipments whose shipping
   method includes a FedEx service, so that PolyBag stays within the FedEx agreement.
3. As an operator, I want UPS address validation used only when UPS is the only carrier
   the shipping method can use, so that PolyBag stays within the UPS agreement.
4. As an operator, I want a carrier validator skipped when the Shipment's Client has no
   account with that carrier, so that validation never fails for want of credentials.
5. As an operator, I want a Shipment with no shipping method validated only by USPS and
   Google, so that no carrier validator is used for a package that may not go with that
   carrier.
6. As an operator, I want an address that one validator cannot settle passed to the
   next, so that a gap in one validator's data never leaves the address unchecked.
7. As an operator, I want a Shipment that every validator has tried to stop being retried
   on the schedule, so that the same paid request is not sent every five minutes.
8. As an operator, I want validation retried when the validators could not be reached
   (outage, rate limit, credentials rejected), so that a temporary failure does not leave
   the address unchecked for good.
9. As a shipper, I want the Validate action on a Shipment to always re-run validation, so
   that I can recheck an address after correcting it.
10. As a shipper, I want to see which validator checked an address, so that I know how
    much weight to give the result.
11. As a shipper, I want the UPS liability notice shown wherever a UPS validation result
    is shown, so that PolyBag meets the UPS agreement.
12. As a shipper, I want the UPS notice shown only on UPS results, so that it does not
    clutter every Shipment.
13. As an operator shipping to Germany, Poland, Portugal or Slovenia, I want real
    addresses to stop failing Google validation, so that valid orders are not flagged as
    undeliverable.
14. As an operator, I want Google sent a state only for countries whose addresses use
    one, so that the state never makes Google distrust a correct address.
15. As an operator shipping internationally, I want FedEx used first only in countries
    where it checks house numbers reliably, so that the savings never cost accuracy.
16. As an operator shipping to Belgium, Portugal or Brazil, I want FedEx skipped, so that
    a wrong house number is never accepted as valid.
17. As an operator shipping to Hong Kong, Uruguay or Liechtenstein, I want FedEx used even
    though it is not on the trusted list, so that those addresses get validated at all,
    since Google does not support them.
18. As an operator, I want an international FedEx match accepted only when FedEx returned
    the same house number I sent, so that FedEx quietly substituting a different real
    address is not taken as confirmation.
19. As an operator shipping to Slovakia or Czechia, I want two-part house numbers such as
    `482/22` compared correctly, so that a FedEx match that keeps only the street number
    is not treated as a substitution.
20. As an operator, I want a US address that FedEx can only place at a single-organization
    ZIP code treated as "maybe", so that it matches how USPS treats the same address.
21. As an operator shipping internationally, I want international Shipments validated on
    the schedule once validation is cheap, so that address problems surface before
    packing, not at label purchase.
22. As a maintainer, I want the per-country lists (FedEx trusted, FedEx excluded, Google
    unsupported) kept in one place with the evidence behind them, so that updating them
    after re-testing is a one-place change.
23. As a maintainer, I want to be able to see how often each validator settled an address
    and how often a paid validator was reached, so that the savings can be measured and
    the country lists revisited.
24. As a self-hosting operator without FedEx or UPS accounts, I want validation to behave
    as it does today, so that the change costs me nothing.
25. As an operator in sandbox mode, I want validation to keep using the fake validator
    unless real validation in sandbox is switched on, so that testing does not spend
    requests.
26. As a shipper, I want an address that matched reference data but has no delivery-point
    confirmation shown as "Verified", not "Deliverable", so that I know what was actually
    checked.
27. As an operator, I want an address no validator could settle shown as "Couldn't
    verify", not "Not deliverable", so that the exceptions list holds only addresses with
    evidence against them.
28. As a maintainer, I want every validator's answer on real orders recorded, including
    FedEx's on Shipments another validator settled, and later compared with what actually
    happened to the parcel, so that the routing improves from real data, not test
    addresses.
29. As a maintainer, I want UPS validation measured against the other validators before
    it is trusted, so that a free validator of unknown quality does not accept wrong
    addresses.

## Implementation Decisions

- **Two new columns on Shipments:** when validation was last attempted, and which
  validator produced the current result (USPS, Google, FedEx, UPS or fake). A
  result's source is set only when a validator settles the address. An attempt is recorded
  only when at least one validator actually ran and gave an answer; a run in which every
  validator was unavailable records no attempt, so the schedule retries it.
- **The scheduled validation run** selects Shipments that are not `checked` and have no
  recorded attempt. Manual validation, from the Shipment or Manual Ship, ignores the
  attempt and always runs. Changing a Shipment's shipping method or address clears the
  attempt, so a Shipment validated before it had a FedEx or UPS method gets the free
  validator's turn once it has one.
- **Every validator's answer is logged**, not only the one that settled the address:
  validator, free or paid, outcome, a reason from a small fixed vocabulary when
  inconclusive, country, and time. Rows hold verdicts, not copies of the address. This
  is the evidence for revising the routing from real orders rather than test addresses.
- **Deliverability says what the evidence supports.** Two values are added:
  - **`verified`** ("Verified"): matched the reference data at house level, with no
    delivery-point data. Google international, FedEx international and UPS results land
    here.
  - **`unverified`** ("Couldn't verify"): every validator in the chain was tried and
    none could confirm or reject the address.

  `yes` ("Deliverable") is kept for a confirmed delivery point: USPS DPV, Google's USPS
  data, FedEx US DPV. `no` ("Not deliverable") is kept for positive evidence the address
  is wrong. An incomplete Google verdict is inconclusive, not `no`. Existing non-US `yes`
  rows are backfilled to `verified`.
- **A validation plan module** takes a Shipment and returns the ordered validators for
  it. It is the only place the routing rules live, and it depends on:
  - the Shipment's country and shipping method;
  - the carriers the method's services belong to;
  - whether the Shipment's Client has an active account with each carrier;
  - the per-country lists below;
  - the existing settings (fake carriers, demo mode, sandbox mode, real validation in
    sandbox, Google enabled).

  `AddressValidationService` stops taking a fixed list and asks the plan for each
  Shipment. It keeps the existing fallback behavior: stop once a validator settles the
  address, and report a failure only after every validator has had its turn.
- **Carrier eligibility.** FedEx is eligible when the shipping method includes a FedEx
  carrier service or allows Amazon Buy Shipping, which may sell a FedEx label. UPS is
  eligible only when every carrier service on the method is UPS and the method does not
  allow Amazon Buy Shipping. A Shipment with no shipping method gets neither: nothing can
  be bought for it (ADR 0006, amended by `carrier-catalog-reset/16`), but it can still be
  packed, so USPS and Google still check its address. These rules encode our reading of
  the carriers' agreements and belong in one place, so a written clarification from
  either carrier changes one rule.
- **Per-country lists** live together in one class or data file, each entry citing the
  test it came from:
  - **FedEx trusted internationally:** AT, CH, CZ, DE, ES, FR, IT, LV, MX, NL, PL, and
    the lower-coverage DK, FI, LT, LU, NO, SI, CL. A FedEx miss falls through to Google,
    so a lower-coverage country only saves less.
  - **FedEx excluded:** BE, BR, PT, where it accepts wrong house numbers.
  - **Google unsupported:** HK, LI, UY, where it rejects the region.
- **FedEx result reading, international.** A match settles the address only when all of
  these hold:
  - FedEx reports it matched.
  - The precision is house-level.
  - The returned house number equals the input number, where the comparison understands
    two-part numbers.

  Anything else is inconclusive. A settled international result is `verified`. The US
  reading (resolved, delivery point confirmed, suite flags) stays, with
  single-organization ZIP precision mapped to "maybe".
- **Google request:** send the administrative area only when the address reference
  service says the country uses one.
- **UPS** ships together with its notice, which is shown with the validation result on
  the Shipment wherever the result's source is UPS, in UPS's own wording. Nobody on the
  project knows how reliable UPS validation is, so before it goes ahead of USPS it gets
  the same production comparison FedEx had. Until then its valid-address result maps to
  `verified`.
- **International on the schedule** is the last step. The scheduled run's US-only filter
  is dropped once the plan and attempt tracking are in, so cost stays bounded.
- **FedEx shadow check.** When a FedEx-eligible Shipment is settled by another validator,
  FedEx is asked as well and its answer is logged without changing the result. It is
  free and builds a side-by-side comparison on real orders. It runs in every country,
  including the excluded BE, BR and PT, to show whether FedEx improves there. It sits
  behind a setting, on by default.
- **Quality against outcomes.** Validator answers are later joined to evidence of whether
  they were right: address edits after validation, label purchase failures, carrier
  tracking exceptions, undeliverable returns, deliveries. Choosing those signals is a
  design decision of its own.

## Testing Decisions

- A good test drives a Shipment through validation and asserts what an operator would
  see: the deliverability, the message, the validated address, the recorded source, and
  whether a request went out to each API. It fakes the HTTP responses and does not assert
  which private methods ran.
- **Validation plan:** table-driven tests of Shipment in, ordered validators out,
  covering:
  - country;
  - shipping methods with FedEx only, UPS only, mixed carriers, or none;
  - Clients with and without each carrier account;
  - each of the three country lists;
  - the fake, demo and sandbox settings.
- **Validation service with the plan:** fallback order, stopping once settled, failure
  reported once after the chain, attempt and source recorded, and no attempt recorded
  when every validator was unavailable.
- **Scheduled run:** an attempted Shipment is not picked up again, an unavailable run is,
  and manual validation re-runs regardless.
- **FedEx international reading:** accept, house-number substitution, street-only
  precision, and two-part numbers, using response shapes taken from the recorded
  production responses.
- **Google request:** no administrative area for a country that doesn't use one; one for
  a country that does.
- **UPS notice:** present on a UPS result, absent on others.
- **Deliverability:** each validator's results map to the agreed values; a chain that
  ends inconclusive is `unverified`; the backfill moves non-US `yes` only.
- **Answer log and shadow check:** one row per validator that answered, none for one
  that was unavailable; a shadow answer never changes the Shipment's result.
- **Prior art:** the existing tests for the FedEx, UPS, Google and USPS validators and for
  the validation service already fake each API with mocked responses and assert on the
  Shipment. The UPS request is also validated against UPS's published schema.

## Out of Scope

- Caching validation results. The UPS agreement's limits (per customer, at most nine
  months, deleted on request) would apply, and nothing here needs a cache.
- Validating many addresses per request. FedEx accepts up to 100, but one bad state code
  or an unexplained server error fails the whole batch, and validation runs one Shipment
  at a time today.
- FedEx or UPS validation outside the countries named here, and any carrier's validation
  for a carrier the shipping method cannot use.
- Using FedEx's business/residential classification for rating. It is recorded on the
  Shipment as today and nothing more.
- Asking FedEx or UPS for broader written permission. That would widen the eligibility
  rules later without changing this design.
- Fixing the address reference data used as validation ground truth in our demo tooling.

## Further Notes

**Evidence.** The country lists come from production runs on 2026-09-30 against FedEx,
and against Google for comparison. The raw captures are in local scratch, not the repo;
this is a summary:

- **US:** FedEx confirmed a delivery point for 50 of 50 addresses USPS confirmed
  deliverable.
- **International method:** per country, 10 real addresses and 10 with the house number
  inflated to one that mostly doesn't exist.
  - FedEx accepted 192 of 269 real addresses; Google 211.
  - FedEx accepted 23 of 260 inflated numbers; Google 11.
  - FedEx accepted inflated numbers in Belgium (7 of 10), Portugal (4) and Brazil (3).
  - Google accepted only 2 of 10 real Czech addresses; FedEx accepted all 10.
  - FedEx rarely matched Canadian or Australian addresses at house level, so those gain
    little from FedEx first.
- **Samples are small:** ten per group per country. The lists sort countries into
  groups; they are not exact rates. FedEx's reference data is updated monthly, so the
  comparison is worth re-running before widening the trusted list.

**FedEx says not to use its API to determine deliverability.** Its documentation says
this, and that FedEx does not deliver to every valid address. PolyBag's deliverability
is a property of the address, not of a carrier. Google's international results are the
same kind of reference-data match, and PolyBag recorded those as deliverable. Neither
FedEx nor Google internationally has delivery-point data the way USPS does, which is why
those results become `verified`, not `yes`.

**Decisions, 2026-10-01** (at slicing):

- The lower-coverage countries join the FedEx trusted list.
- A method that allows Amazon Buy Shipping counts as FedEx-eligible for validation, since
  FedEx can then be the carrier of record without being the postage source.
- A Shipment with no shipping method gets no carrier validator. It is not an error, since
  it can be packed, but nothing can be bought for it.
- Deliverability gains `verified` and `unverified`, as above.
- UPS is measured against the other validators before it is trusted.
- The FedEx shadow check runs in excluded countries too.

**Issues:** [`issues/`](issues/), `01`–`11`. `01`–`04` and `06` can start immediately;
`05` is the tracer for the plan.
