# A zero-value customs item fails the purchase, or quietly understates the declaration

Status: done — 2026-09-11

Repo: `polybag`

## Problem

`CustomsItem::fromPackageItem()` takes `unitValue` from `shipment_items.value` with a
`?? 1` fallback. That guards a missing row and a null value. It does **not** guard an
explicit **zero**, and zero is a value the import writes on purpose: `ShopifySource` maps
each line to Shopify's unit price with a default of `0`, not null.

So a Shopify order containing a **free gift line** — a promotion, a warranty replacement, a
sample — produces a customs item worth `0.00`, and the fallback never fires because the
value is not null. A missing price field lands on the same `0` the same way.

Two failures follow, and they are not equally visible.

**1. Every item free — the purchase fails after the box is taped shut.**
`UpsAdapter::buildShipInvoiceLineTotal()` sums the items and sends the total; a sum of zero
is refused with `120502 — InvoiceLineTotal MonetaryValue must be greater than 0`. Same
error `24` hit for a different reason, arriving at the worst moment and naming a field the
operator has never heard of.

**2. One free line among paid ones — the invoice is wrong and nothing says so.** The sum is
positive, so the purchase succeeds and the commercial invoice declares that line at `$0`.
UPS's schema says `Product.Unit.Value` "should be greater than zero", so the line is out of
contract — and more to the point, a customs declaration understating an item is a false
declaration made on the shipper's behalf. It prints, it goes in the pouch, and nothing in
PolyBag flags it.

The second is worse in kind and the first is worse in timing.

## Not covered by the existing guard

`MissingDeclaredValueException` looks adjacent and is not: it fires only when the operator
selects the `declared_value` special service and no amount can be derived, which is
insurance rather than customs. Nothing checks the values that go onto a customs invoice.
Its *shape* is the right precedent though — a typed exception caught in the workflow and
turned into a titled operator-facing message rather than a carrier error code.

## Observed and not observed

The mechanism is read off the code and confirmed against UPS's live `120502`. **It has not
been seen in this data**: all 7,533 `shipment_items` rows carry a positive value, none null
and none zero. So this is reachable-by-data rather than currently happening — an argument
about urgency, not about whether it is real. A single free-gift promotion on a connected
store creates it. Worth checking against a store that actually runs promotions before
deciding how far up the queue it goes.

## What to decide

The detect half is not in question: **no purchase should be attempted with a customs item
worth zero**, and the operator should be told which item and why, before the label is
bought rather than after. What to do next is a policy question:

1. **Withhold and hand it back**, the way `19` does for a weight that cannot be honoured —
   name the item, refuse, let the operator fix the value at source. **Recommended.**
2. **Substitute a nominal value** — `$1`, or a per-client default, the convention for goods
   of no commercial value. Cheaper for the operator, but PolyBag would be writing a number
   onto a legal customs declaration that nobody entered. That is a different class of act
   from scaling a weight, and should not be done silently if it is done at all.
3. **Let the operator enter a value at ship time**, like the customs-weight override — the
   remedy stays with the person who can see the goods.

`19` established the discipline option 2 runs against: PolyBag does not over-declare to get
a box out the door, and the remedy lives with whoever owns the catalogue.

Whichever is chosen, **the per-line case needs the same answer as the all-free case.** A
guard that only checks the sum lets the quiet, worse failure through.

## Scope beyond UPS

`120502` is UPS's. Whether USPS and FedEx refuse a zero-value customs line, accept it, or
print it as `$0.00` is **unknown and untested** — all three build declarations from the same
`CustomsItem` list, so whatever is decided belongs in front of the adapters rather than
inside `UpsAdapter`. Shopify is unaffected: it builds its declaration from its own catalogue
and never sees ours (`19`).

## Test notes

- A package whose customs items are all zero-valued does not reach the carrier
- A package with one zero-valued line among positive ones is treated the same way, not
  allowed through on a positive sum
- The null and missing-row cases still fall back to `1` — that fallback predates this
- Whatever the operator sees names the offending item, not the carrier's field name

## Comments

**2026-09-11 — option 1, withheld before the purchase.** `ShipRequest::zeroValueCustomsItems()`
returns the lines at `unitValue <= 0`, and `EloquentPackageShippingWorkflow::ship()` throws
`ZeroValueCustomsItemException` on any of them, caught into a `Customs Value Required`
result the way `MissingDeclaredValueException` is. The message names the offending lines by
their customs description and says to set the value on the shipment item; the carrier's
field name never appears.

Where it sits and why:

- **Ahead of the customs-weight prompt**, not after it. There is no override for a missing
  value, so confirming a weight and then being refused anyway would be the worse order.
- **Ahead of the offer claim**, with everything else that can fail locally, so the offer
  stays spendable for the retry.
- **Only where a declaration is sent**, asked of the address *pair*
  (`sharesCustomsZoneWith`), not the destination alone — a Canadian location shipping
  into Canada declares nothing and one shipping into Oregon declares everything, which
  `requiresCustomsDeclaration()` gets backwards from a non-US origin. A blind purchase
  sends none of ours, so the check is skipped rather than refusing over an array nobody
  reads (same reasoning as the weight override in `19`).
- **Per line, not on the sum**, so the quiet understatement is refused along with the
  loud `120502`.
- **In front of the adapters**, so USPS and FedEx are covered without ever finding out what
  they do with a zero.

The `?? 1` fallback for a null value is untouched; the test pins that.

Not `leavePackageIntact`: on Manual Ship the package was built from the form and the
operator fixes the value there, so cleaning it up is right, and the attended paths never
clean up anyway. Same as `MissingDeclaredValueException`.

**2026-09-11 — the customs-weight prompt had the same blind spot.**
`requiresCustomsWeightOverride()` gated on the destination alone too, so a Canadian
location would have been prompted over a Canadian parcel and not over one into the US.
Switched to the same pair check in the same PR; two tests pin it.
