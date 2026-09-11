# The UPS Saturday-rejection retry is unreachable, and so is the error message beside it

Status: needs-triage

Repo: `polybag`

## Problem

`UpsAdapter::createShipment()` handles a UPS refusal of Saturday delivery by retrying
without it, gated on `$saturdayApplied && ! $response->successful()`. **That condition is
never true.** `UpsConnector` sets `$tries = 3` and Saloon's `throwOnMaxTries` defaults to
`true`, so a failing request throws a `RequestException` out of `sendCreateShipment()`
rather than returning a failed response, and the adapter's outer `catch` turns it into
`ShipResponse::failure()` before either branch is reached.

The block below it, which builds `$errorMessage` from
`$responseData['response']['errors'][0]['message']`, is unreachable for the same reason.

**Observed**, not deduced: found while fixing `24`, by writing a test for the retry that
could not be made to pass. A mocked UPS 400 refusing Saturday delivery produces the
`RequestException`'s own message — `Bad Request (400) Response: {"response":{"errors":…}}` —
and the fake records a single attempt. `RetriesTransientErrors::handleRetry()` already
declines to retry 4xx, so this is not the transient-retry path misbehaving; it is a
Saturday-specific fallback written against a return-value contract the connector does not
have.

## What to decide

`needs-triage` rather than `ready-for-agent` because the fix depends on whether the
behaviour is still wanted, which is a shipping question:

1. **Revive it** — catch `RequestException` around `sendCreateShipment()`, inspect the
   response body off the exception, retry there. Costs a real code path and a test per
   branch.
2. **Delete it** — if UPS refusing Saturday delivery is rare, or better surfaced to the
   operator than silently downgraded. Note the silent downgrade is arguably wrong anyway:
   the package ships on a service the operator did not choose, `appliedServices` is
   corrected, and nothing tells them at the time.
3. **Leave it** and mark it dead, which is the status quo and the worst of the three.

Whichever way it goes, the unreachable `$errorMessage` block goes with it, so the adapter
has one error path rather than one live and one decorative.

## Not urgent

This has never functioned, so nothing regresses by leaving it. It costs an operator a
confusing failure message on a Saturday-delivery rejection, and `saturday_delivery` is an
opt-in special service.

The `unset()` inside the dead branch was made surgical while fixing `24`, so reviving the
retry will not strip the customs invoice from an international shipment.
