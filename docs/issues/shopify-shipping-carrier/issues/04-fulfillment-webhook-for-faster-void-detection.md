# Consider a FULFILLMENTS_UPDATE webhook instead of polling for voids

Status: wontfix — 2026-09-08

Repo: `polybag`

## Current behavior

`packages:sync-shopify-voids` runs every 15 minutes, reads the fulfillment behind each
live Shopify package, and un-ships it via `clearShipping()` when Shopify reports
`LABEL_VOIDED` or `CANCELLED`. It works and is tested.

Polling was chosen deliberately: a webhook needs a publicly reachable callback URL, and
an on-prem install may not have one.

## What a webhook would buy

Latency, and nothing else. `FULFILLMENTS_UPDATE` exists as a webhook topic (there is no
label-specific topic), so a voided label would reach PolyBag in seconds instead of
within 15 minutes.

## The decision

Whether that latency matters. A voided label is not an emergency — the package sits in
the shipped queue with a dead tracking number until the next poll, and a packer who
voided it in Shopify knows what they did. Fifteen minutes is very likely fine.

Against it:

- Needs a public callback URL, so it can only ever be a hosted-tenant feature. On-prem
  keeps polling regardless, which means **both** paths exist and both need maintaining.
- Needs HMAC verification, webhook registration and re-registration, and replay
  handling.
- Shopify webhooks are at-least-once and can be dropped, so the poll stays as a backstop
  anyway. The webhook is an optimisation on top, never a replacement.

Recommendation: leave it until somebody complains about the 15-minute window. The cost
work in `05` is worth more, since it is the only route to real postage numbers.

## Comments

### 2026-09-08 — the polling path is confirmed working, so this is latency only

Two labels voided in the Shopify admin were detected by the scheduled
`packages:sync-shopify-fulfillments` run and both packages returned to unshipped, twelve
and sixteen minutes after the void. Details in `01`.

So nothing here is a correctness gap: the 15-minute poll finds voids. What a webhook buys
is the window between the void and the next run, during which PolyBag believes a package
is shipped and a packer could reprint a dead label. Triage this on how much that window
actually costs, not on whether polling works.

### 2026-09-08 — triaged: not now, and the trigger to reopen is named

Closed as `wontfix` in the sequencing pass, taking the recommendation this issue already
made rather than deciding anything new. Nothing here is a correctness gap: the poll is
observed working end to end, so what a webhook buys is latency alone.

The cost is the part that settles it. A webhook can only ever be a hosted-tenant feature,
so it does not replace the poll — it adds a second path beside one that already works,
with HMAC verification, registration and replay handling attached, for a window in which
the only harm is a packer reprinting a label they themselves voided minutes earlier.

**Reopen when** somebody reports the 15-minute window actually costing them something. That
is a real trigger, not a formality — this is deferred, not judged worthless.
