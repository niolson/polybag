# Never auto-buy an Amazon offer that will miss the delivery date

Status: ready-for-agent

Repo: `polybag`

## Problem

Amazon's help page for Buy Shipping protections says offers that carry late-delivery risk
are filtered out before they reach the seller. That stopped being true in practice on
2026-08-10: a
[Seller Forums thread](https://sellercentral.amazon.com/seller-forums/discussions/t/f166290f-b9c5-44cc-89e7-81338551f39a)
reports that since then Buy Shipping integrations can buy services that are not expected
to meet the delivery date. The problem was confirmed by an order-management vendor and was
unresolved when checked on 2026-09-23. Amazon may restore the filtering, or may not.
PolyBag must not depend on either.

PolyBag does not check this itself. `RateSelector::classify()` sorts on-time rates first
and late rates after them, and `selectForAutomation()` takes the first. When no offer
arrives by `Shipment::getDeliverByDate()`, **automation buys the cheapest late offer**.
Until 2026-08-10 no late offer ever came back from Amazon, so that fallback did not matter
on this path. Now, for an Amazon order it buys a Label that is expected to miss the date
the buyer was promised. The late delivery counts against OTDR, and the Label cannot be
OTDR-protected.

`getRates` gives two signals, and they may disagree:

- `promise.deliveryWindow.end`, which the adapter already maps to
  `RateResponse::$deliveryDate`
- an `excludedBenefits` entry with reason code `LATE_DELIVERY_RISK`, which the adapter
  stores in the Offer's metadata and never reads

## What to build

For Amazon's own orders (`channelType: AMAZON`), in the unattended path:

- An offer is **late** when its delivery date is after `deliver_by`, when it has no
  delivery date, or when any `excludedBenefits` entry names `LATE_DELIVERY_RISK`. Take
  either signal as enough.
- Automation never buys a late offer. If every eligible offer is late, the selection
  returns no rate and says why, so batch ship and auto-ship hand the Package to a person.
  They must not report "no rates".
- The Ship page keeps late offers visible for a person to choose, marked as late, with the
  reason. `allRatesLate` already covers the case where no offer is on time. This adds
  marking for each offer, including the `LATE_DELIVERY_RISK` case where the promised date
  looks fine.

Out of scope: direct carriers and off-Amazon orders keep today's fallback. They carry no
OTDR penalty, and shipping late may still beat not shipping. Changing that is a separate
decision.

## Acceptance criteria

- [ ] An Amazon order with one on-time and one cheaper late offer buys the on-time one
      (this already works; the test pins it)
- [ ] An Amazon order whose offers are all late buys nothing, and the batch result names
      lateness as the reason
- [ ] An offer whose promised date is on time but which carries `LATE_DELIVERY_RISK` is
      treated as late
- [ ] An Amazon offer with no delivery date is treated as late
- [ ] A direct-carrier rate that is late for a non-Amazon order is still bought when it is
      the only option
- [ ] The Ship page marks each late Amazon offer and shows the reason

## Blocked by

None. It does not wait on `14`'s questions: arriving on time is a separate question from
being protected.
