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
| [shopify-shipping-carrier](shopify-shipping-carrier/) | 9 of 25 | Adapter shipped 2026-08-31; Shopify is a **blind purchase offer** beside the rates (`09`), behind a per-client opt-in and out of every automated path, with the service **inferred at purchase** (`11`) and the cost left null. `01` cleared the terms-of-service gate 2026-09-08 and closed 2026-09-09 having answered or moved all ten of its questions; `02` catalogued seventeen `carrier:service` pairs and established a **free rate-existence probe**. Running the real path found five defects, all now fixed — `15`, `16`, `19`, `20`, `21`/`18`, and `22` on top of `18`. Customs-form storage and printing shipped 2026-09-10 with Shopify and UPS feeding them (`07`, `23`, `24`). `26` shipped 2026-09-11: a zero-value customs line is refused before the purchase, per line, in front of every adapter. **What is open:** `12`'s charging half (waits on the billing owner), `11`'s coverage measurement and `14`'s remaining seventeen carriers (wait on real Shopify volume and the install-base question), `07`'s pre-purchase gate (waits on `23`'s Amazon row), `05` (build last), and two `needs-triage` items — `13`, `25`. `17` waits on a real store; `04` is `wontfix`. See [Working order](#working-order--shopify-shipping-carrier). |
| [amazon-buy-shipping](amazon-buy-shipping/) | 2 of 10 | Implements ADR-0003 (Accepted 2026-09-02). `01` **done** — production `getRates` returned 6 offers across OnTrac/UPS/USPS, so the multi-carrier premise holds. `04` **done** 2026-09-04, which unblocks Amazon sandbox work generally. `02` **done** 2026-09-04 — the offer and observed-service stores, which unblocks `03` and `05` and the deferred quote/purchase half of `postage-source-split/08`. `05` **done** 2026-09-05 — observed services can be aliased or promoted from *Map Carrier Services*, so OnTrac has a home before `03` starts returning it. `06` **done** 2026-09-05 — approval is a row per source/client/environment, deny by default, granted from the same page. `07` **done** 2026-09-05 — auto-ship, batch ship and pre-selected rates all select through `RateSelector::selectForAutomation()`, which withholds an unapproved discovered service and names it; the Ship page still lists it for a person to choose. `03` **done** 2026-09-05 — the adapter quotes, buys, voids and tracks through Amazon (the buying half now known to send an invalid body, see `10`), keeping the real carrier per offer and stamping the observed-service identity on every rate; quoting and purchasing now dispatch by the offer's postage source, which discharges the deferral in `postage-source-split/08`. One acceptance criterion is deliberately unticked: nothing has been run against a live order yet. `08` is a `needs-triage` design question, and post-quote filtering — the option it names — is what shipped. `09` opened 2026-09-11, `ready-for-human`: one international quote-and-purchase to fill `shopify-shipping-carrier/23`'s Amazon row and discharge `03`'s live-run criterion — and to build what the schema says is missing for a cross-border parcel: `requiresAdditionalInputs` is ignored, `CUSTOM_FORM` is neither requested nor read, and there is no documents-endpoint request. Same day: the sandbox was ruled out (it ignores `shipTo`), and the account's only non-US orders — to Puerto Rico, Guam, the Northern Marianas and American Samoa — all quoted as USPS-domestic with nothing to ask for, which observed `23`'s over-block through Amazon on four territories. A buy-and-void was then attempted and found **`10`**: the adapter's purchase body has never been valid — `needFileJoining: false` where every rate offers only `true`, `PACKSLIP` omitted where every PDF spec marks it mandatory, and USPS's required Confirmation group answered with a `NO_CONFIRMATION` it does not offer — established by a validation oracle (Amazon checks the body before the order, and a shipped order fails at the order), so nothing was bought. **Fixed the same day** and confirmed against the oracle — the adapter's PDF and ZPL bodies now reach the order check; the joined pack-slip-plus-label document a PDF purchase will then receive is still unobserved. With a valid body, Amazon refused both a 2025-07 and a 2025-11 order as already shipped. **`09` is blocked on placing an unshipped foreign order** in the seller account; `03`'s live-run criterion stays open until an unshipped order exists. |
| [data-source-improvements](data-source-improvements/) | 0 of 7 | All closed. `06` **done** 2026-09-07 — `docs/data-sources/database.md` is the Database driver reference: connection fields per driver, the read/write query contracts and their bound parameters, `RawSqlGuard`, `max_affected_rows`, the field-mapping defaults read off `DataSourceFactory::databaseConfigFor()` (not `FieldMapper`, which holds none), least-privilege `GRANT` examples run against real MySQL 8.4 and SQL Server 2022 instances, and one worked example. Writing it turned up a live bug: the Export Query helper text advertised a `:cost` parameter that is never bound, so any export query using it failed with `HY093` — the helper text now lists only what `PackageExportService` actually supplies. No PostgreSQL or SQLite round-trip was run, same gap `04` records. `05` **done** 2026-09-07 — *Test Queries* is now *Preview Queries*: it executes the read queries and shows the first rows raw and mapped, so a wrong field mapping is visible before an import runs; the write queries are still parse-checked only and never executed. `04` closed 2026-09-07 as **done** — it had shipped 2026-08-26 in `65e54cc` (a shared `ImportConnectionConfig`, plus the `pdo_pgsql`/`pdo_sqlsrv` the image was missing) four days after the 2026-08-22 premise re-verification, and nobody updated the ticket; no live round-trip against a real SQL Server or PostgreSQL instance is recorded anywhere, so that path is covered by unit tests but still unrun end to end. `07` **done** 2026-09-07 — FBA orders are excluded from import by default behind a per-source opt-in, and when imported they are badged and blocked from packing, batch shipping and export. Whether `SearchOrders` v2026-01-01 can filter server-side is still unanswered: no v2026-01-01 model is vendored to check against, so the filter is client-side by choice. |
| [carrier-request-schema-validation](carrier-request-schema-validation/) | 0 of 2 | Extends the pattern from #144/#145 to USPS and FedEx, whose specs cannot be vendored, so the schemas are hand-written off our own adapters. `01` **done** 2026-09-11 — `uspsLabel.json` covers both the domestic and international label bodies, wired into seven adapter tests and guarded by 32 of its own; breaking the adapter on purpose produced a five-line failure naming each field. It surfaced one reachable gap: a shipment with no name and no company builds a nameless `toAddress` the adapter sends as-is, which is the shape of the production rejection that started this. `02` **done** 2026-09-11 — `fedexShip.json` covers the `CreateShipment` body, wired into all eleven adapter closures plus two new ship tests (sub-pound SmartPost, ZPL One Rate with Saturday delivery) and guarded by 72 of its own; it also pins the special-service ↔ detail and indicia ↔ endorsement pairings. Four reachable gaps recorded, none a defect in a body the suite sends: a nameless contact, a null state or postal code, a sub-inch dimension cast to 0, and a null account number. Both issues closed. |
| [fedex-sandbox-rate-testing](fedex-sandbox-rate-testing/) | 1 of 1 | `ready-for-human`. The FedEx sandbox answers most rate request shapes with truncated JSON, so `FedexAdapter::buildRateApiRequest()` replaces every sandbox rate request with a canned domestic one — which makes international rating unreachable there. Needs real sandbox requests to characterise before anything can be decided. Two international rate fixtures are already committed and skipped. |
| [postage-source-split](postage-source-split/) | 1 of 13 | Implements ADR-0002 (Accepted 2026-09-01). `01`–`12` all **done**, shipped 2026-09-02 to 09-04 as #155–#171. Only `13` is open, a `needs-triage` presentation question left behind by `07`. |
| [tech-debt](tech-debt/) | Phases 2–4 | A plan with checkboxes, not issue files — no `Status:` line, so it does not appear in the grep below. |

## Working order — shopify-shipping-carrier

Sequenced 2026-09-08, revised 2026-09-09 and condensed 2026-09-11. Each issue file carries
the reasoning for its own position; this is the order.

**The premise the original ordering rested on is gone.** `01` was written when a label cost
real postage, so everything downstream was batched behind one expensive purchase. On a
development store these are **test labels** — nothing is charged and nothing ships — which
makes the experiments the cheap work and inverts most of the queue. What is expensive now is
only what needs a parcel to physically move.

### Open, in order

1. **[`12`](shopify-shipping-carrier/issues/12-client-billing-invoices-unpriced-postage-as-zero.md)'s
   charging half.** The flag half shipped 2026-09-08, so an invoice can be reconciled before
   it goes out; `line_total` still under-bills by the missing postage. **Blocked on the
   billing owner**, and better answered after `05`.
2. **[`11`](shopify-shipping-carrier/issues/11-infer-the-service-from-the-label.md) items 2–4
   and [`14`](shopify-shipping-carrier/issues/14-label-evidence-to-gather.md).** The ladder,
   both tables and the purchase-time hook are built and running against real labels. What
   remains is the **coverage measurement** ADR-0003 asks for, which needs Shopify packages to
   run over, and tokens for the other seventeen carriers, which is gated on the install-base
   question. Both US carriers are finished and neither wants more labels. `11` item 5 (FedEx
   `IP`/`XQ`) and the UPU S10 question in `14` are independent and pickable any time.
3. **[`07`](shopify-shipping-carrier/issues/07-customs-form-printing.md)'s pre-purchase
   gate**, which needs
   [`23`](shopify-shipping-carrier/issues/23-which-carriers-return-a-separate-customs-document.md)'s
   Amazon row — one international Amazon purchase, now
   [`amazon-buy-shipping/09`](amazon-buy-shipping/issues/09-international-purchase-and-customs.md).
   Storage and printing already shipped.
   Gating on `requiresCustomsDeclaration()` today would refuse APO/FPO and territory labels
   on workstations that never needed a report printer, which `23` confirmed rather than
   suspected.
4. **[`05`](shopify-shipping-carrier/issues/05-shipping-label-cost-reconciliation.md)** —
   build last, but gather its evidence (`Order.events` after every purchase) in the course of
   everything above. Then return to `12`.
5. **Two `needs-triage` items**, neither blocking anything:
   [`25`](shopify-shipping-carrier/issues/25-the-ups-saturday-rejection-retry-is-unreachable.md)
   (dead code that has never functioned), and
   [`13`](shopify-shipping-carrier/issues/13-split-shopify-fulfillment-orders-per-package.md)
   (blocked on a multi-package packing workflow that does not exist).

**Not scheduled.**
[`17`](shopify-shipping-carrier/issues/17-questions-a-test-label-cannot-answer.md) waits on a
real store shipping a real parcel — two of its three questions come free with the first one
anybody makes, so it is watching rather than working. `04` is `wontfix`, reopening if the
15-minute void window ever costs someone something.

**Two open inputs this repository cannot supply.** Which carriers our install base actually
ships with, which gates the other seventeen in `14`; and the billing owner's answer on what
an unpriced line should charge, which gates `12`.

### What the campaign settled

The experiments were run as one campaign on the development store, because each label is free
and the evidence overlaps. What came out of it, beyond the individual issues:

- **The pricing premise holds.** Label prices come in below USPS list commercial and match
  Pirate Ship's and Veeqo's for the same parcels, so a shop reaches rates here it could not
  reach on its own account without an NSA. Evidenced by comparison on a development store,
  not by a production purchase — the one caveat left is whether a production store is priced
  identically, which the first real label settles for free.
- **`preferredRateSelection` is honoured**, and Shopify resolves the preferred rate *before*
  it validates the rest of the input — so a selection sent with a past ship date reports
  whether a rate matched **without buying**. That free oracle is the instrument the rest of
  the campaign runs on, and it is repeatable indefinitely against one order.
- **There is no Shopify service vocabulary, only each carrier's own passed through.** USPS in
  a case-sensitive PascalCase, UPS's numeric codes, DHL's letter codes. So the question for a
  new carrier is "what does this carrier call it", not "what would Shopify call this".
- **USPS international is a vocabulary gap, not an absence of rates** — the admin's own rate
  list offers three USPS international services for a parcel the oracle finds no `usps:` code
  for. Worth a second pass with USPS's published product identifiers.
- **Four separate vocabularies turned up in one campaign**: Shopify's service codes, label
  tokens as printed, UPS's tracking-number service indicators, and each carrier's API codes.
  The UPS indicators are the dangerous one — they match UPS's API codes on every domestic
  service and diverge on every international one, so a table built from the published codes
  looks confirmed domestically and is silently wrong abroad.
- **Rung 2 earns its place only on carriers rung 1 cannot reach.** Shopify passes USPS's own
  label through, so USPS tokens are descoped rather than deferred; UPS labels are bitmaps
  necessarily, because UPS offers ZPL or GIF and no PDF at all.
- **Five defects in shipped code were found by running it**, three of them wrong on every
  purchase, and two of them in code paths that had never executed outside a test suite that
  fakes the vendor. `22` is the sharpest: a mocked Shopify validates no arguments, so a query
  Shopify rejects outright passed six tests.

### Done

`01` `02` `04` (wontfix) `06` `08` `09` `10` `15` `16` `18` `19` `20` `21` `22` `24`, plus
`07`'s storage and printing, `11`'s ladder and hook, `12`'s flag half, and `23`'s USPS, UPS
and FedEx rows. Each file carries its own record.

## Closed

| Directory | Issues | Closed |
|---|---|---|
| [nginx-upstream-resolution](nginx-upstream-resolution/) | 1 of 1 `done` | Shipped 2026-09-06. nginx resolves the app container at request time, so it follows `app` to a new IP instead of 502ing on the old address until someone restarts it. Docker's embedded DNS returns a 600s TTL for a container name, which is why the `valid=10s` cap is part of the fix rather than noise. |
| [pii-retention](pii-retention/) | 1 of 1 `done` | Shipped 2026-09-10, out of `shopify-shipping-carrier/07`. `shipments.city` landed NOT NULL and `PurgePiiCommand` nulls it alongside sixteen nullable fields in one statement, so the scheduled daily purge threw on its first eligible shipment and never reached the packages — `pii_retention_days` described a policy that has never run anywhere. The first run after this deploys purges a backlog, not a day's worth; dry-run it first. |
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
