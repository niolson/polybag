# Normalize the service and snapshot it at ship time

Status: needs-triage

Repo: `polybag`

## Parent

The service twin of [`03`](03-normalize-carrier-of-record.md). ADR-0002 decision 5 made
`packages.carrier` free text with a normalized identity beside it, snapshotted at ship
time; `packages.service` got the free-text half and never the normalized half. ADR-0003
decision 7 then gave the service *evidence* (`confirmed` / `inferred` / `unknown`) but,
again, no identity.

Raised 2026-09-18 while narrowing the Packages list: the Service column was the widest
thing on the page.

## Problem

`packages.service` is whatever the postage source called it, and the sources do not agree
on what to call the same thing:

| Source | What lands in `packages.service` for USPS Ground Advantage |
|---|---|
| Direct USPS | the rate `description` verbatim: `USPS Ground Advantage Machinable Cubic Non-Soft Pack Tier 1`, `… Tier 2`, `… Machinable Single-piece`, and so on — one string per (mail class × processing category × rate indicator) |
| Amazon Buy Shipping | Amazon's catalog name, or the `CarrierService` name once the observed service is promoted or aliased on *Map Carrier Services*. Amazon splits Ground Advantage four ways — `(less than 1lb)`, `(1 - 70 lb)`, `(Customs)`, `Cubic` — each its own `serviceId` |
| Shopify Shipping | nothing confirmed; `ServiceInferrer` writes `USPS Ground Advantage` from the label barcode, or the service stays unknown |
| `CarrierService` catalog | `Ground Advantage` (code `USPS_GROUND_ADVANTAGE`), with no leading `USPS` |

Four spellings of one service, and the catalog's own name is a fifth. Direct UPS and FedEx
happen to store names that match the seeded catalog rows, so they look normalized, but only
because the adapter and the seeder were written by the same hands.

Consequences today:

- The Packages list Service column shows the longest of these and, until 2026-09-18, set
  the table's width. It now wraps, which is a bandage.
- The Service filter on that page is `SELECT DISTINCT service`, so filtering for Ground
  Advantage means picking the USPS variants one at a time and missing the Amazon and
  Shopify spellings entirely.
- Nothing can group packages by service across sources: no report does today, and none
  can be written until this exists.
- The USPS detail — cubic tier, machinable, single-piece — is real information that a
  packer or an auditor wants on the package page, and it is only available fused into the
  display name. The shortened list column and the detailed view page the request asks for
  are two readers of one field that has to carry both.

The structured parts are not kept. On a direct USPS purchase the adapter reads `mailClass`,
`processingCategory` and `rateIndicator` off the selected rate to build the label request,
and none of the three is written to the package: `metadata` and `carrier_request_payload`
are null on a shipped cubic package inspected locally. `14` covers why direct rates have no
server-side row to restore from.

## What to build

An optional normalized service identity beside the preserved raw value, **snapshotted
onto the package when it ships**, exactly as `03` did for the carrier.

- `packages.normalized_carrier_service_id`, nullable FK to `carrier_services`, written in
  the same optimistic-lock update as `service` in `Package::markShipped()` and in
  `recordInferredService()`, cleared by `clearShipping()` alongside the rest of the
  projection. The `package_labels` row gets the same column, since it is the record and
  `packages` is the projection (ADR-0004).
- Resolution per source, in the adapter or workflow that already has the identity in
  hand — not by parsing the display string after the fact:
  - **USPS**: `mailClass` is already the catalog `service_code`.
  - **UPS / FedEx**: the rate's `serviceCode` is already the catalog `service_code`.
  - **Amazon**: the observed service's `carrier_service_id` when it has been promoted or
    aliased (`amazon-buy-shipping/05`); null while unmapped, which ADR-0003 decision 8
    says is a valid terminal state.
  - **Shopify / inferred**: `ServiceInferrer` emits the strings in
    `resources/data/service-inference/ruleset.json` (`USPS Ground Advantage`,
    `UPS Ground` …). Either the ruleset gains a `service_code` per selection or the
    inferrer resolves its output through the same normalizer; the former is smaller and
    keeps the ruleset the single vocabulary.
- Resolving to nothing is a valid outcome. An unrecognised service normalizes to null
  and the package still ships.
- Snapshot, never recompute on read: an alias or catalog edit must not rewrite what a
  past package, export or report meant. Same rule as `03`.
- A `CarrierService` referenced by a shipped package cannot be deleted.

Then the readers:

- Packages list: show the normalized name when there is one, the raw string when there is
  not, with the raw string on hover either way. Drop the `->wrap()` bandage.
- Service filter: a `SelectFilter` over `carrier_services`, plus an "unmapped" option
  that matches `normalized_carrier_service_id IS NULL`. The distinct-strings filter goes.
- `ViewPackage`: both. "Ground Advantage" and, beneath it, the source's own name.
- `PackageExportService` keeps publishing `confirmedService()` — the raw string — since
  a channel expects what the carrier said, not our catalog's spelling. Nothing here
  changes what an export contains.

### Keep the rate detail too

Whether to persist the USPS `rateIndicator` / `processingCategory` (and the FedEx
`isOneRate`, UPS packaging code) as their own columns rather than leaving them fused into
the raw string is a triage call. Arguments for: the raw string is display text that USPS
could reword; the indicator is what the label SKU carries; `packaging-form-and-carrier-identity/05`
already classifies on it. Argument against: it is `14`'s job to make direct-carrier
purchase data server-side, and this would be a second, narrower way of keeping it. If
`14` lands first, the snapshot reads off the restored rate and this question answers
itself.

### Backfill

`03` deliberately did not backfill. This one probably should, because the readers above
are list and filter, and a list where last month's packages are all "unmapped" is worse
than today's. For direct USPS the raw string starts with the mail-class name — a prefix
match against the four USPS catalog names covers every variant seen locally. UPS and FedEx
match on name equality. Amazon rows resolve through the observed-service mapping where one
exists. Anything else stays null. Run once as a command, idempotent, reported per source.

## Not this issue

- Amazon's per-weight-band and cubic `serviceId`s are separate observed services and
  stay that way; whether they are all aliased to one `Ground Advantage` row is a mapping
  decision an operator makes on *Map Carrier Services*, not a rule here.
- Shopify cubic pricing. Shopify reports neither service nor price on a bought label
  (ADR-0003), so whether a parcel was priced cubic is unobservable from this side; the
  inferred service carries no tier and never will.
- Reporting that groups by service. That is what this unblocks, not what it builds.
- A display-only shortening of the USPS string in the list column was considered and
  skipped 2026-09-18 in favour of doing this properly.

## Acceptance criteria

- [ ] Raw `service` string is preserved unchanged on every package and every label row
- [ ] A normalized identity is written at ship time and on inference, and never
      recomputed on read
- [ ] Editing a catalog row or an alias does not change any already-shipped package
- [ ] An unrecognised service normalizes to null and the package still ships
- [ ] Direct USPS variants (`Machinable Single-piece`, cubic tiers 1–3, `Nonstandard`) all
      snapshot to `USPS_GROUND_ADVANTAGE`; the same holds for Priority Mail and Express
- [ ] An Amazon offer bought through a promoted or aliased observed service snapshots to
      that row; an unmapped one snapshots to null
- [ ] An inferred Shopify service resolves to the same row a direct purchase would
- [ ] Packages list shows the catalog name and keeps the raw string on hover; the Service
      filter selects by catalog row and offers "unmapped"
- [ ] `ViewPackage` shows both names
- [ ] Channel exports are byte-for-byte unchanged
- [ ] Backfill command is idempotent and reports resolved / unresolved counts per source

## Blocked by

None. Cheaper after `14` (the snapshot then reads off a server-restored rate), and
`amazon-buy-shipping/05` is already in place for the Amazon leg.
