# Project review after the September feature and refactor work

Status: reference

Repo: `polybag`

## Why

September 2026 merged 124 pull requests: the postage-source split, the carrier catalog
reset, label history, packaging identity, and Amazon Shipping sold to other channels.
Each pull request was reviewed on its own diff. This review reads whole flows across
them, because the defects most likely to survive per-diff review sit where two
separately correct changes meet.

## How it is organised

The review runs in areas, each a path money or data takes through the app rather than
a directory:

| Area | Covers | State |
|---|---|---|
| A | Label purchase — Ship page, `ShippingRateService` offer issuance, `OfferStore`, `EloquentPackageShippingWorkflow`, `BatchLabelService` / `GenerateLabelJob` | Reviewed 2026-09-28; issues `01`–`05` |
| B | Label lifecycle — reprint, void, tracking and manifest dispatch, Shopify fulfillment sync | Reviewed 2026-09-28; issues `06`–`10` |
| C | Postage sources and carrier adapters | Reviewed 2026-09-28; issues `11`–`15` |
| D | Client and location scoping, authorization | Reviewed 2026-09-28; issues `16`, `21` |
| E | Automation — rules, allowance, batch selection | Reviewed 2026-09-28; issues `17`–`20` |

Each area is checked against the invariants its ADRs and `CONTEXT.md` state, not
against a general checklist. Area A's:

1. The browser names an Offer and restates nothing (ADR-0002 decision 4).
2. An Offer is bound to its Package, quote inputs, source instance, environment and
   billing identity, and expires.
3. An Offer is claimed atomically; it cannot be spent twice.
4. An ambiguous purchase leaves the Offer awaiting confirmation, and nothing more is
   bought for the Package until it is recovered or resolved (`CONTEXT.md`).
5. A purchase that succeeded at the source and failed here is found, not repeated.
6. `markShipped()` writes the Label row in its transaction; a Package has at most one
   active Label (ADR-0004).
7. With no shipping method, nothing is bought (ADR-0006 decision 12).
8. Automation buys only within the allowance.

## How findings are verified

Every finding is one of:

- **Confirmed** — a Pest test asserting the required behavior fails against `main`.
  The test is in the issue and becomes the regression test of the fix. The probes are
  not committed as failing tests.
- **Plausible** — the code plainly allows it, but no test reaches it cheaply; the issue
  gives the exact scenario.

Candidates that did not hold up are listed below so they are not re-investigated.

## Area A: what held

Invariants 1, 2, 3, 6 and 7 hold on the attended path. In particular: `ship()` refuses a
rate with no offer; `rateFromOffer()` rebuilds carrier, service, price and metadata from
the row; `redeem()` claims with one conditional `UPDATE`; the quote fingerprint and the
carrier-account fingerprint retire an offer whose inputs or payer changed; the per-package
and per-shipment blind-purchase locks close the double-click and sibling-package races;
and `buyPostage()` re-reads status and shipping method from the database rather than the
page's stale instance.

Invariants 4 and 5 hold only while the Offer row survives and exists, which is where
the findings are.

## Area A: candidates dropped

- *A direct Offer with no expiry.* `shipDatesFor()` returns a date for every direct and
  Amazon Shipping task; only Buy Shipping gets none, and its offers carry Amazon's own
  window.
- *The Ship page's public `rateOptions` array is tamperable.* It is, but only the offer
  identifier is read from it, and `inspect()` rejects an offer belonging to another
  Package.

## Area B: invariants

Area B's are from ADR-0002, ADR-0004 and `CONTEXT.md`:

1. Void, tracking and manifesting follow the postage source that bought the Label, not
   the carrier of record. For a direct Label, "who bought it" means the account it was
   bought on.
2. A void marks the Label voided and returns the Package to `Unshipped`, and the Package
   can then be shipped again as if new.
3. `Shipped` ⇔ exactly one active Label, and only the four writers change either side.
4. Only the active Label is printed; a voided Label is never reprinted.
5. Only direct-account Labels go on a manifest of ours.
6. A Shopify Shipping void happens in the Shopify admin and reaches PolyBag through the
   fulfillment synchronizer.

## Area B: what held

Invariants 3, 4, 5 and 6 hold. `clearShipping()` locks the Package row, guards on
`status = shipped`, voids exactly one Label and asserts the pair before commit.
`markLabelPrinted()`, `recordInferredService()` and `withdrawInferredService()` keep the
same lock order and refuse a zero-row Label update. Reprint (`labelForReprint()`, the
print acknowledgment, the batch print) reads only a shipped Package's current
`label_data`, so nothing can print a voided Label. `ManifestService::createManifest()`
refuses any non-direct Package, and `getUnmanifestedPackages()` filters on
`boughtOnCarrierAccount()`. The dispatcher routes Shopify and Amazon void and tracking by
the recorded `postage_data_source_id`, and refuses rather than falls back for a source it
doesn't recognize. The synchronizer finds a fulfillment by tracking number, and one
without a match is no answer rather than someone else's.

Invariant 1 holds for channel postage and for the manifest, but not for a direct void or
tracking (`06`). Invariant 2 holds for the Package's shipping columns, but not for three
things kept outside them: its manifest (`07`), the sales channel's copy of the tracking
number (`09`), and a void the source accepted but PolyBag failed to record (`10`). The
void action itself isn't authorized (`08`).

## Area B: candidates dropped

- *A tracking refresh writes a stale answer onto a re-shipped Package.*
  `TrackingService::record()` saves without re-checking the tracking number, but a void
  and a re-ship would both have to finish inside one 30-second carrier call, and the
  only answer a just-voided number can give is pre-transit.
- *Overlapping Shopify syncs void a re-shipped Label.* Only possible if two runs overlap,
  and the schedule has `withoutOverlapping()`.
- *A print acknowledgment lands on a re-shipped Label.* The QZ acknowledgment is keyed by
  Package, but it arrives seconds after the print, well before a void and re-ship could
  complete.
- *Rate-shop scopes buy on a second account that void then misses.* `resolve()` quotes
  only the first account a `rate_shop` scope returns, so today no Label is bought on the
  second. `06` covers the scope-change route to the same failure.

Not filed: `ShopifyPostageSource::VOID_MESSAGE` tells the operator to "void it here" after
cancelling in the Shopify admin, but the void here always fails for a Shopify Label. The
synchronizer un-ships it on its own, and the table tooltip says so correctly.

## Area C: invariants

From ADR-0002 decisions 4–9, ADR-0003 and ADR-0006 decisions 1–4 and 10:

1. Channel postage (Shopify, Amazon Buy Shipping) binds only to the Shipment's originating
   connection. Amazon Shipping for other channels is chosen by a scope row and is never
   sold for an Amazon order.
2. A method's source policy decides which sources are asked; the connection's postage
   setting only narrows.
3. A service's properties (PO Box, military, contents) bind every source that sells it.
4. An adapter's answer to a purchase is either a decline (the source sold nothing) or an
   unknown outcome that stays unresolved for recovery. A source that can be asked about a
   purchase never turns an unknown into a decline.
5. The carrier of record is the physical carrier, normalized for behavior and snapshotted
   when the Label is bought; normalization runs before any carrier-policy lookup
   (ADR-0002 decisions 1 and 5).
6. Observation, normalization and the allowance stay separate; discovery never creates
   catalog rows, and a mapping names the same service (ADR-0003 decision 2, ADR-0006
   decision 2).
7. A hard-required special service excludes an offer that can't honor it, judged per
   offer for Amazon; a default is a preference and never excludes (ADR-0002 decision 8).
8. A void or tracking answer is read from what the source said, not only from its HTTP
   status.

## Area C: what held

Invariants 1–3. `PostageSourceResolver::channelSourceFor()` returns only the Shipment's own
active connection and never a second account; `offAmazonShippingSourceFor()` refuses an
Amazon order by connection or by recorded order ID; rating asks Shopify and Amazon only
with the method's policy row. PO Box and military flags filter direct services before
quoting, Shopify's specific services come from the same filtered list, and Amazon offers
are filtered on their mapped service. For invariant 4, all three recoverable adapters
let transport errors and 5xx through as intended.

Invariant 5 holds for everything but a mapped Amazon offer (`15`). `markShipped()`
normalizes `ShipResponse::$carrier` inside its transaction and writes
`normalized_carrier_id` to the Package and the Label together; the export reads the
snapshot through `carrierOfRecordName()`; End of Day reads `normalized_carrier_id`; ship
dates come from the `carrier_id` stored on the Offer when it was issued, so a rename
between quote and purchase changes nothing.

Invariant 6 holds apart from `15`. The recorder only inserts `observed_services` rows, with
`insertOrIgnore` settling the concurrent-quote race, never touching `Carrier` or
`CarrierService`; the mapper is reached only from the page, and a mapping is one
`source_service_mappings` row.

Invariant 7 holds for direct, Shopify and off-Amazon Amazon Shipping: direct consults
carrier policy and catalog scoping, Shopify is `Unguaranteed` and so excluded, and the
off-Amazon adapter reports `NotImplemented` for every code, so a required service excludes
it and a default is dropped. Buy Shipping judges each offer on its own value-added service
groups, as the ADR asks, but counts defaults as required (`14`).

Invariant 4 fails after a 2xx: USPS and UPS turn a response they cannot read into a
decline (`11`). Amazon stamps its shipment ID first and avoids that half. FedEx declines
deliberately, since it bills only on tender and has no lookup to recover by.

Invariant 8 fails for FedEx twice: a cancel reply saying nothing was cancelled is
recorded as a void (`13`, with UPS and USPS reading status only), and "out for delivery" and
"delivery exception" read as the terminal Delivered (`12`). Amazon's tracking reads only
its summary vocabulary and reports an unknown status as no answer; USPS and UPS match the
past tense.

## Area C: candidates dropped

- *Shopify `auto` is offered for a PO Box destination.* Shopify picks the carrier and is
  responsible for reaching the address; nothing on our side can check it, and a specific
  Shopify service is already filtered.
- *FedEx retries without Saturday delivery, even when it is required.* Both rating and
  purchase drop Saturday when FedEx rejects it. Saturday is opportunistic by design across
  every adapter (`HasSaturdayDelivery` strips it on ineligible days), so it is not a
  guaranteed service in the ADR's sense.
- *A FedEx 2xx with a tracking number but no label is a decline.* The shipment exists, but
  FedEx bills only on tender, so the retry costs nothing, unlike `11`.
- *USPS void reads `shipments.country` where purchase reads `validated_country ?? country`.*
  They could pick different endpoints, but no validator writes `validated_country`, so the
  two agree in practice.
- *`CarrierNormalizer::resolve()` loads every carrier per call.* Correct, and small; the
  Amazon adapter already memoizes it per quote.

## Area D: invariants

Users have a role and a home location but no client, so every user sees every client by
design. Client scoping here is about correctness, not visibility. From `AGENTS.md` and
ADR-0006 decisions 5–6:

1. Each resource, page and action is gated by role. Operational credentials (carrier
   accounts, connections, settings) and the source policy and service mappings are
   Admin-only.
2. A carrier account resolves from the Shipment's client and the Package's location.
3. Products, aliases, shipping rules and pick batches match only within their own client.
4. End of Day respects location when multi-location is on (`AGENTS.md` asks this of End
   of Day only; pick batches are scoped by client).

## Area D: what held

Every custom page carries a role gate matching `AuthorizationTest`. Connections, clients,
label batches and pick batches gate their resources with `canAccess()`, and the source
policy has its own Admin-only policy. The OAuth callback refuses anyone below Admin.
Scan-to-add looks up a product within the Shipment's client, and
`EloquentPackageDraftWorkflow` re-checks every packed item from the browser: the shipment
item must belong to the Shipment, the product must match it, and a scan-to-add product
must belong to the Shipment's client. `RuleEvaluator` applies a rule only to its own
client or to rules with no client.

Invariant 1 fails for carrier accounts (`16`). Area B's `08` (voids) is the same kind of
gap on an action rather than a resource.

## Area D: second pass

Invariant 4 holds. End of Day and `ManifestService` filter by location when multi-location
is on; the page's location is a deliberate picker, not a hidden value. Pick batches keep to
one client (`PickBatchService` filters and resolves a single `client_id`). Channel and
shipping-method aliases are read per importing client (`ImportReferenceResolver`).

Every action on the Shipment and Package screens was checked. Create, edit and delete go
through `ShipmentPolicy` / `PackagePolicy`, and the item relation managers are editable
only on Edit pages, which the policies guard. The custom actions are the
Ship/Pack/Edit links, reprint (active Label only, area B), track and address validation.
Void is `08`.

Per-client export overrides were removed on purpose (`4e5d75d`), but the export docblock and
`AGENTS.md` still describe them (`21`).

Dropped: *A User can run address validation*, which may cost a billed lookup. Packers
need it, and it changes only the validation fields.

## Area E: invariants

From ADR-0006 decisions 5–8 and 11–12, `amazon-buy-shipping/17` and `CONTEXT.md`:

1. Automation buys only within the allowance: a source the method has a row for, a
   service the method lists or the row's *any service*, never a deactivated service or
   carrier, and nothing for a shipment with no method.
2. The connection's postage setting only narrows; *packer only* keeps channel postage
   off every unattended path.
3. A *Use* rule picks only within the allowance and grants nothing. An *Exclude* rule
   applies to the Ship page and to automation alike.
4. A blind purchase never enters a price comparison. Automation buys one only when a rule
   names it or it is the method's sole eligible choice.
5. A content-restricted offer (Media Mail for a Package that doesn't qualify,
   `USPS_PTP_BPM`) is never bought unattended.
6. The method's on-time and OTDR requirements hold against everything automation buys,
   a rule's choice included.
7. Batch ship selects only shipments it can finish, and buys one label for what is left
   to ship.

## Area E: what held

Invariants 1, 2, 3 (for *Use*) and 5 hold. `RateSelector::selectForAutomation()` is the
single gate every unattended rate passes, a rule's pre-selected rate included. It holds
back, in order: content-restricted rates, Buy Shipping held by a *packer only*
connection, rates naming an inactive service or carrier (`InactiveCatalog`), and anything
outside the method's rows and active listed services. Unpriced rates never win.
`MethodSourceAllowance::permits()` skips a *Use* rule naming a source or service the
shipment's method does not allow, and nothing is picked for a shipment with no method.
A rule-selected blind purchase is refused when the connection's setting stops short of
automation, and falls through to rate shopping. The sole-choice inference counts
configured sources, not the ones that answered, so an outage doesn't make Shopify the
fallback. A pre-selected Media Mail rate still goes through `ContentsFilter`. Rules match
only their own client or none. Batch ship refuses shipments with no method,
unpicked shipments where picking is required, FBA orders and shipments with an unshipped
Package. `GenerateLabelJob` keeps a Package with an unresolved Offer.

Invariant 3 fails for *Exclude* against a rule's pre-selected direct rate (`17`).
Invariant 6 fails twice: a UPS or FedEx *Use* rule is judged on a placeholder with no
delivery date and refused as late on any order with a due-by date (`18`), and a blind
purchase skips the requirement entirely (`20`, which needs a decision). Invariant 7 fails
for a partly shipped order (`19`).

## Area E: candidates dropped

- *The Ship page can buy an excluded offer by naming it.* `getShippingRates()` issues an
  Offer for every quoted rate before `prepareRates()` drops the excluded ones, and
  `ship()` doesn't re-check exclusions, whereas `resolveBlindOffer()` does for blind
  offers. But an excluded offer's opaque ID never reaches the browser, so nobody can name it.
- *Rules with equal priority are evaluated in no defined order.* `scopeActive()` sorts on
  `priority` only, and every new rule is created at `0` until someone drags the table,
  so ties are the normal case. In practice MySQL returns ties in id order, the same
  order the table shows, so what an Admin sees is what runs. Adding `orderBy('id')`
  to `scopeActive()` and the table's sort would guarantee it. Not filed.
- *Exclude rules ranked after the matching Use rule are ignored.* By design: priority is
  first-match, and `RuleEvaluatorTest` pins it.
- *Batch validation and draft creation race a packer.* Both run in one request. A packer
  who opens the Shipment afterwards resumes the batch's draft rather than creating a
  second, and `buyPostage()` re-reads the Package status before buying.
