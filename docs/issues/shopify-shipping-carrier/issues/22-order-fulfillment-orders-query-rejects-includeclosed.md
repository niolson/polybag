# The void re-point query is invalid, so no void has ever re-pointed a shipment

Status: done — 2026-09-09

Repo: `polybag`

## Problem

`ShopifyShippingLabelService::ORDER_FULFILLMENT_ORDERS_QUERY` asked
`Order.fulfillmentOrders` for `includeClosed: false`. That connection does not take the
argument, and Shopify rejects the whole query:

```
Shopify GraphQL error: Field 'fulfillmentOrders' doesn't accept argument 'includeClosed'
```

Introspecting `Order.fulfillmentOrders` on 2026-07 gives `displayable`, `first`, `after`,
`last`, `before`, `reverse`, `query` — and no `includeClosed`. The argument is real, but it
belongs to the **query-root** `fulfillmentOrders` connection, which is what
`ShopifySource::FULFILLMENT_ORDERS_QUERY` uses and why the import has always worked.

So `fulfillableFulfillmentOrders()` threw on every call. In
`ShopifyFulfillmentSynchronizer::repointFulfillmentOrder()` that lands in the catch, logs a
warning and returns, which means **`18`'s fix has never once run against the live API**.
Every void since it shipped left the shipment naming the fulfillment order the void closed,
and the branch that clears the stored ID when nothing is fulfillable never ran either.

Observed on two packages before it was noticed:

```
[2026-09-09 21:15:01] local.WARNING: Could not re-resolve the Shopify fulfillment order after a void
  {"package_id":209,"shipment_id":6771,"error":"Field 'fulfillmentOrders' doesn't accept argument 'includeClosed'"}
[2026-09-09 21:39:50] local.WARNING: Could not re-resolve the Shopify fulfillment order after a void
  {"package_id":210,"shipment_id":6763,"error":"Field 'fulfillmentOrders' doesn't accept argument 'includeClosed'"}
```

## Why it went unseen

Two reasons, and both are worth keeping.

**The import is a working backstop.** `repointFulfillmentOrder()`'s docblock already says a
failure to ask is not fatal because `ShopifyFulfillmentOrderRepointer` re-points on the next
import run — and it does. Verified end to end on shipment 6763: after the void, the import
moved it from the closed `…672022` onto the replacement `…280086`, carried `source_record_id`
with it, and created no duplicate. The visible behaviour was therefore correct on a
fifteen-minute-to-next-import delay, and nothing surfaced.

**Mocks validate no arguments.** `ShopifyFulfillmentSynchronizerTest` covers the re-point in
six tests, all against `MockResponse` payloads. A faked Shopify accepts any query text at
all, so the one thing that was wrong is the one thing the suite could not see. This is the
same class of defect `carrier-request-schema-validation` exists for, one API over.

## Fix

Drop the argument. `supportedActions` carrying `CREATE_FULFILLMENT` was always the real
filter — the docblock said so — and it is strictly better than status: a fulfillment order
can be open and still unfulfillable (on hold, or assigned to a third-party fulfillment
service), and a closed one carries no supported actions at all. Closed fulfillment orders now
come back with the rest and are filtered client-side.

`first: 20` → `first: 50`, because closed fulfillment orders now count toward the page and an
order accumulates one on every void. `hasNextPage` still means "no answer", unchanged.

Verified against the live API on order `6980047995094`: the query returns the closed
`…672022` with `[]` actions and the open `…280086` with `[CREATE_FULFILLMENT, …]`, and
`fulfillableFulfillmentOrders()` now returns exactly one candidate whose goods fingerprint
matches the shipment's.

## Test

`it('asks the order for its fulfillment orders in a shape Shopify accepts')` asserts the
**query text** rather than the response — no `includeClosed`, and `supportedActions` present.
Asserting the request is the only thing a mocked transport can do about an argument the
server would have rejected.

## Related

- `18` — the re-point this silently disabled; its behaviour is correct now for the first time
- `21` — the import-side re-point, which is why nothing user-visible broke
- `19` — found while verifying a void taken during its testing
- `carrier-request-schema-validation` — the same class of defect, for USPS and FedEx
