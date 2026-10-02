# Run Amazon `getRates` read-only and record what comes back

Status: done

Repo: `polybag`

## Problem

Both ADRs partly rest on a premise nobody has tested: that Amazon Buy Shipping's `getRates`
returns offers from **several real carriers** — USPS, UPS, Amazon Shipping side by side —
rather than Amazon Shipping alone.

If it returns a single carrier, `docs/adr/0003-discovered-service-catalog-and-aliasing.md`
shrinks to almost nothing and the observation/approval structure it describes is not worth
building. Everything downstream in this directory is gated on the answer.

Amazon runs two doc sites for `/shipping/v2/`, and the Amazon Shipping (Swiship) one states
rates come only from "Amazon Shipping and partner carriers" — which is why this is a
measurement and not an assumption.

`ready-for-human` because it needs production SP-API credentials against a real seller
account, and `sandbox_mode` is a shared cross-carrier toggle that should be flipped by hand.

## Before starting

- Read-only: `getRates` quotes, it does not buy. No postage is spent.
- Amazon's shipping sandbox returns canned data whose **service IDs differ from production**,
  so a sandbox run cannot answer this. It has to be production.
- `config('services.amazon.sandbox_url')` points at the **FE** host deliberately, for the
  Orders v2026 test case. Shipping v2's sandbox cases are NA, so a sandbox attempt will 403
  until `resolveBaseUrl()` learns a per-API host — another reason to go straight to production.

## What to answer

1. **Does `channelType: AMAZON` return more than one carrier?** This is the gate.
2. **Is UPS Ground Saver among them?** Our own UPS account cannot get Ground Saver enabled —
   it is seeded but unavailable in both sandbox and production. If Amazon resells it, Buy
   Shipping reaches a service the direct account cannot, which is a second argument for the
   path independent of price.
3. Are DHL eCommerce or OnTrac offered? Both drive the "carrier we hold no row for" case.
4. **Do several distinguishable offers ever share one carrier/service pair?** This decides
   whether an opaque offer identifier is strictly necessary or merely tidy.

Capture verbatim, for at least a domestic and an international shipment:

- exact `carrierId` / `carrierName` / `serviceId` / `serviceName` per offer
- the `ineligibleRates[]` entries and their reason codes
- special-service metadata shape (`availableValueAddedServiceGroups`)
- environment and marketplace identity as returned
- `requestToken` / `rateId` and any expiry information
- `supportedDocumentSpecifications` — format, size and DPI actually on offer

## Acceptance criteria

- [ ] Raw responses captured to `.scratch/` (gitignored — they carry addresses)
- [ ] The four questions above answered in this file's `## Comments`
- [ ] A go/no-go recorded for ADR-0003's discovery structure
- [ ] `docs/adr/0003-*` moved to Accepted or revised in the app repo

## Blocked by

None — can start immediately, and blocks everything else here.

## Comments

### 2026-09-01 — sandbox probe: plumbing proven, gate question still open

Ran `getRates` against the **sandbox** with the US `AMAZON`-channel example from Amazon's
"Purchase a Shipment From a Rate" tutorial. Raw request and response saved to
`.scratch/amazon-shipping-v2/` in the app repo.

Prerequisites done first:

- **Direct-to-Consumer Shipping** added to the **Shipping** business entity in the app
  registration; it was already present under Sellers.
- Seller re-authorized through our OAuth broker.
- `services.amazon.sandbox_url` pointed at the NA host — see the caveat below.
- New `App\Http\Integrations\Amazon\Requests\GetShippingRates` (POST
  `/shipping/v2/shipments/rates`).

Result: **HTTP 200**, one rate, no ineligible rates.

```
carrierId   AMZN_US          serviceId    std-us-swa-mfn
carrierName Amazon Shipping  serviceName  Amazon Shipping Ground
totalCharge USD 4.76         billedWeight 1.0 KILOGRAM
```

**This does not answer the gate question.** One carrier came back, but this is Amazon's
*dynamic* sandbox generating a synthetic response, and Amazon's own FAQ warns sandbox
service IDs differ from production. `std-us-swa-mfn` is Amazon Shipping's own service —
exactly the ambiguity this issue exists to resolve. **A production run is still required**
before ADR-0003's discovery structure is justified.

What it does prove: the role is live (a missing role 403s), the broker token authenticates
against Shipping v2, and the response matches the published model exactly.

Structural findings — the response *shape* is real even though the values are synthetic:

1. **`supportedDocumentSpecifications` is per-rate.** PNG, ZPL and PDF, all 4x6 INCH, with
   `supportedDPIs: [300, 203]`. Maps cleanly onto the app's pdf/zpl at 203/300. PNG is
   offered and is **not** supported by our print path, so it is a real case to reject rather
   than a hypothetical. Confirms the requested document spec must be validated against the
   chosen rate, not against the carrier.
2. **`availableValueAddedServiceGroups` is per-rate** (empty here). Settles ADR-0002
   decision 8: special-service capability belongs at the offer seam, not in carrier policy.
3. **`rateId` and `requestToken` are separate opaque strings**, the rateId being 76
   characters. Confirms ADR-0002 decision 4 — neither is reconstructible from carrier and
   service.
4. **No expiry field is returned.** The documented 10-minute EXTERNAL window is not
   surfaced in the response, so expiry has to be tracked from request time on our side. See
   `02-specify-observation-and-offer-stores`.

Also: `billedWeight` came back in **KILOGRAM** for a POUND request. Unit conversion on the
response path is not optional.

`carrierId: AMZN_US` is an opaque external identifier and "Amazon Shipping" would need
normalizing to a `Carrier` row — the case ADR-0003's terminology table calls an external
identifier, now observed rather than assumed.

Caveat carried forward: pointing `sandbox_url` at NA breaks the Orders v2026 sandbox test
case, which needs the FE host for its JP marketplace ID. Filed as
`04-per-api-sandbox-host`; the change is uncommitted until that lands.

### 2026-09-02 — the sandbox does not evaluate carriers; do not repeat this experiment

The single-rate response above is a **fixed canned sandbox response**, not a result. Three
runs — two without the business-id header, one with `AmazonShipping_US`, spanning two days —
returned the **byte-for-byte identical `rateId`** `e1b7cf2d…5809431`. Only `requestToken`
and the promise windows differ, and both are clock-derived. A real eligibility evaluation
cannot return the same rate ID across runs.

Corroborating: `ineligibleRates` is empty. Amazon's own on-Amazon example returns a long
ineligible list (USPS, FedEx, UPS, DHL, China Post, 4PX, India Post and more), because
evaluating an Amazon order means testing the full carrier catalog against it. Empty there
means nothing was evaluated, not that nothing was ineligible.

**Conclusion: no amount of sandbox work can answer this issue.** Only a production `getRates`
against a real order in our seller account will.

Two things learned along the way:

- **`x-amzn-shipping-business-id` defaults to `AmazonShipping_UK` when omitted**, which is
  wrong for every marketplace we serve. `GetShippingRates` now sends `AmazonShipping_US` by
  default and takes the business as a constructor argument. This was not the cause of the
  single rate, but we were getting a plausible-looking answer through a wrong default.
- **Amazon's documented on-Amazon example returns USPS Priority Mail alongside Amazon
  Shipping Ground**, from a request using the same `AmazonShipping_US` header. The
  multi-carrier premise behind ADR-0003 is now supported by an official example rather than
  by inference — though still unverified for our account, which is what this issue exists to
  settle.
- That example's large `ineligibleRates` array, with reason codes, settles ADR-0003's open
  question about whether it is worth harvesting as a discovery surface. It is.

### 2026-09-02 — production run: **multi-carrier confirmed, GO on ADR-0003**

Ran production `getRates`, `channelType: AMAZON`, against a real order in the seller account.
Raw responses in `.scratch/amazon-shipping-v2/` (gitignored — they carry the order ID and
addresses).

**A shipped order can still be quoted.** That order's `fulfillmentStatus` is `SHIPPED` and
Amazon rated it anyway — order state is evidently enforced at purchase, not at quote. This
matters for us: the account has no fresh orders, and rate exploration does not need one.

Two runs, differing only in parcel size:

| Parcel | Eligible | Carriers | Ineligible |
|---|---|---|---|
| 33x24x15 cm, 0.5 lb | 3 | OnTrac, UPS | 105 across 14 carriers |
| 23x15x5 cm, 0.75 lb | 6 | OnTrac, UPS, USPS | 102 |

```
OnTrac  ONTRAC_MFN_GROUND           OnTrac Ground                    5.79
UPS     UPS_PTP_NEXT_DAY_AIR_SAVER  UPS Next Day Air Saver           16.06
UPS     UPS_PTP_NEXT_DAY_AIR        UPS Next Day Air                 21.94
USPS    (3x Priority Mail Express Flat Rate Envelope variants)       31.11-31.70
```

Ineligible catalog spans 14 carriers: USPS 31, Yanwen 15, UPS 14, 4PX 11, Yun Express 10,
WanB 7, China Post 4, India Post 4, Shiprocket 3, SF Express 2, Delhivery, DHL, Self Delivery.

**Findings that change the ADRs:**

1. **The multi-carrier premise holds.** Three real carriers eligible simultaneously, priced
   independently. ADR-0003's discovery structure is justified.
2. **OnTrac is eligible and cheapest** — a carrier we have no `Carrier` row for, no account
   with, and no adapter. The "carrier we hold no row for" case ADR-0003 was designed around is
   now observed rather than hypothesised.
3. **`ineligibilityReasons.code` is `UNKNOWN` for every one of the 105.** ADR-0003 currently
   says the array comes "with reason codes" and treats that as what makes it a discovery
   surface — **that is wrong and needs correcting**. There are 16 distinct human-readable
   `message` strings carrying the real information ("Expression 'L * W * H' = 11880 exceeds
   maximum 2949.67", "This shipping service does not deliver from the given source address to
   the destination address"). Discovery can use carrier/service **identity** from this array;
   it cannot branch on machine-readable reasons.
4. **Amazon normalizes to metric internally.** Dimensional limits are evaluated in cm3 against
   an INCH request, and `billedWeight` returns in KILOGRAM for a POUND request. Conversion on
   both directions is not optional.
5. **`availableValueAddedServiceGroups` varies per rate** — 0 for OnTrac, 1 for both UPS
   rates. ADR-0002 decision 8's placement of special-service capability at the offer seam is
   confirmed against real data.
6. **`benefits` is populated on eligible rates** (Buy Shipping protection). Worth recording on
   the package; it is a reason to prefer this path that has nothing to do with price.
7. **UPS Ground Saver appears in the catalog** — four variants (BPM, >=1lb, <1lb, Media),
   ineligible for this parcel. Our own UPS account cannot get Ground Saver enabled, so Buy
   Shipping does reach services the direct account cannot.
8. **The sandbox is structurally unrepresentative, not merely stale.** It returned *only*
   Amazon Shipping; production returned *no* Amazon Shipping at all. Opposite answers.

**Caveat on the eligible set.** This order is ~10 months old, so its delivery promise expired
long ago and the eligible services skew to fast and expensive — USPS appears only as Priority
Mail Express flat-rate envelopes, and no ground service qualifies. The *catalog* is
representative; the *eligibility* is not. A fresh order would be needed to see what wins on
price in normal operation, and the account has none.

Done 2026-09-02: ADR-0003's `ineligibleRates` claim corrected and the ADR moved to
**Accepted**, with the sandbox-is-unrepresentative finding and the metric-normalization
consequence folded in.
