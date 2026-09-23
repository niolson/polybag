# Let automation require on-time or OTDR-protected Amazon offers

Status: done — shipped 2026-09-23, both settings; `14`'s question 1 turned out to be answered by captures already in `.scratch/`

Repo: `polybag`

## Problem

Amazon's help page for Buy Shipping protections says offers that carry late-delivery risk
are filtered out before they reach the seller. That stopped being true in practice on
2026-08-10. A
[Seller Forums thread](https://sellercentral.amazon.com/seller-forums/discussions/t/f166290f-b9c5-44cc-89e7-81338551f39a)
reports that since then, Buy Shipping integrations can buy services that are not expected
to meet the delivery date. An order-management vendor confirmed the problem, and it was
still unresolved when checked on 2026-09-23. Amazon may restore the filtering, or may not.
PolyBag must not depend on either.

PolyBag does not check this itself. `RateSelector::classify()` sorts on-time rates first
and late rates after them, and `selectForAutomation()` takes the first. When no offer
arrives by `Shipment::getDeliverByDate()`, **automation buys the cheapest late offer**.
Until 2026-08-10, Amazon never returned a late offer, so that fallback did not matter on
this path.

Buying a late offer is not always wrong. What a late delivery costs the seller depends on
whether the Label is **OTDR-protected**. If it is, a late delivery does not count against
the seller's OTDR (see `14`). Protection is a separate attribute of the offer from its
promised date. Amazon could in principle protect an offer whose window ends after
`deliver_by`. It can also leave an on-time offer unprotected. That happens when the seller
has not turned on the two Seller Central automation settings, and may also depend on the
carrier or the order's program. So two separate things matter, and the seller should
decide which of them automation insists on.

`getRates` has three fields that bear on this:

- `promise.deliveryWindow.end`, which the adapter already maps to
  `RateResponse::$deliveryDate`. This is the only field that says whether the offer
  arrives on time.
- `benefits.includedBenefits`, which says whether the offer is protected. The benefit is
  `OTDR_PROTECTED`. Every production capture in `.scratch/amazon-shipping-v2/` names it,
  alongside `CLAIMS_PROTECTED`.
- `benefits.excludedBenefits[].reasonCodes`, which can include `LATE_DELIVERY_RISK`.
  This is Amazon's reason for **withholding a protection**. It does not state a delivery
  date. The captures also give `NON_SSA_ORDER` and `NON_AHT_ORDER` as reasons for
  withholding `OTDR_PROTECTED`: Shipping Settings Automation and Average Handling Time
  automation are off for the seller. The adapter stored `benefits` in the Offer's
  metadata and never read it.

The absence of `LATE_DELIVERY_RISK` does not mean an offer is on time. Nor does the
absence of a protection benefit mean it is late.

## What to build

Two settings on an Amazon connection (`DataSource`). OTDR is measured per seller account,
and the Seller Central settings that make protection possible are also per account. Both
settings apply only to Amazon's own orders (`channelType: AMAZON`) in the unattended path,
meaning batch ship, auto-ship and pre-selected rates. They apply to every rate quoted for
such an order, direct-carrier rates included. A late delivery counts against OTDR however
the Label was bought, and only a Buy Shipping offer can be protected.

| Setting | Automation rejects an offer when | Default |
|---|---|---|
| **Require on-time delivery** | its delivery date is after `deliver_by`, or it has no delivery date | on |
| **Require OTDR protection** | it does not include the OTDR-protection benefit | off |

Either, both, or neither can be on:

- **Neither**: today's behaviour. On-time offers come first, and if none is on time,
  automation falls back to the cheapest late offer.
- **On-time only**: late offers are never bought, protected or not.
- **Protection only**: a protected offer is bought even when its date is late, because
  the late delivery does not count against OTDR.
- **Both**: only offers that are on time *and* protected.

Protection is off by default. With it on, a seller who lacks the Seller Central automation
settings gets no buyable offers at all, and every Amazon order goes to a person. The
setting's help text should say so.

Applying the filters:

- Apply them after approval and after the service-class filter (`15`). They only narrow
  the set of offers that are already eligible.
- When every eligible offer is rejected, the selection returns no rate and gives the
  reason: "no on-time offer", "no protected offer", or both. Batch ship and auto-ship
  then hand the Package to a person. They must not report "no rates".
- `LATE_DELIVERY_RISK` does not reject an offer under the on-time setting. It is an
  exclusion reason for the protection benefit, so the protection setting covers it.
- The Ship page shows every offer to a person, whatever the settings are. It marks each
  Amazon offer that is late, meaning after `deliver_by` or with no date, and each one that
  is unprotected, showing any exclusion reason codes. An offer whose date is on time but
  which carries `LATE_DELIVERY_RISK` shows both facts, because they disagree and the
  person should see that. `allRatesLate` already covers the case where no offer is on
  time.

Out of scope: every order not from Amazon, off-Amazon Amazon Shipping included, keeps
today's fallback. Those orders carry no OTDR penalty, and shipping late may still beat not
shipping. Changing that is a separate decision.

## Acceptance criteria

On-time setting.

- [x] With the on-time setting on, an Amazon order whose offers are all late buys nothing,
      and the batch result names lateness as the reason
- [x] With it on, an Amazon offer with no delivery date is not bought
- [x] With it on, an offer whose date is on time is bought even if it carries
      `LATE_DELIVERY_RISK`
- [x] With both settings off, an Amazon order with only late offers buys the cheapest,
      as it does today
- [x] An Amazon order with one on-time offer and one cheaper late offer buys the on-time
      one under every combination of settings that the offers pass. This already works;
      the test pins it
- [x] A direct-carrier rate that is late for a non-Amazon order is still bought when it is
      the only option, whatever the Amazon connection's settings are

Protection setting.

- [x] With only the protection setting on, a protected offer whose date is late is bought
- [x] With it on, an unprotected on-time offer is not bought, and when nothing is
      protected, the batch result names the missing protection as the reason
- [x] With both settings on, only an offer that is both on time and protected is bought

Ship page.

- [x] Each Amazon offer is marked when it is late, and when it is unprotected, with any
      exclusion reason codes shown

## Blocked by

None.

## Comments

### 2026-09-23 — shipped

> *This was generated by AI.*

- **Storage.** `data_sources.requires_on_time_offers` (default on) and
  `requires_otdr_protected_offers` (default off) are columns, so existing connections
  pick up the defaults without a backfill. Both are toggles in a new *Automated Label
  Purchase* section of the Amazon connection form.
- **Selection.** `RateSelector::selectForAutomation()` takes an `OfferRequirements`. The
  workflow builds it from the order's Amazon connection, or from the column defaults when
  that connection was deleted, and uses `OfferRequirements::none()` for every other order.
  Rates refused for lateness or for missing protection come back on
  `UnattendedRateSelection` next to the unapproved ones, and count as an attended
  alternative. A rule's pre-selected rate is now checked against the deliver-by date
  instead of `null`, so the requirements hold against it too.
- **Reporting.** Auto-ship and batch ship report *No On-Time Rates*, *No OTDR-Protected
  Rates* or *No On-Time, Protected Rates*. The message names the connection and lists any
  unapproved services as well.
- **Ship page.** Each rate carrying a `benefits` block shows an *OTDR protected* or *Not
  OTDR protected* badge, with Amazon's reasons in words beneath it.
  `BuyShippingBenefits` does the parsing, and unknown reason codes pass through as Amazon
  spelled them. Lateness is marked with ` — LATE`, as before.
- **No deliver-by date.** An Amazon import can leave `deliver_by` null, and with no
  commitment days on the shipping method there is nothing to be on time for. With on-time
  required, every rate is refused as *No Deliver-By Date* and the package goes to a
  person. Before this, `classify()` would have counted every rate as on time.
- **Not yet observed:** an offer that *includes* `OTDR_PROTECTED`. Every capture excludes
  it, because the orders were old and the test account has the automation settings off.
  The test for the included case is built from the schema.

### 2026-09-23 — settings to move

> *This was generated by AI.*

Both settings move from the Amazon connection to the shipping method in `17`, so a Prime
method and a Standard method can differ. The OTDR setting there becomes *None / Prime
orders / All Amazon orders*, and the on-time setting applies to every order on the method.
