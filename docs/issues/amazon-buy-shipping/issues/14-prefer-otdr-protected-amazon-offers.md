# Prefer OTDR-protected offers for Amazon orders

Status: needs-triage

Repo: `polybag`

## Problem

Amazon measures on-time delivery rate (OTDR) on every order a seller fulfills, not only
Prime ones. The target for ordinary seller-fulfilled orders is 90%. The rate decides
whether the seller is eligible for Premium and Seller Fulfilled Prime shipping, and
probably affects who wins the Buy Box. That last point is our inference; Amazon does not
publish its Buy Box inputs.

Seller Fulfilled Prime sets a higher bar, measured weekly: OTDR at or above 93.5%, valid
tracking at 99%, cancellations at or below 0.5%, and at least 40% of deliveries within one
day and 75% within two. Missing them costs the seller Prime eligibility.

Buy Shipping offers two separate protections on the Labels it sells
([Amazon's help page](https://sellercentral.amazon.com/help/hub/reference/GB2FHL2QMQ5NT397)):

- **Claims Protection**, for claims against a Label bought through Buy Shipping.
- **OTDR Protection**: a late delivery does not count against OTDR. It needs Shipping
  Settings Automation and Average Handling Time automation turned on in Seller Central,
  a Label marked "OTDR Protected", and the Package shipped on time.

Only the Label is in PolyBag's hands. The two automation settings are the seller's, in
Seller Central, and shipping on time depends on the warehouse. A seller without the
automation settings gets no OTDR protection from any Label, so the Ship page should not
suggest otherwise.

PolyBag ignores all of this today:

- `getRates` returns a `benefits` block on each rate, with `includedBenefits` and
  `excludedBenefits`, and each exclusion carries `reasonCodes`. The adapter stores that
  block on the Offer (`AmazonBuyShippingAdapter::rateMetadata()`), and nothing reads it
  back.
- `RateSelector::selectForAutomation()` chooses the cheapest on-time rate. An unprotected
  offer a few cents cheaper wins over a protected one, and nothing on the Ship page shows
  the packer the difference.
- The import does not read the order's `programs`, so a Prime or Premium order looks the
  same as any other Amazon order. Only `fulfillmentServiceLevel` and `deliverByWindow`
  come in (`AmazonSource`).

## Scope

All of Amazon's own orders (`channelType: AMAZON`). Protection is a property of the
*offer*, and the order's programs decide only how strongly to weigh it. There are two
tiers:

| Tier | Orders | Why |
|---|---|---|
| Delivery-metric | `PRIME`, `PREMIUM`, and possibly `FBM_SHIP_PLUS` | the weekly SFP metrics; losing them loses the program |
| Standard | every other Amazon order | the 90% OTDR target, Premium and Prime eligibility, and probably the Buy Box |

Off-Amazon Amazon Shipping Labels (`amazon-shipping-external-orders`) are out of scope:
those orders are not on Amazon, so their deliveries do not count toward the account's
OTDR.

## What is not known yet

1. **What string "OTDR Protected" is in the API.** The Shipping v2 model gives
   `CLAIMS_PROTECTED` as an example benefit and `LATE_DELIVERY_RISK` as an example
   exclusion reason. It does not say whether OTDR protection is a benefit of its own,
   is `CLAIMS_PROTECTED`, or is shown some other way. Answer it with production
   `getRates` calls, capturing the responses in `.scratch/`. The sandbox will not do:
   ADR-0003 found it structurally unrepresentative.
2. **Whether OTDR Protection applies to ordinary orders, or only Prime.** The help page
   describes it for Buy Shipping Labels generally; Amazon's Seller Fulfilled Prime text
   describes it for Prime offers. Capture one Prime order and one ordinary order, and
   compare their `benefits`. If ordinary orders only ever get Claims Protection, the
   standard tier weighs that instead, or nothing.
3. **Where `programs` sits in Orders v2026-01-01.** The values seen are `AMAZON_BAZAAR`,
   `AMAZON_BUSINESS`, `AMAZON_EASY_SHIP`, `AMAZON_HAUL`, `DELIVERY_BY_AMAZON`,
   `FBM_SHIP_PLUS`, `INVOICE_BY_AMAZON`, `IN_STORE_PICK_UP`, `PREMIUM`, `PREORDER` and
   `PRIME`. Check the JSON model for whether the list is order-level or item-level. The
   docs have been wrong about field locations in this version before. The only fixture we
   hold is v0, where the field is `IsPrime` and item-level `AmazonPrograms`.
4. **Whether `FBM_SHIP_PLUS` belongs in the delivery-metric tier.** The v0 model says it
   includes late-delivery protection, but its rules may differ from SFP's.
5. **Policy for each tier.** A reasonable default, for triage to confirm:
   - Delivery-metric orders **require** a protected offer. If none arrives on time, the
     Package goes to a person rather than being bought unprotected.
   - Standard orders **prefer** a protected offer unless it costs more than a per-client
     tolerance over the cheapest eligible offer.

   Whether the tolerance is a fixed amount, a percentage, or configurable at all is part
   of the question.

## Likely shape

Settle the questions above before building this.

- **Import:** record the order's programs next to `amazon_fulfillment_service_level` in
  the Shipment's metadata, and derive the tier from them.
- **Offers:** parse `benefits` into a typed value on `RateResponse` rather than leaving it
  as metadata, so the selector and the Ship page read the same fact.
- **Automation:** apply the tier's policy after the service-class filter (`15`) and after
  approval. Protection chooses between offers that are already eligible, and never makes
  an ineligible one eligible.
- **Ship page:** show protection on each Amazon offer. Where an offer lists it as
  excluded, show the reason code, such as `LATE_DELIVERY_RISK`. Sort protected offers
  first on delivery-metric orders.

Late offers are handled separately, in `16`: an offer that will miss `deliver_by` or
names `LATE_DELIVERY_RISK` is a question of arriving on time, not of protection, and does
not wait on these questions.

## Blocked by

Nothing for questions 1–4. Building waits on their answers, and the automation step waits
on `15`.
