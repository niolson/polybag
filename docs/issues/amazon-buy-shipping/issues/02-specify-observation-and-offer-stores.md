# Specify the observation and offer stores from the `01` findings

Status: done

Repo: `polybag`

## Problem

ADR-0003 requires two stores with different lifecycles, and deliberately does not specify
their shape — "build only the observation and approval structure the returned data proves
necessary."

- An **offer** is ephemeral and package-specific: price, promise, `requestToken`, `rateId`,
  expiry. `rate_quotes` approximates this but is an audit log on a 60-day purge and must not
  be repurposed as an authoritative purchase context.
- An **observed service** is durable: `(source, environment, marketplace, carrierId,
  serviceId)` with first/last seen and mapping state. It deduplicates identities and must not
  retain purchase tokens indefinitely.

`01` has returned, so this is specifiable now. Captured responses are in
`.scratch/amazon-shipping-v2/` in the app repo. What they establish: `rateId` and
`requestToken` are separate opaque strings, no expiry is returned so it must be tracked from
request time, `availableValueAddedServiceGroups` and `supportedDocumentSpecifications` vary
per rate, and identity needs `(source, environment, marketplace, carrierId, serviceId)` — the
production run and the sandbox returned disjoint carrier sets for the same channel.

## What to build

The two stores, plus the offer lifecycle ADR-0002 decision 4 requires: opaque identifier,
binding to both the package and the postage-source instance, expiry, atomic consumption so
one offer cannot be spent twice, and idempotent recovery so a purchase that succeeded at
Amazon but failed on our side is found rather than repeated.

The browser holds only the opaque offer identifier. `requestToken`, `rateId`, source identity,
environment and expiry stay server-side — `RateResponse` round-trips through Livewire today,
and that is not where purchase authority belongs.

## Acceptance criteria

- [x] Offer and observed-service stores are separate, with separate retention
- [x] An offer cannot be consumed twice
- [x] An expired offer fails closed with a re-quote path, not a silent purchase
- [x] No purchase token is reachable from browser state
- [x] Observed services deduplicate on `(source, environment, marketplace, carrierId, serviceId)`

## Blocked by

- `01-run-getrates-and-record-what-comes-back`

## What shipped

Two tables, two models, two services, and one seam through the ship path.

- **`observed_services`** + `ObservedService`, `ObservedServiceRecorder`,
  `ServiceObservation`. Deduplicates on the five-part identity, with `first_seen_at`,
  `last_seen_at`, `observation_count` and a nullable `carrier_service_id` for the mapping
  `05` will fill. Nothing here creates a `Carrier` or a `CarrierService`, and a test
  asserts it.
- **`shipping_offers`** + `ShippingOffer`, `OfferStore`, `OfferDraft`, `OfferRedemption`,
  `OfferRejection`. Opaque ULID, bound to the package *and* the postage-source instance,
  encrypted purchase context, expiry, one-way consumption.
- **`SourceEnvironment`** — production/sandbox, derived from the shared `sandbox_mode`
  setting and stamped onto every offer and observation at write time.
- `RateResponse` gained `offerId`, and `EloquentPackageShippingWorkflow::ship()` redeems
  it before anything reaches a carrier. Direct-carrier rates carry no offer and ship
  exactly as before, so this seam is live and tested with no issuer in production code
  yet — `03` is the first.

Decisions the issue left open, and why they went the way they did:

**Consumption is one-way, including after a failed purchase.** A failed `createShipment`
leaves the offer spent. Returning it to the pool would be the friendlier behavior and the
wrong one: a failure response does not prove the source declined, and re-spending an
identifier it may already have honored is how a parcel gets two labels. The operator
re-quotes, which costs a round trip and nothing else.

**An offer resolves three ways, and the third one blocks the package.** Confirmed
(`purchase_reference`), declined (`purchase_failed_at` — the source answered no, so nothing
was bought and the package is free to quote again), or nothing heard at all. Only the third
is ambiguous, and while one stands `ship()` refuses to buy anything for that package on any
source: a label may exist upstream that we never recorded, and a second purchase pays for a
second one. `data:purge` never deletes one of those whatever the retention is set to, and
reports how many it kept. `03` turns that state into an actual recovery call, mirroring
`ShopifyShippingLabelService`.

The distinction is the whole reason for the extra column: without it, every ordinary carrier
decline would jam the package permanently.

**The offer is the purchase authority; the selected rate is display data.** `ship()` rebuilds
carrier, service, price *and rate metadata* from the stored offer, so a valid offer identifier
paired with altered rate data cannot spend one offer and buy something else. What comes back
from Livewire says *which* offer, and nothing more.

The metadata half is not cosmetic and is why `shipping_offers.rate_metadata` exists.
`FedexAdapter` reads `metadata['serviceType']` with no fallback and `UspsAdapter` reads
`mailClass`, `rateIndicator` and `processingCategory` the same way — an offer that dropped it
would not buy the wrong label, it would fail to buy one at all. Storing it on the offer keeps
both properties at once: the adapter gets what it needs, and it comes from what the source
said rather than from what the browser sent back.

**An offer is refused when its carrier account is no longer the one that would buy.** The
offer records `carrier_account_id`, but the purchase cannot yet be *told* to use it: adapters
resolve their own through `ResolvesCarrierAccount`, from the package's location and client,
and `ShipRequest` has nowhere to name one. The two usually agree, and stop agreeing when
scopes are edited between quote and purchase, or when rate shopping quoted several accounts
for one carrier and priority has since moved. So `accountNoLongerResolves()` compares — using
the same `CarrierAccount::resolveForShipment()` the adapter will use, not a copy of its rules
— and refuses on divergence rather than billing an account that never offered the price.
Passing the account through `ShipRequest` is the real answer and belongs with the interface
work in `03`.

**A channel offer is refused rather than misdispatched.** Purchase still routes through
`CarrierRegistry` by carrier name, which is only correct when the carrier of record and the
adapter to call are the same thing — that is, an offer bought on one of our own carrier
accounts. An Amazon offer carried by OnTrac must be bought from Amazon; looking up "OnTrac"
would find a direct adapter we have not built and hold no account with. Until quoting and
purchasing move onto `PostageSourceOperations` in `03`, a `postage_data_source` offer fails
loudly at `unsupportedDispatch()`. That guard is what `03` deletes.

**Purchases are serialized per package.** The unresolved-purchase guard and the offer claim
are two separate writes, so two requests carrying two *different* valid offers would each
read a clean package, claim their own row, and buy a label apiece. A non-blocking
`Cache::lock("package-purchase:{id}")` makes the pair one decision. Non-blocking on purpose:
queueing behind a carrier call that may run a minute leaves a packer at a frozen button,
where "someone is already buying this" is something they can act on.

**An offer does not survive the sandbox toggle.** `inspect()` and the atomic claim both
compare the stored environment against `SourceEnvironment::current()`. Sandbox and production
identifiers differ and so do the hosts that honor the tokens, so an offer quoted in one world
is a record in the other, never authority — and rejecting it before the claim keeps it from
being spent into the wrong endpoint and then stranded as unresolved.

**Local validation happens before the one-way door.** `OfferStore::inspect()` answers "could
this be spent?" without spending it, and the atomic claim happens immediately before
`createShipment()`. Consuming first would turn a customs-weight confirmation prompt — a round
trip through the operator — into a dead offer and a forced re-quote.

**Expiry is checked inside the claim, not before it.** The claim is a single conditional
`UPDATE` matching on `consumed_at IS NULL` *and* the window, so a rate that expires
between the read and the write cannot be spent by whoever was mid-flight.

**Retention is three different clocks, deliberately.** Offers default to 7 days
(`shipping_offer_retention_days`, new, in Settings → Data Retention), rate quotes stay at
60, and observed services are purged by nothing at all. The audit log of what was quoted
and the authority to buy it answer different questions; an identity is worth more the
older it gets.

**`marketplace` is `''` rather than null on `observed_services`.** MySQL treats NULLs as
distinct in a unique index, so a nullable column there would have let the same identity
insert without limit and quietly defeat the deduplication the table exists for.

**The recorder is bulk, not per-service.** A production response is ~110 identities and it
runs on the Ship page for every quote; recording one is four queries regardless of how
many come back, asserted by a test at 105 identities.

Three things worth carrying to the next slice:

- `ShipRequest` cannot name a carrier account or a postage-source instance, which is why two
  of the guards above are comparisons rather than plumbing. Both delete themselves when
  quoting and purchasing move onto `PostageSourceOperations`.

- `PackageShippingOptions` is cached under a lock for `RATE_CACHE_SECONDS` in
  `Ship::loadRateOptions()`. Once `03` issues real offers, a cached rate list will hand
  out identifiers for offers that may already be spent — the redemption path fails closed
  on that, but the cache and the offer lifetime should be reconciled deliberately rather
  than left to coincide.
- `EloquentPackageShippingWorkflow::selectedRateIndex()` still matches a pre-selected rate
  on `carrier` + `serviceCode` alone, which ADR-0002 decision 4 names as the collision this
  store exists to fix. It is unchanged here because nothing issues offers yet; `03` should
  match on the offer identifier instead.
