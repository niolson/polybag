# Decide whether `getRates` takes a constraint instead of a service-code list

Status: needs-triage

Repo: `polybag`

## Problem

`CarrierAdapterInterface::getRates(RateRequest $request, array $serviceCodes)` bakes in "the
caller knows the service codes up front". That assumption held while every offer came from a
direct carrier with a static published catalog.

It is already a fiction for Shopify, where the only codes the adapter can filter on are ones we
invented and seeded ourselves. For Amazon it is worse: the catalog is per-shipment, and the
production run returned 102 services we had never heard of across fourteen carriers.

ADR-0003 says the parameter "wants to become a constraint — class, allowlist, price ceiling —
rather than an enumerated list", and explicitly defers the decision because Amazon can filter
after the quote instead of before.

## What to answer

1. Is post-quote filtering good enough for Amazon in practice, given `getRates` is one call and
   rate limits are 80 rps?
2. Would a constraint object simplify the Shopify case or just move the fiction?
3. What does it cost the three direct-carrier adapters, which are correct as they stand?

`needs-triage` because the answer may well be "leave it alone" — this is a design question, not
agreed work, and it touches every adapter.

## Blocked by

Nothing any more. The gate below is discharged.

~~None, but do not act on it before `03-amazon-buy-shipping-adapter` shows whether post-quote
filtering is actually awkward.~~

**2026-09-07:** `03` shipped 2026-09-05, and post-quote filtering — the option this issue
names as the alternative to a constraint object — is what it shipped. The evidence this
issue was waiting for therefore exists, and question 1 can be answered from the adapter as
built rather than in the abstract. Still `needs-triage`: the answer may well be "leave it
alone", and it touches every adapter.
