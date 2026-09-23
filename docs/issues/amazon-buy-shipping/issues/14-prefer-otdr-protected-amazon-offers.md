# Record Amazon order programs, and match rules on them

Status: done — shipped 2026-09-23; both open questions answered by assumption, see *Decided*

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

**Prime and Premium are different programs.** Prime requires shipping to the whole
contiguous 48 states, with no charge to the buyer for shipping. Premium shipping lets a
seller offer one-day or two-day delivery to regions of their choosing, such as two-day to
western Washington only, and charge for it. A seller can run either without the other, so
PolyBag keeps them apart rather than folding Premium into Prime.

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

Where things stand:

- `getRates` returns a `benefits` block on each rate. `16` parses it
  (`BuyShippingBenefits`): the benefit is `OTDR_PROTECTED`, withheld with reasons such as
  `LATE_DELIVERY_RISK`, `NON_SSA_ORDER` and `NON_AHT_ORDER`. The Ship page badges it.
- `16` added *require OTDR protection* on the Amazon connection. `17` moves it to the
  shipping method and lets it apply to Prime orders only, which needs the order's
  programs.
- The import does not read the order's `programs`, so a Prime or Premium order looks the
  same as any other Amazon order. Only `fulfillmentServiceLevel` and `deliverByWindow`
  come in (`AmazonSource`).

The file name predates the 2026-09-23 rewrite. It is kept so existing links still work.

## Scope

Two things, both about knowing what kind of Amazon order a Shipment is:

1. **Import the order's programs.** `17` needs them to require protection on Prime or
   Premium orders only.
2. **A shipping-rule condition on them**, for sellers who want "if the order is Prime,
   use this service" rather than a method-wide requirement.

Automated selection itself is `17`. This issue only records and exposes the programs.

## Where `programs` lives

Answered 2026-09-23. In Orders v2026-01-01 `programs` is on the **order**, and its values
are `AMAZON_BAZAAR`, `AMAZON_BUSINESS`, `AMAZON_EASY_SHIP`, `AMAZON_HAUL`,
`DELIVERY_BY_AMAZON`, `FBM_SHIP_PLUS`, `INVOICE_BY_AMAZON`, `IN_STORE_PICK_UP`,
`PREMIUM`, `PREORDER` and `PRIME`. Order items carry a separate `programs` list,
`TRANSPARENCY` and `SUBSCRIBE_AND_SAVE`, which says nothing about delivery and is not
needed here. The only fixture we hold is v0 (`IsPrime`, item-level `AmazonPrograms`), so
build a v2026 fixture from the JSON model rather than from the docs. The docs have been
wrong about field locations in this version before.

## What to build

- **Import.** `AmazonSource` writes the order-level list, as Amazon spells it, to the
  Shipment's metadata as `amazon_programs`, next to `amazon_order_id`. An order with no
  programs stores `[]`, so "imported with none" can be told apart from "imported before
  this existed". Re-imports refresh it like the other Amazon metadata.
  - Stored as metadata, not a column. Everything that reads it works on one Shipment at a
    time: rule evaluation, rate selection, the Ship page. A Shipments-table filter, if
    one is wanted later, can use `whereJsonContains`. A cross-channel `is_prime` column
    would be premature, since only Amazon has programs.
- **Meaning.** One place maps program codes to the programs the app distinguishes, so
  nobody types codes into configuration: *Prime* is `PRIME`, *Premium* is `PREMIUM`.
  `FBM_SHIP_PLUS` maps to neither (see *Decided*).
- **Rule condition.** `RuleEvaluator` gains an *Amazon program* condition, *is Prime* or
  *is Premium*, beside `channel`, `residential` and the rest, with its field in the
  Shipping Rule form. A non-Amazon Shipment never matches it.
- **Visible.** The Pack and Ship pages show a *Prime* or *Premium* badge on such an
  order, where the packer can see why it is being treated differently.

## Decided

Both were open questions until 2026-09-23, when they were settled by decision rather than
by capture:

1. **OTDR protection is assumed to be offered on ordinary orders**, not only Prime. The
   exclusion reasons seen so far, `NON_SSA_ORDER` and `NON_AHT_ORDER`, are seller settings
   rather than programs, which points the same way. `17`'s *Other Amazon orders* option is
   therefore treated as usable. A `getRates` capture comparing a Prime and an ordinary
   order, with both Seller Central automation settings on, would still confirm it.
2. **`FBM_SHIP_PLUS` is not Prime.** It is a separate program, for shipments from China,
   and nothing is done with it. The code is stored in `amazon_programs` as Amazon sends
   it, but maps to no program, matches no rule and shows no badge.

## Rejected

- **Preferring a protected offer on ordinary orders, within a price tolerance.** The
  earlier draft of this issue proposed two tiers: *require* on Prime, *prefer within a
  tolerance* elsewhere. Rejected 2026-09-23. Most sellers want the cheapest protected
  offer bought automatically, which `17`'s *All* option gives without a tolerance to
  configure.

## Out of scope

- A shipping rule whose *action* names a discovered Amazon service, such as "if Prime,
  use OnTrac Ground", when OnTrac is not in the catalog. Rule actions name a
  `CarrierService` today. `18` loosens approval, not rule targets; this needs its own
  issue if sellers ask for it.

## Acceptance criteria

- [x] A v2026 order with `programs: ["PRIME"]` imports with `amazon_programs` set to
      `["PRIME"]`, and one with none imports with `[]`
- [x] Item-level `programs` are not merged into the order's list
- [x] A shipping rule with *is Prime* matches a `PRIME` order and not a `PREMIUM` one;
      *is Premium* the reverse. Neither matches an ordinary Amazon order or a Shopify
      order
- [x] The Ship page shows the Prime badge on a Prime order and the Premium badge on a
      Premium order, and neither on any other

## Blocked by

None.

## Comments

### 2026-09-23 — shipped

> *This was generated by AI.*

- **Fixture.** `orders_2026-01-01.json` is now vendored in `tests/Fixtures/Schemas/`,
  unmodified, and the test orders in `AmazonImportExportTest` are validated against its
  `Order` schema. That confirmed `programs` is an order-level string array and needs no
  `includedData` value, so the import request is unchanged.
- **Import.** `AmazonSource::mapOrderToShipment()` writes `amazon_programs`: the
  order-level list, strings only, as Amazon spells them, `[]` when absent. Item-level
  `programs` are ignored. Re-imports already replace the Shipment's metadata, so the list
  refreshes with no extra code.
- **Meaning.** `App\Enums\AmazonOrderProgram` (`Prime`, `Premium`) holds the code map
  (`codes()`), the label and badge colour, and `forShipment()` / `appliesTo()`. A
  Shipment with no `amazon_programs`, whether from another channel or imported before
  this, has no programs. `17` should read programs through it rather than the metadata.
- **Rule condition.** `amazon_program` with `data.program` of `prime` or `premium`, in
  `RuleEvaluator` and as an *Amazon Program* block in the Shipping Rule form, summarised
  as *Amazon Prime* / *Amazon Premium* in the rules table. Like the other conditions, a
  malformed one without a program passes; the form requires the field.
- **Visible.** A badge beside the client badge on Pack, and in the *Package Details*
  header on Ship.
