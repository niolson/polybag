# Implement postage-source resolution

Status: done — PR #167, with the scope-drift fix in PR #168

Current-scope clarification (2026-09-22): the shipped resolver supports postage bound to the
originating Shopify/Amazon source and direct carrier accounts. Off-Amazon Amazon Shipping
(`channelType: EXTERNAL`) is not implemented. The bullet below assigning it to a
`CarrierAccount` is superseded by ADR-0002's 2026-09-22 clarification: it should select a
connected Amazon `DataSource` for the Shipment's Client.

Repo: `polybag`

## Problem

Once more than one postage source can supply a shipment, something has to decide which one
is asked — and "follow the `CarrierAccount` pattern" is not enough to bind an offer to a
specific source instance. ADR-0002 decision 9 settles the rules; this is implementing them.

Nothing exercises this until a second postage source exists, but the Amazon adapter cannot
be built without it.

## What to build

Resolution per ADR-0002 decision 9:

- **Shopify binds to the shipment's originating data source and nothing else.** A purchase
  is keyed to a fulfillment order that exists in exactly one Shopify account, so a Shopify
  source is never a candidate for a shipment that did not come from it. This is already how
  `ShopifyShippingLabelService::dataSourceFor()` behaves — lift it into the general rule
  rather than leaving it an implementation detail of one adapter.
- **Amazon Buy Shipping binds the same way** — the order lives in one seller's account.
- **Superseded:** this issue originally placed Amazon Shipping on non-Amazon orders under
  `CarrierAccount` scoping. That path did not ship; ADR-0002's 2026-09-22 clarification now
  requires selection of an eligible connected Amazon `DataSource` instead.
- **One source is quoted per carrier by default.** Quoting several for the same carrier is
  opt-in, mirroring `CarrierAccountScope::rate_shop`, because each extra source is another
  API call on the packer's critical path.
- Ties resolve by the same priority ordering `resolveForShipment()` uses. An unresolvable
  tie is a configuration error surfaced to the operator, never an arbitrary pick.

**Correction found while implementing.** The tie cannot arise on the carrier-account arm.
`carrier_account_scopes` is unique on `(carrier_id, location_key, client_key)`, so each of
the four precedence bands holds at most one scope and the walk has nothing to arbitrate —
the schema, not a runtime check, is what makes "never an arbitrary pick" true there, and
the test asserts the constraint rather than a branch that cannot fire. The reachable tie is
a carrier account scoped to a resale channel's `Carrier` row: Shopify holds one so its
offers have services to hang off, and an account scoped to it would have two sources
claiming one carrier. That is what surfaces to the operator.

## Acceptance criteria

- [x] A shipment imported from Shopify source A never resolves to Shopify source B
- [x] A shipment with no Shopify origin resolves to no Shopify source at all
- [x] Client-scoped sources take precedence over global ones, matching existing carrier
      account behavior
- [x] Only one source per carrier is quoted unless rate shopping is explicitly enabled
- [x] An unresolvable tie surfaces to the operator rather than picking silently
- [x] Tests cover the Shopify binding and the precedence chain

## What shipped

PR #167 (`8a90383`, `9dcad47`), merged 2026-09-03. `PostageSourceResolver`, in the two arms
ADR-0002 decision 9 describes, kept separate because they answer different questions rather
than one question twice: channel postage *binds* to the shipment's origin, carrier accounts
*resolve* by scope. `ShopifyShippingLabelService::dataSourceFor()` now asks the resolver
instead of holding the rule itself, which is what this slice was for — lifting an
implementation detail of one adapter into the general rule.

`PostageSourceResolution` carries candidates and conflicts, so an unresolvable tie comes back
as an operator-readable message rather than a silent pick or an exception.

**Nothing quotes through it yet.** `resolve()` has no production caller; rates still dispatch by
carrier name, and `channelSourceFor()` is the half in live use. That is the expected state — the
issue says as much up front, and the Amazon Buy Shipping adapter is the first consumer. It does
mean the one-source-per-carrier and rate-shop criteria are proven by
`PostageSourceResolutionTest` and by the unique index, not by a live quoting path.

### The review gate: `directAdapterFor()`, not `policyFor()`

`9dcad47` tightened the carrier-account arm after review. The first version asked
`CarrierRegistry::policyFor()`, which is the weaker predicate: holding a carrier's *policy* is
not holding an *account* with it. A candidate is a claim that we can buy here, and it has to
pair with `CarrierAccountPostageSource`, which resolves the same package through
`directAdapterOrFail()` when the label is later voided or tracked — so resolving on the looser
question would promise a packer an offer that throws on the way back out.

Two kinds of carrier row fail the tighter gate, and the conflict message names both: a resale
channel's row (Shopify holds one so its offers have services to hang off) and a policy-only row
(a courier Shopify picked, kept so its cutoffs and manifest behaviour come out right — ADR-0002
option D). No adapter registered today is policy-only, so the two predicates agree by luck
rather than by construction, which is the reason to state the stronger one.

### Rode along: PR #168

`65c87cb`, a distinct defect found while working on the precedence walk and fixed separately
because it is not about resolution. `carrier_account_scopes.carrier_id` is denormalized so the
unique index can enforce one account per `(carrier, location, client)`, but it was derived only
in the scope's own saving hook — which never fires when the *account* changes carriers. Move an
account from USPS to FedEx and its scopes keep `carrier_id = USPS`, so `resolveForShipment()`
hands a FedEx account to a USPS shipment and finds nothing for a FedEx one. Worse, the index
keys on the stale value, so it stops being the guarantee this slice's "the schema, not a runtime
check" reasoning leans on.

Three parts: the account restamps its scopes when its carrier changes (dropping, with a warning,
any whose slot is already held); the carrier select is fixed after create, since the credentials
on the same form are issued by that carrier and mean nothing to another; and
`2026_09_03_204500_restamp_drifted_carrier_account_scopes` repairs rows that drifted before the
hook existed. No drifted rows existed locally — the repair is for installs where somebody used
the select the UI had until then left open.

## Blocked by

- `01-record-postage-source-provenance`

## Follow-on

- `amazon-buy-shipping/03-amazon-buy-shipping-adapter` — the first consumer of `resolve()`
