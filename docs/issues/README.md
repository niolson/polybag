# Issue index

Conventions live in `../agents/issue-tracker.md`; the `Status:` vocabulary lives in
`../agents/triage-labels.md`. This file is the at-a-glance view of what is open.

**Resolved issues stay where they are.** `done` means "implemented and verified; kept
for history" — the implementation record in the file's `## Comments` section is the
point of keeping it. There is no archive directory, and adding one would give agents a
second place to look.

## Open work

| Directory | Open | State |
|---|---|---|
| [shopify-shipping-carrier](shopify-shipping-carrier/) | 9 of 15 | Adapter shipped 2026-08-31. `01` gates the purchase-path work and is `ready-for-human` — a label must be bought in the Shopify admin before the API will sell one. `09` **done** 2026-09-04 — Shopify is now a priceless offer beside the rates, behind a per-client opt-in and a confirmation, and out of every automated path; `10` shipped with `postage-source-split/11`. `08` **done** 2026-09-05 — the rollup now records how many of its packages reported a cost, so spend totals say what they exclude and cost-per-package divides by priced packages only; it spun out `12`, the same defect on the billing path, where the number is invoiced rather than displayed. `06` rescoped and **done** 2026-09-05 — the Shopify offer is withdrawn once another package on the shipment has shipped, enforced at purchase time as well as in the list, so a second label is never attempted against an already-fulfilled fulfillment order; its `fulfillmentOrderSplit` half spun out as `13`, blocked until PolyBag has a multi-package packing workflow at all. `11` partially implemented 2026-09-06 — the inference ladder, the versioned USPS service-type-code table (generated from USPS's published appendix, not transcribed), the label-text reader and the narrow inferred-service write path all landed with tests; it stays open because the coverage measurement it exists to produce needs real Shopify packages, and the purchase-time hook is deliberately unwired until there is a label to validate it against. It spun out `14`, the label evidence the token tables need across Shopify's nineteen carriers. `01` cleared its blocker 2026-09-08 — a label bought in the Shopify admin accepts the terms, and the first purchase through PolyBag then went through on Shopify's account, with Shopify choosing USPS Ground Advantage for an `auto` selection. It answered six of its ten questions and spun out `15`: the IMpb parser accepts only 22-digit numbers, so rung 1 of `11` declined the 26-digit number on that label and left `packages.service` null when the service type code decoded to exactly what the label said, and `16`: `ShippingLabel.trackingInfo.company` is Shopify's internal carrier code rather than a carrier name, so a Shopify-bought UPS label shipped with `carrier = "ups_shipping"` and no carrier of record — USPS resolving was a coincidence of spelling. Both **done** 2026-09-08: the IMpb parser now reads every valid barcode length and declines a string that reads two ways, and Shopify's carrier codes are translated where Shopify is known to be the source, with trademark signs folded out of alias lookup keys. The same purchase turned up the label price in the order event stream, recorded on `05`. |
| [amazon-buy-shipping](amazon-buy-shipping/) | 1 of 8 | Implements ADR-0003 (Accepted 2026-09-02). `01` **done** — production `getRates` returned 6 offers across OnTrac/UPS/USPS, so the multi-carrier premise holds. `04` **done** 2026-09-04, which unblocks Amazon sandbox work generally. `02` **done** 2026-09-04 — the offer and observed-service stores, which unblocks `03` and `05` and the deferred quote/purchase half of `postage-source-split/08`. `05` **done** 2026-09-05 — observed services can be aliased or promoted from *Map Carrier Services*, so OnTrac has a home before `03` starts returning it. `06` **done** 2026-09-05 — approval is a row per source/client/environment, deny by default, granted from the same page. `07` **done** 2026-09-05 — auto-ship, batch ship and pre-selected rates all select through `RateSelector::selectForAutomation()`, which withholds an unapproved discovered service and names it; the Ship page still lists it for a person to choose. `03` **done** 2026-09-05 — the adapter quotes, buys, voids and tracks through Amazon, keeping the real carrier per offer and stamping the observed-service identity on every rate; quoting and purchasing now dispatch by the offer's postage source, which discharges the deferral in `postage-source-split/08`. One acceptance criterion is deliberately unticked: nothing has been run against a live order yet. `08` is a `needs-triage` design question, and post-quote filtering — the option it names — is what shipped. |
| [data-source-improvements](data-source-improvements/) | 0 of 7 | All closed. `06` **done** 2026-09-07 — `docs/data-sources/database.md` is the Database driver reference: connection fields per driver, the read/write query contracts and their bound parameters, `RawSqlGuard`, `max_affected_rows`, the field-mapping defaults read off `DataSourceFactory::databaseConfigFor()` (not `FieldMapper`, which holds none), least-privilege `GRANT` examples run against real MySQL 8.4 and SQL Server 2022 instances, and one worked example. Writing it turned up a live bug: the Export Query helper text advertised a `:cost` parameter that is never bound, so any export query using it failed with `HY093` — the helper text now lists only what `PackageExportService` actually supplies. No PostgreSQL or SQLite round-trip was run, same gap `04` records. `05` **done** 2026-09-07 — *Test Queries* is now *Preview Queries*: it executes the read queries and shows the first rows raw and mapped, so a wrong field mapping is visible before an import runs; the write queries are still parse-checked only and never executed. `04` closed 2026-09-07 as **done** — it had shipped 2026-08-26 in `65e54cc` (a shared `ImportConnectionConfig`, plus the `pdo_pgsql`/`pdo_sqlsrv` the image was missing) four days after the 2026-08-22 premise re-verification, and nobody updated the ticket; no live round-trip against a real SQL Server or PostgreSQL instance is recorded anywhere, so that path is covered by unit tests but still unrun end to end. `07` **done** 2026-09-07 — FBA orders are excluded from import by default behind a per-source opt-in, and when imported they are badged and blocked from packing, batch shipping and export. Whether `SearchOrders` v2026-01-01 can filter server-side is still unanswered: no v2026-01-01 model is vendored to check against, so the filter is client-side by choice. |
| [carrier-request-schema-validation](carrier-request-schema-validation/) | 2 of 2 | Both `ready-for-agent`. Extends the pattern from #144/#145 to USPS and FedEx, whose specs cannot be vendored. |
| [fedex-sandbox-rate-testing](fedex-sandbox-rate-testing/) | 1 of 1 | `ready-for-human`. The FedEx sandbox answers most rate request shapes with truncated JSON, so `FedexAdapter::buildRateApiRequest()` replaces every sandbox rate request with a canned domestic one — which makes international rating unreachable there. Needs real sandbox requests to characterise before anything can be decided. Two international rate fixtures are already committed and skipped. |
| [postage-source-split](postage-source-split/) | 1 of 13 | Implements ADR-0002 (Accepted 2026-09-01). `01`–`12` all **done**, shipped 2026-09-02 to 09-04 as #155–#171. Only `13` is open, a `needs-triage` presentation question left behind by `07`. |
| [tech-debt](tech-debt/) | Phases 2–4 | A plan with checkboxes, not issue files — no `Status:` line, so it does not appear in the grep below. |

## Closed

| Directory | Issues | Closed |
|---|---|---|
| [nginx-upstream-resolution](nginx-upstream-resolution/) | 1 of 1 `done` | Shipped 2026-09-06. nginx resolves the app container at request time, so it follows `app` to a new IP instead of 502ing on the old address until someone restarts it. Docker's embedded DNS returns a 600s TTL for a container name, which is why the `valid=10s` cap is part of the fix rather than noise. |
| [special-services](special-services/) | 7 of 7 `done` | Shipped 2026-07-09, #72; review gate closed 2026-08-22. The four `*-api-reference.md` files and the cross-carrier report are `reference` — vendor capability tables worth keeping. |

## The grep

```bash
grep -rn '^Status:' --include='*.md' docs/issues \
  | grep -Ev 'Status: (done|reference|closed|wontfix)'
```

`reference` excludes PRDs and background findings, which are framing rather than work
items. Keep new `Status:` lines to the vocabulary in `triage-labels.md` so this keeps
working — a status line that opens with anything else shows up as open work.

## Work tracked elsewhere

Deployment and hosting work for the instances we operate ourselves is tracked privately
alongside that tooling, for the reason given in `docs/self-hosting.md`: none of it is
needed to run PolyBag. Two directories here are the app half of a cluster whose other
half is private — `nginx-upstream-resolution` (the deploy-side follow-on) and
`shopify-shipping-carrier` (the Dev Dashboard scope rollout). Each says so where it
matters. Nothing open in this index is blocked on anything in that repo.
