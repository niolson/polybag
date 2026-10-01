# FedEx reads "out for delivery" and "delivery exception" as delivered

Status: done — 2026-10-01

Repo: `polybag`

Severity: medium. A FedEx parcel that is on the truck, or has failed delivery, is marked
Delivered with a wrong delivery date. Delivered is terminal, so it is never checked again.
Verified: confirmed (test below fails on `main` at `6d47232`).

## Problem

`FedexAdapter::mapTrackingStatus()` tests its arms in order, and the first one is:

```php
str_contains($normalizedCode, 'DL') || str_contains($normalizedLabel, 'DELIVER') => TrackingStatus::Delivered,
```

`DELIVER` is a substring of most of FedEx's other delivery-stage descriptions:

| Code | FedEx description | Should be | Mapped to |
|---|---|---|---|
| `OD` | On FedEx vehicle for delivery | Out for delivery | **Delivered** |
| `DE` | Delivery exception | Exception | **Delivered** |

The `OD` and `DE` arms further down are never reached for those descriptions.

Two things make it worse:

- **The delivery date is invented.** With no `DL` scan event, `resolveDeliveredAt()` falls
  back to `deliveredAtFallback()`, which re-runs the same mapping, gets Delivered, and
  returns `dateAndTimes.0.dateTime`. That is whatever FedEx lists first, usually the ship or
  pickup date.
- **It never corrects itself.** `RefreshTrackingCommand` skips `Delivered` and `Returned`
  packages, so the next scan, the real delivery or the exception, is never read. A delivery
  exception is exactly what an operator needs to see, and here it can't be seen.

USPS and UPS match the past tense `DELIVERED`, so they don't have this problem. The only
overlap there is a word like "undelivered", which neither carrier seems to use as a status.

## Evidence

In `tests/Unit/Services/Carriers/FedexAdapterTest.php`:

```php
it('reads FedEx delivery-stage statuses that are not a delivery', function (string $code, string $description, TrackingStatus $expected): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make(['output' => ['completeTrackResults' => [['trackResults' => [[
            'latestStatusDetail' => ['code' => $code, 'description' => $description],
            'dateAndTimes' => [['type' => 'ACTUAL_PICKUP', 'dateTime' => '2026-04-08T09:00:00Z']],
            'scanEvents' => [],
        ]]]]]]),
    ]);

    $package = Package::factory()->fedex()->create(['carrier' => 'FedEx', 'tracking_number' => '794644790138']);
    $response = $this->adapter->trackShipment($package);

    expect($response->status)->toBe($expected)          // fails: Delivered, both cases
        ->and($response->deliveredAt)->toBeNull();      // would be the pickup time
})->with([
    'out for delivery' => ['OD', 'On FedEx vehicle for delivery', TrackingStatus::OutForDelivery],
    'delivery exception' => ['DE', 'Delivery exception', TrackingStatus::Exception],
]);
```

## What to build

- Map on FedEx's status **code** first, as an exact match: `DL` delivered, `OD` out for
  delivery, `DE`/`SE`/`HL`/`CA` exception, `RS` returned, `IT`/`AR`/`DP`/`PU`/… in transit,
  `OC` pre-transit. Fall back to description text only when the code is unknown.
- Where text is still read, match whole phrases (`DELIVERED`), never the stem `DELIVER`.
- Make `deliveredAtFallback()` read the `ACTUAL_DELIVERY` entry of `dateAndTimes` by its
  `type`, not index 0.
- Consider a one-off command to re-check FedEx packages marked Delivered without a `DL`
  event, since the terminal state keeps them from being refreshed.

## Comments

- 2026-10-01 — `FedexAdapter::mapTrackingStatus()` looks the status code up exactly in
  `TRACKING_STATUS_CODES`, where only `DL` is Delivered. It reads the description only for
  an unknown code, by phrase: "for delivery", exception/delay/hold, "return", then
  `DELIVERED` (not "undelivered"), then transit. `deliveredAtFallback()` reads the
  `ACTUAL_DELIVERY` entry of `dateAndTimes` by type. The estimated delivery date had the
  same index-0 fault and now reads `ESTIMATED_DELIVERY` by type. Regression tests: the
  Evidence test, extended to unknown codes, and an estimated-date-by-type test in
  `FedexAdapterTest`. Both fail without the fix. The existing summary-fallback test's
  fixture gained `type` keys, which every real FedEx reply carries. The one-off re-check
  command was not built: there are no live tenants, so no Package was wrongly marked
  Delivered.
