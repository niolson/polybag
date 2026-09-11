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

The argument is real but belongs to the **query-root** `fulfillmentOrders` connection,
which is what `ShopifySource::FULFILLMENT_ORDERS_QUERY` uses and why the import always
worked.

So `fulfillableFulfillmentOrders()` threw on every call, `repointFulfillmentOrder()` caught
it, logged a warning and returned — meaning **`18`'s fix had never once run against the
live API**. The re-point, the clear-when-nothing-is-fulfillable branch and the
goods-fingerprint filter had all only ever executed in the test suite.

## Why it went unseen

Both reasons are worth keeping.

**The import is a working backstop.** `ShopifyFulfillmentOrderRepointer` re-points on the
next import run, as `repointFulfillmentOrder()`'s own docblock says it will. Verified end
to end on one shipment: after the void the import moved it onto the replacement, carried
`source_record_id` with it, and created no duplicate. The visible behaviour was correct on
a fifteen-minute delay, so nothing surfaced.

**Mocks validate no arguments.** Six tests covered the re-point, all against `MockResponse`
payloads. A faked Shopify accepts any query text at all, so the one thing that was wrong is
the one thing the suite could not see — the same class of defect
`carrier-request-schema-validation` exists for, one API over.

## What shipped

The argument is gone. `supportedActions` carrying `CREATE_FULFILLMENT` was always the real
filter and is strictly better than status: a fulfillment order can be open and still
unfulfillable (on hold, or assigned to a third-party fulfillment service), and a closed one
carries no supported actions at all. Closed fulfillment orders now come back with the rest
and are filtered client-side, and `first: 20` became `first: 50` because they count toward
the page and an order accumulates one on every void. `hasNextPage` still means "no answer".

Verified against the live API: the query returns the closed fulfillment order with `[]`
actions and the open one with `[CREATE_FULFILLMENT, …]`, and `fulfillableFulfillmentOrders()`
returns exactly one candidate whose goods fingerprint matches the shipment's.

**The test asserts the query text**, not the response — no `includeClosed`,
`supportedActions` present. Asserting the request is the only thing a mocked transport can
do about an argument the server would have rejected.

## Related

- `18` — the re-point this silently disabled; correct now for the first time
- `21` — the import-side re-point, which is why nothing user-visible broke
- `carrier-request-schema-validation` — the same class of defect, for USPS and FedEx
