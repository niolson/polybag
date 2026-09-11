# Consider a FULFILLMENTS_UPDATE webhook instead of polling for voids

Status: wontfix — 2026-09-08

Repo: `polybag`

## Problem

`packages:sync-shopify-fulfillments` runs every 15 minutes, reads the fulfillment behind
each live Shopify package, and un-ships it when Shopify reports `LABEL_VOIDED` or
`CANCELLED`. A `FULFILLMENTS_UPDATE` webhook would cut that window to seconds. There is no
label-specific topic.

Polling was chosen deliberately: a webhook needs a publicly reachable callback URL, and an
on-prem install may not have one.

## Why it is closed

**The poll is observed working**, so this is latency alone, not a correctness gap. Two
labels voided in the Shopify admin were detected on the next scheduled run — twelve and
sixteen minutes later — and both packages returned to unshipped correctly (`01`).

What a webhook buys is the window in which the only harm is a packer reprinting a label
they themselves voided minutes earlier. What it costs is a second path beside one that
already works: HMAC verification, registration and re-registration, replay handling, and —
because Shopify webhooks are at-least-once and can be dropped — the poll stays as a
backstop anyway. It can only ever be a hosted-tenant feature, so on-prem keeps polling
regardless and both paths need maintaining.

**Reopen when** somebody reports the 15-minute window actually costing them something.
That is a real trigger, not a formality — this is deferred, not judged worthless.

## Comments

- **2026-09-08** — polling confirmed working end to end (`01`), which reduced this to a
  latency question.
- **2026-09-08** — closed `wontfix` in the sequencing pass, taking this issue's own
  recommendation, with the reopen trigger named above.
