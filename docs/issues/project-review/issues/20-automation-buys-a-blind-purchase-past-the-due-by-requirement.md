# Automation buys a blind purchase for an order its method requires on time

Status: done — 2026-10-02

Repo: `polybag`

Severity: medium, and partly a decision. The method's on-time requirement promises that
automation skips a purchase with no delivery date, and a Shopify blind purchase never has
one.
Verified: confirmed (test below fails on `main` at `6d47232`) against the requirement as
the method form words it.

## Problem

`amazon-buy-shipping/17` put *Exclude rates that deliver after the due-by date* on the
shipping method, on by default, and made it apply to every order on the method. The form
describes the section as "what batch ship, auto-ship and shipping rules insist on before
buying a label for an order on this method", and the toggle's help text says automation
"skip[s] any rate whose delivery date is after the shipment's due-by date, **or that gives
no delivery date**".

`selectedRateForAutoShip()` applies `OfferRequirements` to every rate, including a rule's
pre-selected one ("a rule's choice is still unattended"). It never applies them to a
blind purchase:

- a *Use Shopify* rule's blind offer is returned before `$requirements` is even built;
- the sole-choice inference (`soleBlindPurchaseOfferForAutomation()`) runs after rate
  selection and is returned without being checked.

So a Shopify order with a due-by date is bought blind, and Shopify may choose a service
that arrives late. The due-by date comes from `deliver_by` or the method's
`commitment_days`, and every seeded method has a commitment. The requirement is on by
default. A Shopify-only method therefore gets exactly the purchase the toggle promises to
skip.

This conflicts with ADR-0003's explicit-choice rule, which says a rule naming Shopify, or
Shopify being the method's only choice, *is* the operator's consent to a blind purchase.
Which of the two wins hasn't been decided anywhere. Either answer is defensible, but at
the moment the code and the form disagree.

## Evidence

In `tests/Feature/BlindPurchaseTest.php`:

```php
it('does not auto-ship a blind purchase when the method refuses rates with no delivery date and the order has a due-by date', function (bool $byRule): void {
    $package = blindPurchasePackage(withUspsRate: $byRule);
    allowBlindPurchase($package);
    $package->shipment->update(['deliver_by' => now()->addDays(2)]);
    $source = registerBlindSource();
    if ($byRule) {
        ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
            'shipping_method_id' => $package->shipment->shipping_method_id,
            'action' => ShippingRuleAction::UseService,
            'carrier_service_id' => null,
            'any_service' => true,
        ]);
    }

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse();               // fails for both: the blind purchase is bought
    $source->shouldNotHaveReceived('createShipment');
})->with(['sole choice' => false, 'rule' => true]);
```

## What to build

A maintainer decision first:

- **The requirement wins.** With `excludes_late_rates` on and a due-by date,
  automation doesn't buy blind; the refusal names the method's requirement and the
  attended alternative, as `refusedForMethodRequirements()` does for rates. A
  seller who wants Shopify to choose regardless turns the toggle off for that method. The
  test above becomes the regression test.
- **The explicit choice wins.** Keep the behaviour, and say so on the form: the help
  text gains "A Shopify Shipping purchase has no delivery date and is bought anyway when
  a rule names it or it is the method's only choice." Replace the test with one pinning
  that behaviour.

The first matches the section's own description and is the safer default for an unpriced,
undated purchase.

## Comments

- 2026-10-01 — Decided: **the explicit choice wins.** A rule naming Shopify, or Shopify
  being the method's only choice, keeps buying blind on an order with a due-by date.
  Shopify's default rate selection keeps the buyer's checkout delivery method where it
  can, then the shop's preferred carrier and service, then Shopify's recommended rate, so
  the seller has its own lever there. To build: the method form warns when a method that
  allows Shopify Shipping has a commitment or *Exclude rates that deliver after the due-by
  date* on; the toggle's help text stops promising to skip every undated purchase; and the
  Evidence test is replaced by one pinning the blind purchase.
- 2026-10-02 — Built as decided. `ShippingMethodResource` shows a warning callout,
  *Shopify Shipping is bought without a delivery date*, in *Automated Purchases* when the
  method has a `shopify` postage-source row and either a commitment or *Exclude rates that
  deliver after the due-by date* on. It is reactive to both fields and points the seller at
  the Shopify admin's preferred carrier and service. The toggle's help text now says a
  Shopify Shipping purchase has no delivery date and is bought anyway when a rule names it
  or it is the method's only choice. No change to `selectedRateForAutoShip()` beyond a
  comment that records the decision. The Evidence test is replaced in
  `BlindPurchaseTest` by *auto-ships a blind purchase for an order with a due-by date on a
  method that excludes late rates*, for both the sole-choice and rule paths. Two form
  tests in `ShippingMethodOfferRequirementsTest` cover the callout appearing,
  disappearing, and staying off for a method that doesn't allow Shopify.
