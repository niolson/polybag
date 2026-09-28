# A FedEx cancel that didn't cancel is recorded as a void

Status: needs-triage

Repo: `polybag`

Severity: high if FedEx answers this way. The Package returns to Unshipped while its
Label is still live, and the re-ship buys a second label for the same parcel.
Verified: confirmed against FedEx's documented response shape (test below fails on `main`
at `6d47232`). Whether production FedEx sends a 2xx with `cancelledShipment: false`, rather
than a 4xx, has not been observed.

## Problem

This is the void-side version of `11`: the adapter reads the HTTP status and ignores what
the body says.

`FedexAdapter::cancelShipment()`:

```php
if ($response->successful()) {
    return CancelResponse::success('FedEx shipment cancelled.');
}
```

FedEx's Cancel Shipment reply carries its own answer, `output.cancelledShipment` (and
`cancelledHistory`), with `alerts` explaining a refusal. The existing test fixture already
returns `['output' => ['cancelledShipment' => true]]`, but the adapter never reads it.
A 2xx whose body says the shipment was *not* cancelled (for example, already tendered)
counts as a success. `EloquentPackageLabelWorkflow::voidLabel()` then runs
`clearShipping()`: the Label is marked voided and the Package is Unshipped (area B
invariant 2), so it can be shipped again. The first label stays valid, and FedEx bills a
label once it's tendered.

The other direct adapters have the same shape but less evidence that it matters:

- **UPS** returns `success` on any 2xx and only reads
  `VoidShipmentResponse.SummaryResult.Status.Description` for the message. It never checks
  `Status.Code` (`1` = voided).
- **USPS** returns `success` on any 2xx without reading the body.

Amazon's `cancelShipment` has no body to read, so a 2xx is its whole answer.

## Evidence

In `tests/Unit/Services/Carriers/FedexAdapterTest.php`:

```php
it('does not read a FedEx reply that cancelled nothing as a void', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CancelShipment::class => MockResponse::make([
            'output' => [
                'cancelledShipment' => false,
                'cancelledHistory' => false,
                'alerts' => [['code' => 'SHIPMENT.CANCEL.NOTALLOWED', 'message' => 'Shipment already tendered']],
            ],
        ], 200),
    ]);

    $package = Package::factory()->shipped()->for(Shipment::factory())->create([
        'carrier' => 'FedEx', 'tracking_number' => '794644790138',
    ]);
    config(['services.fedex.account_number' => 'test_account']);

    expect($this->adapter->cancelShipment('794644790138', $package)->success)->toBeFalse();  // fails: true
});
```

## What to build

- **FedEx:** success only when `output.cancelledShipment === true`. Otherwise fail, with the
  first alert's message.
- **UPS:** success only when `SummaryResult.Status.Code` is `1`.
- **USPS:** check the v3 cancel reply for a field that confirms the refund request, and
  require it if there is one.
- In all three, treat a 2xx body the adapter can't read as a failure ("the carrier's reply
  could not be read; check the carrier's site before voiding again"), never as a success.
  A void is safe to retry, and a wrong success leads to a second label.

## Comments
