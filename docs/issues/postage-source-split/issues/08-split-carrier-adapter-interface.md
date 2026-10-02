# Split `CarrierAdapterInterface` at the postage-source seam

Status: done — PR #165, with quote and purchase deferred to the offer store

Repo: `polybag`

## Problem

`CarrierAdapterInterface` bundles quoting, purchasing, voiding, tracking, manifests,
special-service policy and rate resolution into one contract. That was coherent while every
offer came from a direct carrier. It is why `ShopifyAdapter` implements most of the interface
as advertised rates, empty parsing, unsupported operations and no-ops.

See ADR-0002 decisions 7 and 8.

## Update — 2026-09-03, after `07` merged (PR #164)

`07` shipped the dispatch split, and it changes what this slice is doing without changing
whether it should be done.

**The seam already exists in placeholder form.** `App\Services\PostageSources\PostageSourceDispatcher`
routes void, tracking and manifest eligibility on the `postage_source` discriminator: a `match`
with a `carrier_account` arm that reaches `CarrierRegistry` unchanged, and a
`postage_data_source` arm that reaches the source. It is deliberately *not* a contract — it was
left as a `match` so this slice defines the real one rather than unpicking a guess first. The
work here is to replace the `match` with the postage-source-operations contract and let the
dispatcher resolve an implementation instead of branching.

**`ShopifyPostageSource` is the first implementation, already written.** It holds the void
message, `supportsManifest()`, and the `FulfillmentDisplayStatus` → `TrackingStatus` mapping,
against `ShopifyShippingLabelService::fulfillmentFor()`. Making it `implements` the new contract
should be close to a signature change.

**So `ShopifyAdapter`'s tracking, void and manifest methods are now dead code, not live
behavior.** The acceptance criterion below said "no longer implements tracking or manifest
methods at all", which read as *move this behavior*; it is now *delete these unreachable
implementations*. Nothing routes to them: a Shopify-bought package records its physical carrier
and its postage source, so dispatch never asks `ShopifyAdapter` anything, and no package
records `carrier = 'Shopify'`. Two things to keep while deleting:

- `ShopifyAdapter::cancelShipment()` returns `ShopifyPostageSource::VOID_MESSAGE`. The constant
  lives on the postage source and stays there; only the adapter method goes.
- `CarrierRegistry` still registers `ShopifyAdapter` under `Shopify` for the rate and purchase
  flows, which are not part of this deletion.

**`supportsManifest()` now means two different things, and the split should name them apart.**
Carrier policy answers "can this carrier be manifested at all?" — `EndOfDay` asks it per carrier
to decide whether to show a manifest button. The postage source answers "may we put *this
package* on a manifest we create?", which is what `Package::scopeBoughtOnCarrierAccount()` and
the guard in `ManifestService::createManifest()` enforce. Both are wanted; one name for both
invites the conflation this whole feature exists to undo.

**Entitled tracking needs three outcomes on the contract, not two.** `07` established the
distinction in practice: *unsupported* (this source has no tracking to give), *no answer* (an
unmatched fulfillment — never recorded as a status, since it would attribute another parcel's
progress to this one), and a real result. A contract offering only supported/unsupported would
lose the middle one.

## What to build

Two contracts:

- **postage-source operations** — quote, purchase, void, manifesting, entitled tracking
- **carrier policy** — normalization, cutoffs, pickup and reporting behavior

Hard-required special services move to the offer seam (decision 8): an **Amazon** offer is
judged on the capabilities the offer itself returns, since
`availableValueAddedServiceGroups` is per-rate rather than per-carrier; a **direct-carrier**
offer consults carrier policy as today; **Shopify** is unsupported outright, because nothing
about its unconstrained selection can guarantee a required service. Capabilities that really
are carrier-wide stay in carrier policy.

Largest blast radius in this feature — it touches all four adapters. The safety net is
`UspsAdapterTest`, `FedexAdapterTest`, `UpsAdapterTest`, `ShopifyAdapterTest` and
`CarrierRegistryTest`; none of them should need rewriting to keep passing. `07` added three more
that hold the dispatch behavior this slice is re-plumbing underneath — `PostageSourceDispatchTest`
(including that the carrier is never asked about a Shopify label), `ShopifyPostageSourceTest`
(the mapping table, empty events, voided and unmatched fulfillments) and
`ShopifyFulfillmentSynchronizerTest`. Those assertions should survive the refactor unchanged;
if one has to move, it belongs with the new contract's implementation, not deleted.

Note `ShopifyAdapterTest` will need its tracking, void and manifest cases removed along with the
methods — the one place assertions are expected to go rather than move.

## Acceptance criteria

- [~] Both contracts exist and every adapter implements only what it can honestly answer —
      `PostageSourceOperations` declares three of the five operations named above; quote and
      purchase are deferred, see the deviation below
- [x] `PostageSourceDispatcher` resolves an implementation of the operations contract instead of
      branching on the discriminator in a `match`
- [x] `ShopifyPostageSource` implements that contract
- [x] `ShopifyAdapter` no longer implements rate parsing, tracking or manifest methods at all,
      and `ShopifyPostageSource::VOID_MESSAGE` survives the deletion of the adapter's void
- [x] Package-level manifest eligibility and carrier-level manifest support are separately named
- [x] The tracking contract can express "no answer" distinctly from "unsupported"
- [x] Hard-required special-service evaluation happens at the offer seam
- [x] A shipment with a hard-required special service excludes Shopify, visibly
- [x] All four adapter test files pass without changes to their assertions, and `07`'s three
      dispatch test files pass unchanged

## What shipped

PR #165, merged 2026-09-03. Two contracts, plus a third the issue did not anticipate.

- **`PostageSourceOperations`** — void, entitled tracking, package-level manifest eligibility.
- **`CarrierPolicy`** — name, `serviceCapability()`, declared-value cap, multi-package,
  `supportsCarrierManifest()`, `supportsTracking()`.
- **`DirectCarrierAdapter`** — the composite: `AsyncRateQuoting` + `CarrierAdapterInterface` +
  `CarrierPolicy`, plus `cancelShipment()` and `trackShipment()`. It exists because the three
  roles coincide for a carrier we hold an account with, and *only* there — stating that as a
  fact about direct carriers is what stops it being assumed of every adapter. `CarrierRegistry`
  gained `policyFor()` and `directAdapterFor()` so a caller asks for the half it needs.

`PostageSourceDispatcher` now has one `resolve()` and no per-operation branching.
`CarrierAccountPostageSource` and `UnrecognizedPostageSource` join `ShopifyPostageSource` as
implementations, so the `07` placeholder `match` is gone in all three methods rather than one.

`ShopifyAdapter` lost `parseRateResponse()`, `prepareRateRequest()`, and its tracking, void and
manifest methods, and now implements `CarrierAdapterInterface` alone — deliberately not
`CarrierPolicy`, since it is not a carrier and has no policy of its own to report. That is what
makes `EndOfDay`'s `policyFor('Shopify')` return null and suppress its manifest button without
a name check anywhere.

### Deviation: quote and purchase are not on the contract yet

The seam above was specified as "quote, purchase, void, manifesting, entitled tracking",
matching ADR-0002 decisions 3 and 7. Only the last three are declared. Quoting and purchasing
still dispatch by carrier name through `CarrierRegistry`.

They cannot move until an offer carries the postage-source instance it came from — ADR-0002
decision 4's offer store, which is `amazon-buy-shipping/02` and not built. Declaring them now
would add methods nothing could route to, and would have to be unpicked when the offer store
lands. The reason is recorded on `PostageSourceOperations` itself so the gap is visible where
someone would notice it, rather than only here.

Quoting instead came out as **`AsyncRateQuoting`**, which is not exclusive to direct carriers —
any source with a rate API implements it — so it composes rather than sitting on either seam.

### `ServiceCapability::Unguaranteed`

Decision 8 says Shopify is "unsupported outright ... [for] a required service". Implemented as
a fourth capability rather than by reusing `Prohibited`, because neither existing case is true:
`Prohibited` asserts the *carrier* refuses, and `NotImplemented` asserts *we* have not wired it
up. Neither holds — Shopify's API has no field in which to request a special service at all, so
the honest statement is that the offer cannot guarantee one.

It excludes on a hard requirement exactly as the decision demands, and drops the preference
without excluding when the service is only a default. That second case is behavior the ADR did
not speak to, and dropping a preference an offer cannot express is the right answer to it.

### Test churn

The criterion held: `UspsAdapterTest`, `FedexAdapterTest` and `UpsAdapterTest` are untouched,
and `07`'s three dispatch test files are untouched. `ShopifyAdapterTest` lost fifteen lines of
tracking, void and manifest cases — the deletion this issue sanctioned in advance.

`CarrierRegistryTest` and `TrackingServiceTest` did change, and it is worth being exact about
how: their anonymous test doubles now implement `DirectCarrierAdapter` instead of
`CarrierAdapterInterface` and use the `ConsultsCarrierPolicyForOffers` concern, and
`supportsManifest()` was renamed to `supportsCarrierManifest()`. No assertion changed meaning.
The prose above put both files in the safety net with "none of them should need rewriting"; a
renamed interface and a renamed method are not a rewrite, but they are not nothing either.

## Blocked by

- `07-dispatch-by-postage-source` — merged 2026-09-03 (PR #164)

## Follow-on

- `amazon-buy-shipping/02-specify-observation-and-offer-stores` — the offer store that unblocks
  moving quote and purchase onto `PostageSourceOperations`. **Done 2026-09-04.** The store
  exists and the ship path redeems against it; the two operations stay off the contract
  until a source actually issues an offer, which is `amazon-buy-shipping/03`.
