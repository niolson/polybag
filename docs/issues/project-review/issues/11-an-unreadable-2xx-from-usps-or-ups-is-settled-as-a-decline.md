# An unreadable 2xx from USPS or UPS is settled as a decline

Status: needs-triage

Repo: `polybag`

Severity: high — a label the carrier created and charged for is forgotten, and the next
attempt buys another.
Verified: confirmed for USPS and UPS (tests below fail on `main` at `6d47232`).

## Problem

The purchase path has two kinds of failure, and treats them differently on purpose:

- **A decline** (`ShipResponse::failure()`) means the source answered and sold nothing.
  `buyPostage()` settles the Offer with `recordFailure()`, and the Package is free to be
  bought again.
- **No answer** (a transport error or 5xx, thrown) leaves the Offer awaiting
  confirmation. The next attempt asks the carrier first, by USPS's `X-Idempotency-Key`
  or UPS's label recovery.

USPS and UPS both return the decline for a response they could not read, after the
carrier answered 2xx. A 2xx from the label endpoint means the label exists and is paid
for.

- **USPS** (`UspsAdapter.php`, domestic and international): `parseBody()` throws on a
  malformed multipart body (`:856`, `:992`), which the catch-all `\Exception` turns into
  `ShipResponse::failure()`. A body missing the tracking number or the label part returns
  `failure('USPS response missing tracking number' | '… label data')` directly
  (`:868`/`:876`, `:1009`/`:1017`).
- **UPS** (`UpsAdapter.php`): a 2xx missing `ShipmentResults`, the tracking number, or the
  label image returns `failure(…)` (`:679`, `:689`, `:705`). In the last case the
  response carries a tracking number, so the shipment certainly exists.

Each one is settled as "nothing was bought", and the operator's retry buys a second
label. Both carriers implement `RecoversUnresolvedPurchase` and could have been asked;
the adapter's own classification stops that happening.

Amazon Buy Shipping already gets this right. When Amazon sells a shipment but returns no
tracking number, the adapter stamps Amazon's shipment ID on the Offer before it reports
the failure, so `recordFailure()` (which only writes when `purchase_reference` is null)
leaves it alone (`AmazonBuyShippingAdapter::shipResponse()`). Even there, though, the
Offer then reads as *resolved*, so nothing blocks a new purchase. That is `03`'s gap by a
different route.

## Evidence

Both use the fixtures in `tests/Feature/DirectCarrierPurchaseRecoveryTest.php`.

```php
it('does not treat a USPS 200 it cannot read as a decline', function (): void {
    $calls = 0;
    Saloon::fake([
        ...uspsAuthFakes(),
        Label::class => function () use (&$calls): MockResponse {
            $calls++;

            // 2xx: USPS made and charged the label, but the metadata part has no tracking number.
            return MockResponse::make(
                body: "--b\r\nContent-Type: application/json\r\n\r\n{\"postage\":8.40}\r\n--b\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQ=\r\n--b--",
                headers: ['Content-Type' => 'multipart/form-data; boundary=b'],
            );
        },
    ]);

    $workflow = app(PackageShippingWorkflow::class);
    $workflow->ship($this->package, new PackageShippingRequest(selectedRate: uspsGroundAdvantage($this->package)));
    // Offer settled: purchase_failed_at set, reason "USPS response missing tracking number".
    $workflow->ship($this->package->fresh(), new PackageShippingRequest(selectedRate: uspsGroundAdvantage($this->package)));

    expect($calls)->toBe(1);   // fails: 2
});
```

The UPS test answers `CreateShipment` with a 2xx carrying
`PackageResults: [['TrackingNumber' => '1ZREVIEW', 'ShippingLabel' => []]]`. The first
attempt returns "UPS response missing label data", and the second buys again (`$calls` is
2).

## What to build

Once the carrier has answered 2xx, never return a decline. Anything the adapter can't
make sense of after that point is an unknown outcome, not a refusal.

- **USPS and UPS:** throw a dedicated exception (for example
  `UnreadablePurchaseResponseException`) instead of `ShipResponse::failure()` for every
  post-2xx problem, and let `parseBody()`'s exceptions through rather than catching them.
  The workflow should treat it like a timeout: leave the Offer unresolved, log the raw
  response, and show "the carrier accepted the purchase but its reply could not be
  read". The next attempt then recovers by key (USPS reprint) or reference (UPS label
  recovery), which returns the same label.
- Log the unreadable body at error level before throwing. It's the only copy of what
  the carrier said.
- Consider where the tracking number is known (UPS's case above, Amazon's shipment ID):
  stamp it on the Offer as well, so a person can find the label even if recovery can't.

A shared rule could live in `ShipResponse` itself: a `failure()` is only legitimate
before the carrier has accepted. Whether that's enforceable is worth checking, since some
adapters build the failure in one place.

## Comments
