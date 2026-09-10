# The UPS Saturday-rejection retry is unreachable, and so is the error message beside it

Status: needs-triage

Repo: `polybag`

## Problem

`UpsAdapter::createShipment()` sends the shipment, then handles a UPS refusal of Saturday
delivery by retrying without it:

```php
$response = $this->sendCreateShipment($connector, $shipment, $request);
$responseData = $response->json();

if ($saturdayApplied && ! $response->successful()) {
    $errorJson = json_encode($responseData);
    if (str_contains(strtolower($errorJson), 'saturday')) {
        // … retry without Saturday
    }
}
```

`! $response->successful()` is never true. `UpsConnector` sets `$tries = 3`, and Saloon's
`throwOnMaxTries` defaults to `true` (`SendsRequests::send()` resolves
`$request->throwOnMaxTries ?? $this->throwOnMaxTries ?? true`), so a failing request throws
a `RequestException` out of `sendCreateShipment()` rather than returning a failed response.
The adapter's outer `catch (\Exception $e)` turns it into `ShipResponse::failure()` before
either branch is reached.

The block immediately below it, which builds `$errorMessage` from
`$responseData['response']['errors'][0]['message']`, is unreachable for the same reason —
UPS API errors surface through the catch instead, with the exception's own message.

## Observed

Found while fixing `24`, by writing a test for the retry that could not be made to pass. A
mocked UPS 400 refusing Saturday delivery produces:

```
success=false  errorMessage="Bad Request (400) Response: {"response":{"errors":[{"code":"120212", …}]}}"
```

That is the `RequestException` message, not the parsed one the unreachable block builds,
and the retry never fires — the fake recorded a single attempt.

`RetriesTransientErrors::handleRetry()` already declines to retry 4xx, so this is not the
transient-retry path doing something unexpected. It is a Saturday-specific fallback that
was written against a return-value contract the connector does not have.

## What to decide

This is `needs-triage` rather than `ready-for-agent` because the fix depends on whether the
behaviour is still wanted, and that is a shipping question rather than a code one:

1. **Revive it** — catch `RequestException` around `sendCreateShipment()`, inspect the
   response body off the exception, and retry there. Costs a real code path and needs a test
   per branch.
2. **Delete it** — if UPS refusing Saturday delivery is rare or better surfaced to the
   operator than silently downgraded. Note that a silent downgrade is arguably the wrong
   behaviour anyway: the package ships on a service the operator did not choose, and
   `appliedServices` is corrected but nothing tells them at the time.
3. **Leave it** and mark it dead, which is the status quo and the worst of the three.

Whichever way it goes, the unreachable `$errorMessage` block should go with it, so the
adapter has one error path rather than one live and one decorative.

## Not urgent

Nothing is broken that used to work: this has never functioned, so no behaviour regresses by
leaving it. It costs an operator a confusing failure message on a Saturday-delivery
rejection, which is a narrow case — `saturday_delivery` is an opt-in special service.

The `unset()` inside the dead branch was made surgical while fixing `24`, so reviving the
retry will not strip the customs invoice from an international shipment.
