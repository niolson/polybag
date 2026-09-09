# Every Shopify-bought package lands a permanent export failure

Status: ready-for-agent

Repo: `polybag`

## Problem

`ShopifySource::exportPackage()` swallows one error message and one only:

```php
$allPermanent = collect($userErrors)->every(fn (array $error): bool => str_contains(
    strtolower((string) ($error['message'] ?? '')),
    'already fulfilled',
));

if ($allPermanent) {
    return;
}

throw new PermanentExportException($message);
```

**Shopify does not say "already fulfilled" here.** When a label bought through
`shippingLabelPurchase` has already produced a fulfillment, Shopify closes the fulfillment
order, and the export's `fulfillmentCreate` comes back with:

> `fulfillment: Fulfillment order 2000000000001 has an unfulfillable status= closed.`

That string does not contain `already fulfilled`, so the guard misses, a
`PermanentExportException` is thrown, and the package's `PackageExport` row is written
`permanently_failed` with `exported` left `false`.

Observed 2026-09-09 on package 208, order #1241, a routine domestic Shopify purchase. The
export ran automatically three seconds after the purchase and failed; a manual re-run
reproduced it exactly.

```
export row: ds=7 status=permanently_failed completed='2026-09-09 17:24:07'
  last_error='Shopify fulfillment error: fulfillment: Fulfillment order 2000000000001
              has an unfulfillable status= closed.'
```

**This is not an edge case — it is every Shopify-bought package.** Shopify creating the
fulfillment itself is the normal path (`01`, question 3), so the condition holds for every
purchase, and the correct outcome is recorded as a permanent failure needing attention.

## Where the assumption came from

Shipped code and its docblock both say the export "degrades safely", and `01`'s question 3
records the same reasoning:

> `ShopifySource::exportPackage()` will meet an already-fulfilled order and swallow it,
> which is the branch that was assumed and never observed.

The first half is right — the export does meet an already-fulfilled order. The second half is
wrong: it is not swallowed, because the guard matches a message Shopify does not send. The
branch was inferred from the fulfillment existing, never run. It has now been run.

`06` reasons from the same premise — "to fulfill something it has already fulfilled" — and
should be re-read against this, though its own fix (withdrawing the offer) does not depend on
the swallow working.

## Options

- **Match the real message too.** Cheapest, and the same shape as the existing guard. Fragile
  in the same way: it matches English prose that Shopify can reword without notice, and this
  issue is precisely what that fragility looks like when it happens.
- **Match on a code rather than a message.** `fulfillmentCreate`'s `userErrors` are
  `FulfillmentOrderHoldUserError`-style entries; check whether a stable `code` is available
  and key on that. Preferred if one exists — the current guard's real defect is that it reads
  prose.
- **Do not export at all when Shopify created the fulfillment.** The export exists to tell
  the channel what shipped; when the channel *is* Shopify and Shopify already knows, the whole
  call is redundant. Detecting that from `postage_source` and skipping the destination is more
  honest than calling a mutation expecting it to fail. This is the one that removes the class
  of bug rather than the instance.

The third is the real fix and the first is a one-line stopgap; they are not exclusive.

## What to answer

1. **Does `fulfillmentCreate` return a stable error code here**, or only prose? Decides
   between options one and two.
2. **Should a Shopify-sourced package be exported to its own Shopify source at all** when the
   postage was bought through that same source? That is option three, and it is a design
   question about what export means, not a bug fix.
3. **What should the operator see** for a package that is correctly fulfilled at the channel
   but has no successful export row? Today it reads as a failure that needs attention and is
   neither.

## Acceptance criteria

- [ ] A Shopify-bought package whose fulfillment Shopify created does not record a
      `permanently_failed` export
- [ ] The package's `exported` state ends up truthful — either exported, or explicitly not
      applicable, but not "failed"
- [ ] A genuine export failure — bad credentials, a fulfillment order that is closed for some
      other reason — still records as a failure
- [ ] A test covers the exact `unfulfillable status= closed` message, and does not assert on
      prose that Shopify controls where a code is available instead

## Blocked by

Nothing. Reproducible on the development store and the fix is local.

## Related

- `01` — question 3, whose recorded inference this corrects, and question 4, which was being
  answered when this surfaced
- `06` — reasons from the same "already fulfilled" premise
- `18` — the fulfillment order churn that produces the closed order in the first place

## Comments

### 2026-09-09 — found while answering `01`'s question 4

Not looked for. Question 4 asks whether the customer is notified twice, since `notifyCustomer`
is set on the purchase and again on the export's `fulfillmentCreate`. With `notify_customer`
turned on for data source 7, package 208 was shipped through the UI and the order timeline
showed exactly one notification:

> Shipping notification scheduled to be sent to Test Customer (customer@example.com) on
> September 9, 2026, 10:28 am.

One fulfillment, one notification event, no duplicate. **But the reason is this bug** — the
second call site never succeeds, so there was never a second notification to send. Question 4
gets the answer it wanted for a reason nobody intended, which is worth knowing if this is ever
fixed: **an option-one or option-two fix that makes `fulfillmentCreate` succeed would reopen
the double-notification question**, because then both call sites would run with
`notifyCustomer` true. Option three would not.
