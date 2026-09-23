# Move the on-time and OTDR requirements to the shipping method

Status: done — shipped 2026-09-23; the OTDR boxes are hidden unless an Amazon connection is active

Repo: `polybag`

## Problem

`16` put *require on-time delivery* and *require OTDR protection* on the Amazon
connection. A seller cannot then treat order types differently. For example, they cannot
have a Seller Fulfilled Prime method that insists on protection and an Amazon Standard
method that does not. The requirements describe what the seller wants bought for a kind
of order, and the kind of order is what the shipping method already stands for. All
service-selection logic should live in shipping methods, not in connection settings.

## What to build

Two fields on `ShippingMethod`, in a section of the method form about automated purchases.
The Amazon connection form loses its *Automated Label Purchase* section, and the
`data_sources.requires_on_time_offers` and `requires_otdr_protected_offers` columns are
dropped.

| Field | Values | Default |
|---|---|---|
| **Exclude rates that deliver after the due-by date** | on / off | on |
| **Require OTDR protection for** | checkboxes: *Prime orders*, *Premium orders*, *Other Amazon orders* | none ticked |

Help text, in words a seller reads:

- *Exclude rates…*: "Automated purchases skip any rate whose delivery date is after the
  shipment's due-by date, or that gives no delivery date. With this off, the cheapest
  late rate is bought when nothing arrives on time."
- *Require OTDR protection for…*: "For the Amazon orders ticked, only buy offers marked
  OTDR Protected. A protected label that arrives late does not count against your
  on-time delivery rate. Requires
  Shipping Settings Automation and Average Handling Time automation in Seller Central.
  Without them no offer is protected, and every order this applies to goes to a person."

### Semantics

- **Which orders.** The due-by field applies to **every order on the method**, not only
  Amazon orders. It is the method's speed guarantee, and it replaces the service-class
  filter `15` proposed: an Overnight method with a one-day commitment excludes a Ground
  rate because Ground arrives too late, whatever the service is called. This is
  a behaviour change for non-Amazon orders, which `16` deliberately left on the old
  fallback, so the PR must call it out. The OTDR field applies only to Amazon's own orders
  (`channelType: AMAZON`). Off-Amazon Amazon Shipping orders do not count toward the
  account's OTDR.
- **Due-by date** is `Shipment::getDeliverByDate()`: the order's `deliver_by`, otherwise
  the method's `commitment_days`. With neither, no rate can be shown to be late. An Amazon
  order in that state is left for a person, as `16` does today. Any other order excludes
  nothing, because there is no deadline to miss.
- **Which box applies** comes from the Shipment's `amazon_programs`, as `14` maps them:
  *Prime orders* for `PRIME`, *Premium orders* for `PREMIUM`, and *Other Amazon orders*
  for everything else, including an order imported before `14`, which has no
  `amazon_programs`. Prime and Premium are separate programs (see `14`), so a seller who
  runs only one of them ticks only that box. An order in both is covered if either box
  is ticked.
- **Direct-carrier rates** can never be protected. With protection required, the order is
  bought through Amazon only. For Prime that is intended: Seller Fulfilled Prime requires
  Buy Shipping.
- Everything else from `16` stands: the requirements narrow the eligible rates after
  approval and never make an ineligible rate eligible. A refusal is reported by name.
  The Ship page shows every rate to a person with its lateness and protection marked.
  Refusal messages name the **shipping method** where they named the connection.

### Migration

- Existing methods get the defaults: due-by exclusion on, no OTDR boxes ticked.
- A connection that had *require OTDR protection* on cannot be mapped to methods
  automatically, because a method serves several connections. The migration logs each
  one, and the PR notes that the seller must set it on the relevant methods.
- `OfferRequirements` is built from the Shipment's method and, for the OTDR part, its
  programs. With no method, nothing is required.

## Decided

- ***Other Amazon orders* is usable.** `14` decided 2026-09-23 to assume ordinary orders
  are offered OTDR protection. Ship the option with no caveat in its help text.
- **`FBM_SHIP_PLUS` is not Prime** (`14`, 2026-09-23). It is a separate program for
  shipments from China, so it falls under *Other Amazon orders*. Read programs through
  `AmazonOrderProgram::forShipment()`, which already leaves it unmapped.

## Acceptance criteria

- [x] With only *Prime orders* ticked, a method refuses an unprotected offer for a
      `PRIME` order, and buys the cheapest unprotected offer for a `PREMIUM` order and
      for an ordinary Amazon order
- [x] With all three ticked, unprotected offers are refused for every Amazon order and a
      Shopify order is unaffected
- [x] With due-by exclusion on, a Shopify order on a method with `commitment_days: 1`
      does not buy a rate delivering in three days, and is left for a person
- [x] With due-by exclusion on and no due-by date, a Shopify order still buys the
      cheapest rate, and an Amazon order is left for a person
- [x] With due-by exclusion off, the cheapest late rate is bought, as before
- [x] Refusal messages name the shipping method
- [x] The connection form no longer shows either setting, and the columns are gone

## Also decided

- **The OTDR boxes appear only while an Amazon connection is active** (2026-09-23).
  Protection is a Buy Shipping benefit, so without a connection to buy through the choice
  means nothing. Hidden, the field is not saved, so a method keeps what was ticked and
  it applies again if the connection is reactivated. The due-by toggle always shows,
  because it applies to every order.

## Blocked by

None. [`14`](14-prefer-otdr-protected-amazon-offers.md), which records `amazon_programs`,
shipped 2026-09-23.
