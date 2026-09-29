# Direct voids and tracking ask whichever account resolves now, not the one that bought the label

Status: needs-triage

Repo: `polybag`

Severity: high. After any scope change, a label bought on our own carrier account can no
longer be voided or tracked from PolyBag, so the refund has to be claimed by hand.
Verified: confirmed (test below fails at `a099283`).

## Problem

Every direct purchase records the account it was bought on: each adapter returns
`carrierAccountId: $account?->id` and `markShipped()` writes it to `packages.carrier_account_id`
and to the Label row. Two readers use that record and one does not:

- **Recovery uses it.** `ResolvesCarrierAccount::purchasingAccount()` asks the account on
  the Offer, and its docblock gives the reason: "a different CRID or shipper number
  answers 'no such label' truthfully about itself".
- **The manifest uses it.** `ManifestService::resolveUspsAccount()` takes
  `$package->carrierAccount` first and resolves through scopes only when none is recorded.
- **Void and tracking don't.** `cancelShipment()` and `trackShipment()` in
  `UspsAdapter` (`:1147`, `:669`), `FedexAdapter` (`:791`, `:824`) and `UpsAdapter`
  (`:780`, `:308`) all call `resolveAccount($package->location_id, $package->shipment->client_id)`,
  which is `CarrierAccount::resolveForShipment(...)->first()`: whatever the scopes say
  *today*. `CarrierAccountPostageSource` passes the Package through and never reads
  `carrier_account_id`.

So the void and tracking go to a different account whenever the scopes no longer resolve
to the one that bought the label:

- a client- or location-specific account is added after the purchase, which is the usual
  way a 3PL onboards a client that brings its own account;
- the buying account is deactivated or its scope removed (`resolveForShipment()` filters
  on `active`), in which case the request goes out on the next account in line, or on no
  account at all;
- the Package's location changes.

The carrier then refuses the void ("not found" or not authorised on that account), and
the operator gets "Void failed" for a label that is still live and billed. PolyBag has no
other way to void it. Tracking fails the same way, and for USPS the ADR-0002 entitlement
note means it cannot succeed: tracking data belongs to the account that bought the
postage.

## Evidence

The label is bought on the global FedEx account, a client account is added afterwards,
and the void is sent with the client's account number:

```php
it('voids a FedEx label on the account that bought it after a client account is added', function (): void {
    $sent = [];
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CancelShipment::class => function ($pending) use (&$sent) {
            $sent[] = data_get($pending->body()->all(), 'accountNumber.value');

            return MockResponse::make(['output' => ['cancelledShipment' => true]]);
        },
    ]);

    CarrierAccount::query()->delete();
    $carrier = Carrier::firstOrCreate(['name' => 'FedEx']);
    $client = Client::factory()->create();

    $globalAccount = CarrierAccount::factory()->fedex()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'global_account'],
        'secret_credentials' => ['api_key' => 'k1', 'api_secret' => 's1'],
    ]);
    CarrierAccountScope::factory()->forAccount($globalAccount)->global()->create();

    $package = Package::factory()->shipped()->for(Shipment::factory()->create(['client_id' => $client->id]))->create([
        'carrier' => 'FedEx',
        'tracking_number' => '794600000001',
        'carrier_account_id' => $globalAccount->id,
    ]);

    // Later that day the client gets its own FedEx account.
    $clientAccount = CarrierAccount::factory()->fedex()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'client_account'],
        'secret_credentials' => ['api_key' => 'k2', 'api_secret' => 's2'],
    ]);
    CarrierAccountScope::factory()->forAccount($clientAccount)->clientScoped($client)->create();

    app(PostageSourceDispatcher::class)->voidLabel($package);

    expect($sent)->toBe(['global_account']);   // fails: ['client_account']
});
```

USPS and UPS use the same resolution, and so does tracking on all three. The test above
exercises FedEx only because the account number is in the request body.

## What to build

A direct Label's void and tracking go to the account recorded on it, as recovery and the
manifest already do.

- Give the adapters one way to get "the account this Package's Label was bought on":
  `carrier_account_id` when set, scope resolution only when it is null (labels from
  before accounts were recorded). `ManifestService::resolveUspsAccount()` already does
  this and can share it.
- When the recorded account has been deleted, refuse the void with a message that names
  the account problem. Don't fall back to resolution. Deletion nulls the column
  (`nullOnDelete`), so tell "deleted" apart from "never recorded" the way
  `purchasingAccountChanged()` does, from the Label row or a fingerprint. A deactivated
  account should still be usable for voids and tracking, because the label it bought
  exists either way.
- Regression tests: the FedEx test above, plus USPS (payment token for the recorded
  account) and one tracking case.

## Comments
