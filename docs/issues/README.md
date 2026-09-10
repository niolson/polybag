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
| [shopify-shipping-carrier](shopify-shipping-carrier/) | 9 of 25 | **Sequenced 2026-09-08, revised 2026-09-09 — see [Working order](#working-order--shopify-shipping-carrier) below.** Adapter shipped 2026-08-31. `01` gated the purchase-path work until 2026-09-08; that gate is now cleared, and on a development store labels cost nothing. `09` **done** 2026-09-04 — Shopify is now a priceless offer beside the rates, behind a per-client opt-in and a confirmation, and out of every automated path; `10` shipped with `postage-source-split/11`. `08` **done** 2026-09-05 — the rollup now records how many of its packages reported a cost, so spend totals say what they exclude and cost-per-package divides by priced packages only; it spun out `12`, the same defect on the billing path, where the number is invoiced rather than displayed. `06` rescoped and **done** 2026-09-05 — the Shopify offer is withdrawn once another package on the shipment has shipped, enforced at purchase time as well as in the list, so a second label is never attempted against an already-fulfilled fulfillment order; its `fulfillmentOrderSplit` half spun out as `13`, blocked until PolyBag has a multi-package packing workflow at all. `11` partially implemented 2026-09-06 — the inference ladder, the versioned USPS service-type-code table (generated from USPS's published appendix, not transcribed), the label-text reader and the narrow inferred-service write path all landed with tests; it stays open because the coverage measurement it exists to produce needs real Shopify packages. Its purchase-time hook was deliberately unwired until there was a label to validate it against; that gate cleared and **item 1 landed 2026-09-09**, so inference now runs inside the purchase and Shopify packages start carrying an inferred service — which is what the coverage measurement will eventually be taken over. It spun out `14`, the label evidence the token tables need across Shopify's nineteen carriers. `01` cleared its blocker 2026-09-08 — a label bought in the Shopify admin accepts the terms, and the first purchase through PolyBag then went through on Shopify's account, with Shopify choosing USPS Ground Advantage for an `auto` selection. It answered six of its ten questions and spun out `15`: the IMpb parser accepts only 22-digit numbers, so rung 1 of `11` declined the 26-digit number on that label and left `packages.service` null when the service type code decoded to exactly what the label said, and `16`: `ShippingLabel.trackingInfo.company` is Shopify's internal carrier code rather than a carrier name, so a Shopify-bought UPS label shipped with `carrier = "ups_shipping"` and no carrier of record — USPS resolving was a coincidence of spelling. Both **done** 2026-09-08: the IMpb parser now reads every valid barcode length and declines a string that reads two ways, and Shopify's carrier codes are translated where Shopify is known to be the source, with trademark signs folded out of alias lookup keys. The same purchase turned up the label price in the order event stream, recorded on `05`. `04` closed **wontfix** 2026-09-08 in the sequencing pass, on its own recommendation and with the reopen trigger named — polling is observed working, so a webhook buys latency alone at the cost of a second path only hosted tenants could use. `01` was split the same day: its
questions 7, 8 and 10 — scan-level movement, whether `displayStatus` advances, and whether a
Shopify-bought USPS label is accepted on a SCAN form we create — moved to `17`, because they
share a blocker `01` no longer has, a real store shipping a real parcel. `01` keeps its
numbering so its comment history still resolves. **`01` closed `done` 2026-09-09**: every
question answered or moved. Shopify accepts a midnight `shippingDatetime` and schedules the
customer's shipping email for it — so after the 8 PM cutoff a shipping confirmation lands at
00:00, a behaviour nobody chose, following from `carriers.pickup_cutoff_hour`, a column with
no UI at all. It spun out `19`, `20` and `21` on the way. `02` **done** 2026-09-09 — `preferredRateSelection` is read and obeyed, not
ignored: a purchase asking for USPS Priority Mail Express cost $47.63 where `auto` had
chosen $5.68 and $9.04 from the same inputs. Its service codes are two vocabularies, a
case-sensitive PascalCase of Shopify's own for USPS (`GroundAdvantage`, not
`usps_ground_advantage`) and UPS's own numeric codes passed through, and twelve confirmed
pairs are now seeded. They were enumerated for nothing: Shopify resolves the preferred rate
before it validates the rest of the input, so a selection sent with a past ship date
reports whether a rate matched without buying anything — which also gives `14` the
instrument it needed to ask for one label per carrier and service. It spun out `18`: a
void does not reopen the fulfillment order but replaces it, and the shipment keeps the
dead ID, so a voided package is offered Shopify postage it can no longer buy. That churn
turned out to reach the import too, as
[`21`](shopify-shipping-carrier/issues/21-a-void-makes-the-next-import-create-a-duplicate-shipment.md):
`source_record_id` is the fulfillment order GID, so voiding a label and re-importing inserts
a **second shipment for the same order** — and once the synchronizer un-ships the first, the
queue holds two open shipments for one order, one of which can never buy a label. The
import-side fix discharges `18`; fixing `18` alone does not fix `21`. Both **done**
2026-09-09, as one change: `ShopifyFulfillmentOrderRepointer` runs once per import run
against the whole fetched set and re-points the open shipment for the same order, location
and goods whose own fulfillment order is no longer offered, so the replacement updates it
rather than inserting beside it; and `applyVoid()` now re-resolves the shipment's
fulfillment order from the order's `supportedActions`, moving `source_record_id` with it,
so a void with no subsequent import no longer leaves a dead ID either. Line items turned
out to be a necessary part of the test — "no longer offered" is equally true of a sibling
that closed for its own reasons — and a `shipped` shipment is never re-pointed, which is
the one window the duplicate can still open in. Review then corrected both halves for the same mistake, absence of evidence read as a match: the goods are now fingerprinted onto the shipment at import time rather than compared through `shipment_items`, which a per-source setting can switch off, and the void path applies the goods test at all — without it, a split order's sibling could be written into the shipment's metadata and the next label bought would have shipped the wrong parcel. The shop's *Preferred services* screens were then used as a denominator — 25 services across USPS, UPS and DHL Express, of which **17 are mapped** — and DHL settled the naming question: there is no Shopify vocabulary, only each carrier's own passed through (`P` is DHL's product code for Express Worldwide, as `03` is UPS's for Ground). USPS international is the one real gap, and a Canada destination hardened it: nine spellings missed there as they had on Poland and Singapore, while UPS and DHL international matched on the same shipments and USPS domestic worked on the same store. That was read as the store most likely not being offered those rates, "indistinguishable from outside" — **corrected 2026-09-09**: the Shopify admin's own rate list for the same parcel offers USPS First Class Package International, Priority Mail International and Priority Mail Express International at $36.57, $48.81 and $74.85, so the rates exist and the seeded codes do not name them. It is a vocabulary gap, needs no production store, and the free oracle can keep hunting it against an international fulfillment order that now exists. `20` **done** 2026-09-09 — the export no longer calls `fulfillmentCreate` at all for a package Shopify sold the label for, so the `permanently_failed` row every Shopify-bought package was recording is gone; `fulfillmentCreate` has no error code to key on (`UserError` carries only `field` and `message`), which is why the fix gates on the stored label ID and deliberately still fails the same message when the label came from one of our own carrier accounts. Rows already written heal with `packages:export --retry-permanent`. `07` **decided 2026-09-10, then half implemented the same day**. The decision first: the second-printer objection that made customs-form printing look expensive was wrong — Device Settings already models a report printer, labelled for exactly this, and `printReport()` already prints 8.5×11 to it, so the column is carrier-neutral `customs_form_data` rather than a Shopify-specific one; Amazon, FedEx and UPS all discard the same second document today, FedEx while building a full customs declaration for it. It spun out `23`, which carriers return a separate customs document and which fuse it into the label. **Storage and printing then shipped**: the document is stored beside the label, nulled on a void and on purge, and printed to the report printer straight after its own label — interleaved per parcel in a batch rather than stacked at the end — with a customs form that fails to print warned about and carried through the redirect rather than losing the label print. `07` stays open for the pre-purchase gate alone, which needs `23`: gating on `requiresCustomsDeclaration()` today would refuse APO/FPO and territory labels on workstations that never needed a report printer. Writing the purge test found that the PII purge has **never run at all** — `shipments.city` is NOT NULL and the command nulls it, so the daily 01:30 run has thrown on its first eligible shipment for the lifetime of the feature and never reached `label_data` either; fixed here and recorded as [pii-retention/01](pii-retention/issues/01-purge-pii-has-always-failed-on-a-not-null-city.md). |
| [amazon-buy-shipping](amazon-buy-shipping/) | 1 of 8 | Implements ADR-0003 (Accepted 2026-09-02). `01` **done** — production `getRates` returned 6 offers across OnTrac/UPS/USPS, so the multi-carrier premise holds. `04` **done** 2026-09-04, which unblocks Amazon sandbox work generally. `02` **done** 2026-09-04 — the offer and observed-service stores, which unblocks `03` and `05` and the deferred quote/purchase half of `postage-source-split/08`. `05` **done** 2026-09-05 — observed services can be aliased or promoted from *Map Carrier Services*, so OnTrac has a home before `03` starts returning it. `06` **done** 2026-09-05 — approval is a row per source/client/environment, deny by default, granted from the same page. `07` **done** 2026-09-05 — auto-ship, batch ship and pre-selected rates all select through `RateSelector::selectForAutomation()`, which withholds an unapproved discovered service and names it; the Ship page still lists it for a person to choose. `03` **done** 2026-09-05 — the adapter quotes, buys, voids and tracks through Amazon, keeping the real carrier per offer and stamping the observed-service identity on every rate; quoting and purchasing now dispatch by the offer's postage source, which discharges the deferral in `postage-source-split/08`. One acceptance criterion is deliberately unticked: nothing has been run against a live order yet. `08` is a `needs-triage` design question, and post-quote filtering — the option it names — is what shipped. |
| [data-source-improvements](data-source-improvements/) | 0 of 7 | All closed. `06` **done** 2026-09-07 — `docs/data-sources/database.md` is the Database driver reference: connection fields per driver, the read/write query contracts and their bound parameters, `RawSqlGuard`, `max_affected_rows`, the field-mapping defaults read off `DataSourceFactory::databaseConfigFor()` (not `FieldMapper`, which holds none), least-privilege `GRANT` examples run against real MySQL 8.4 and SQL Server 2022 instances, and one worked example. Writing it turned up a live bug: the Export Query helper text advertised a `:cost` parameter that is never bound, so any export query using it failed with `HY093` — the helper text now lists only what `PackageExportService` actually supplies. No PostgreSQL or SQLite round-trip was run, same gap `04` records. `05` **done** 2026-09-07 — *Test Queries* is now *Preview Queries*: it executes the read queries and shows the first rows raw and mapped, so a wrong field mapping is visible before an import runs; the write queries are still parse-checked only and never executed. `04` closed 2026-09-07 as **done** — it had shipped 2026-08-26 in `65e54cc` (a shared `ImportConnectionConfig`, plus the `pdo_pgsql`/`pdo_sqlsrv` the image was missing) four days after the 2026-08-22 premise re-verification, and nobody updated the ticket; no live round-trip against a real SQL Server or PostgreSQL instance is recorded anywhere, so that path is covered by unit tests but still unrun end to end. `07` **done** 2026-09-07 — FBA orders are excluded from import by default behind a per-source opt-in, and when imported they are badged and blocked from packing, batch shipping and export. Whether `SearchOrders` v2026-01-01 can filter server-side is still unanswered: no v2026-01-01 model is vendored to check against, so the filter is client-side by choice. |
| [carrier-request-schema-validation](carrier-request-schema-validation/) | 2 of 2 | Both `ready-for-agent`. Extends the pattern from #144/#145 to USPS and FedEx, whose specs cannot be vendored. |
| [fedex-sandbox-rate-testing](fedex-sandbox-rate-testing/) | 1 of 1 | `ready-for-human`. The FedEx sandbox answers most rate request shapes with truncated JSON, so `FedexAdapter::buildRateApiRequest()` replaces every sandbox rate request with a canned domestic one — which makes international rating unreachable there. Needs real sandbox requests to characterise before anything can be decided. Two international rate fixtures are already committed and skipped. |
| [postage-source-split](postage-source-split/) | 1 of 13 | Implements ADR-0002 (Accepted 2026-09-01). `01`–`12` all **done**, shipped 2026-09-02 to 09-04 as #155–#171. Only `13` is open, a `needs-triage` presentation question left behind by `07`. |
| [tech-debt](tech-debt/) | Phases 2–4 | A plan with checkboxes, not issue files — no `Status:` line, so it does not appear in the grep below. |

## Working order — shopify-shipping-carrier

Sequenced 2026-09-08, after `01` bought two labels through the API. **The premise the old
ordering rested on is gone**: `01` was written when a label cost real postage and had to be
voided by hand, so everything downstream of it was batched behind one expensive purchase. On
a development store these are test labels — nothing is charged and nothing ships — which
makes the experiments the cheap work and inverts most of the queue. `15` and `16` closing the
same day are what make the inference path usable against them.

**Revised 2026-09-09**, when `01` and `02` closed and left three bugs behind them. The
campaign was the whole of the queue when it was sequenced, because nothing was known to be
broken. Now something is: `19`, `20` and `21` are defects in shipped code, found by running
it, and two of them are wrong on every purchase. They go ahead of the remaining evidence
work, which is measurement and can wait. `20` closed the same day, `21` with `18` shortly
after, and `19` last. Verifying `19` against the live store then turned up `22` — `18`'s
re-point query was invalid and had never once run outside the test suite — which is closed
too. All four are done, and the campaign is what remains.

Each issue file carries the reasoning for its own position; this is the order.

**First, and unrelated to Shopify — [`12`](shopify-shipping-carrier/issues/12-client-billing-invoices-unpriced-postage-as-zero.md).**
The only open item that is presently wrong in a way that moves money: null-cost postage is a
term in `line_total`, so every billing run under-invoices the client and the line looks
complete. **The flag half shipped 2026-09-08** — both views disclose the count, the billable
event log filters down to those lines, and both CSVs carry it, so an invoice can be
reconciled against the seller's billing data before it goes out. `line_total` still
under-bills; the charging half waits for `05`. Its remaining half is gated on `05`, so in
practice the three bugs below are the next thing anyone can actually pick up.

**Then the three bugs the campaign turned up**, in this order:

1. ~~[`20`](shopify-shipping-carrier/issues/20-every-shopify-bought-package-lands-a-permanent-export-failure.md)~~
   — **done 2026-09-09**, with option three. Option two turned out not to exist:
   `fulfillmentCreate` returns the base `UserError` type, whose only fields are `field` and
   `message`, so there is no code to key on and any guard here reads prose Shopify controls.
   The export now skips outright when Shopify sold the label, which is the shape
   `AmazonSource` has had for Buy Shipping all along. Option one was **rejected**, not
   skipped: `unfulfillable status= closed` also reaches a package shipped on our own carrier
   account whose fulfillment order was closed and replaced (`18`), where the export really has
   failed, so matching the message would bury a real failure. It also keeps `01`'s question 4
   answered — the second `notifyCustomer` call site is now never reached. Existing
   `permanently_failed` rows heal with `php artisan packages:export --retry-permanent`.
2. ~~[`21`](shopify-shipping-carrier/issues/21-a-void-makes-the-next-import-create-a-duplicate-shipment.md)~~,
   ~~which discharges [`18`](shopify-shipping-carrier/issues/18-void-replaces-the-fulfillment-order.md)~~
   — both **done 2026-09-09**, as one change, `21`'s first option. The import re-points the
   existing shipment at the replacement fulfillment order instead of importing it as new
   work, and the void path re-points too, so neither a re-import nor a void left to sit
   leaves a shipment naming a dead fulfillment order. The identity that recognises a
   replacement is order plus assigned location plus a fingerprint of the goods recorded at
   import time, and every ambiguity resolves toward importing a second shipment rather than
   merging two that are not the same — a duplicate is visible in the queue, a wrong merge
   loses goods.
3. ~~[`19`](shopify-shipping-carrier/issues/19-international-purchase-fails-when-the-box-weighs-less-than-its-contents.md)~~
   — **done** 2026-09-09. Narrower than the other two (international only, and Shopify
   postage is a per-client opt-in), but it failed **after the box is taped shut** and
   reported `UNKNOWN_ERROR`, which names nothing an operator can act on. Detect-and-withhold,
   as the issue proposed, plus a 0.1 lb band where the shortfall is the scale rather than the
   catalogue and the declared sum is sent instead. The escape hatch retries at the scale
   weight unchanged — nothing is over-declared to get a box out the door. It also found that
   the existing customs-weight prompt *had* fired on the failing package and could not have
   helped: the import writes Shopify's weights onto `Product`, so PolyBag was asking the
   operator to confirm a scaling Shopify never sees.

**Then the rest of the campaign**, which is now two live items rather than four. It was one
run of purchases on the development store, answering four issues at once because each label
is free and the evidence overlaps; items 1 and 4 closed 2026-09-09 and are kept here for the
reasoning they carry:

1. ~~[`02`](shopify-shipping-carrier/issues/02-establish-preferred-rate-selection-service-codes.md)~~
   — **done 2026-09-09.** It is honoured, the codes are catalogued, and the probe method it
   established is free and repeatable, so `14` can now ask for a specific carrier and service
   instead of taking whatever `auto` returns. The CeC question it carried from `01` is half
   answered: an explicit USPS selection sells through the API on a store whose admin refuses
   USPS, so the origin-address theory is not needed; the pricing half needs a real store.
2. ~~[`11`](shopify-shipping-carrier/issues/11-infer-the-service-from-the-label.md) **item 1
   only**~~ — **done 2026-09-09.** Inference now runs inside the purchase, so every capture
   below is also a test of the hook. It also closed the open question about why Shopify's UPS
   labels are unreadable: UPS's API offers `ZPL` or `GIF` and no PDF at all, so a Shopify UPS
   PDF is a wrapped raster necessarily rather than a rendering choice — the 4×6 and 8.5×11
   documents embed a byte-identical bitmap. Rung 2 is therefore permanently closed for UPS,
   which is a coverage answer rather than a defect, and the input the reopen-OCR question was
   waiting on. USPS infers on rung 1 today; its rung 2 is idle only until `14` transcribes
   tokens from a label we hold. **Item 3 followed the same day** — the UPS 1Z
   service-indicator rung is built, so a Shopify UPS package infers Ground Saver on rung 1
   instead of nothing at all. Its table is observed pairs, not documentation: UPS publishes no
   such table, so the "as authoritative as the USPS appendix" bar was unreachable and was
   lowered on purpose with the provenance recorded. The evidence turned up a **fourth
   vocabulary** — tracking indicators match UPS's API service codes on every domestic service
   and diverge on every international one (`04`/`66`/`67` against `65`/`07`/`08`), so a table
   built from UPS's published codes would look right domestically and be silently wrong abroad;
   all four international rows —
   `04`, `66`, `67`, `68` — were read off a production history and then returned independently
   by Shopify test purchases for the same services, so each carries two unrelated sources
   (except `68`, Standard, which rests on the Shopify labels alone). None was guessable: every
   one differs from the API service code for the same service (`65`, `07`, `08`, `11`), and a
   test now pins that those API codes must resolve to nothing as indicators. Those same purchases **corrected a claim made
   earlier the same day**: dev-store 1Z numbers are not synthetic in general. The one that
   fails its check digit was bought by hand in the Shopify admin; every label bought through
   PolyBag validates, so a development store *can* exercise the rung end to end — which is what
   `14` needs to know before it reads a coverage figure.
3. ~~[`14`](shopify-shipping-carrier/issues/14-label-evidence-to-gather.md), US half~~ —
   **descoped 2026-09-10, not done.** Both US carriers are finished as far as this issue can
   take them, and neither wants more labels. Shopify turns out to pass USPS's label through
   (proven on a Ground Advantage label bought both ways for one package: identical font subset
   tags, image dimensions and page size; `Apache FOP` re-wrapped by `Ruby CombinePDF`), so a
   USPS token *could* be sourced from our own sandbox labels — but should not be, because rung
   1 resolves USPS domestic from the tracking number and rung 2 never even runs. UPS is closed
   from the other side: its labels are bitmaps. The general rule this leaves is that **rung 2
   earns its place only on carriers rung 1 cannot reach**. What remains of `14` is the other
   seventeen carriers, gated on the install-base question. Original text follows.

   ~~USPS and UPS, one label per service, each paired with the admin's record. **PDF only**: Shopify has
   no reachable ZPL setting (`01`, 2026-09-09), so rung 2 reads these through the PDF path or
   not at all — and each capture must be taken from `shippingDocuments[].url`, since the
   admin's print dialog returns a rasterised page with no text to read.~~ The other fourteen
   carriers wait on the install-base question, which is a business input.
4. ~~[`01`](shopify-shipping-carrier/issues/01-verify-first-live-label-purchase.md)~~ —
   **done 2026-09-09.** Questions 2, 4, 6 and 9 all closed that day, the last of them by
   lowering `pickup_cutoff_hour` so the cutoff branch fired without waiting for 8 PM.
   Question 4 answered **no duplicate notification**, but at the time only because the second
   call site could not succeed: that was
   [`20`](shopify-shipping-carrier/issues/20-every-shopify-bought-package-lands-a-permanent-export-failure.md),
   where `ShopifySource::exportPackage()` swallowed only `already fulfilled` while Shopify
   actually said *"unfulfillable status= closed"*, so every Shopify-bought package recorded a
   `permanently_failed` export. That also corrected question 3's recorded inference that export
   "degrades safely"; it did not. **`20` was fixed the same day and question 4 stays answered**
   — option three skips the export outright when Shopify sold the label, so the second call
   site is never reached rather than reached and failing, and `notifyCustomer` fires once at
   purchase. Only a message-matching fix would have reopened it. Question 2: there is no ZPL
   setting, and the page size is fixed at purchase, so nothing needs a change. Question 6:
   an international order **does** return a separate `CUSTOMS_FORM`, but as three Letter
   pages rather than a 4×6 label, which unblocks and reshapes `07`. Getting there spun out
   [`19`](shopify-shipping-carrier/issues/19-international-purchase-fails-when-the-box-weighs-less-than-its-contents.md)
   — Shopify rejects an international label whose `totalWeight` is below the sum of its own
   declared item weights, and PolyBag sends the scale reading, so the purchase fails with an
   unactionable `UNKNOWN_ERROR` after the box is taped shut. PolyBag's existing customs-weight
   override cannot help: it scales declared item weights *down*, which is the direction
   Shopify forbids, and on this path there is nothing to scale because Shopify builds the
   declaration from its own catalogue. Collect `Order.events` on every purchase for `05`.

**Then, off the campaign's evidence:**

5. [`11`](shopify-shipping-carrier/issues/11-infer-the-service-from-the-label.md) items 2–4 —
   tokens from the captures, the UPS 1Z rung, then the coverage measurement ADR-0003 asks
   for. Item 5 (FedEx `IP`/`XQ`) and the UPS 1Z documentation work are independent and can be
   picked up at any time.
6. [`07`](shopify-shipping-carrier/issues/07-customs-form-printing.md) — **decided
   2026-09-10, `ready-for-agent`.** The second-printer objection turned out to be wrong:
   Device Settings already models a report printer and its own label already reads "for
   packing slips, customs forms", and `printReport()` already prints 8.5×11 to it. So the
   middle option — print the second document — is cheaper than it looked, not larger, and
   it is what the issue now specifies: a carrier-neutral `customs_form_data` column that
   four adapters can use (Amazon, FedEx and UPS all discard the same document today),
   purged with `label_data`, gated on `requiresCustomsDeclaration()` rather than the
   shipping method, and skipped as a batch ineligibility reason rather than dropped. It
   spun out [`23`](shopify-shipping-carrier/issues/23-which-carriers-return-a-separate-customs-document.md):
   the gate needs to know which carriers return a *separate* document and which fuse it
   into the label, or it over-blocks APO/FPO and territory shipments. Storage and printing
   do not wait on `23`; only the pre-purchase gate does. **`23`'s USPS row — the one the
   gate turns on — closed 2026-09-10**, and needed no purchase: nine international PDF
   labels and one ZPL were already in `packages.label_data` across seven destination
   countries, and every one of them fuses the CP72 into the label (three 4×6 pages in PDF,
   three `^XA` blocks in ZPL, postage on ply 1). So the over-block is confirmed rather than
   suspected, and the gate cannot be built on `requiresCustomsDeclaration()` alone. It also
   found a latent one: `LabelResponse::parseBody()` discards any multipart part past the
   second without comment, harmless only because `receiptOption` is `NONE`. UPS, FedEx and
   Amazon remain, and each was tried the same way, which turned the issue's premise over:
   the table assumed the document is offered and PolyBag fails to read it, and for **UPS and
   FedEx nothing is offered because nothing is requested**. UPS sends `InternationalForms` at
   `Shipment.InternationalForms` where UPS's own vendored OpenAPI puts it under
   `ShipmentServiceOptions`, so it is ignored and no invoice is generated — filed as
   [`24`](shopify-shipping-carrier/issues/24-ups-international-forms-are-sent-at-the-wrong-nesting-level.md),
   which also records why neither the schema test nor the "international" label test caught
   it. **`24` is done, same day**, and moving the node proved the point it was filed on:
   nested where UPS defines it, the schema checked the subtree for the first time and found
   `FormType` and `Product[].Description` both sent as the wrong type, so the fix that only
   moved it would have turned an ignored invoice into a rejected shipment. Its unwritable
   test spun out [`25`](shopify-shipping-carrier/issues/25-the-ups-saturday-rejection-retry-is-unreachable.md)
   — the Saturday-rejection retry is dead code, since the connector's `$tries` makes a UPS
   4xx throw rather than return. Fixing `24` took three live attempts, each surfacing one
   more field UPS enforces but its schema leaves optional — `Contacts.SoldTo` (9120800) and
   then `Shipment.InvoiceLineTotal` (120502), the latter already built on the rate path and
   simply missing from the ship path. **The purchase that then succeeded answered `23`'s UPS
   row**: UPS returns the customs document *separately*, as its own PDF (`Form`, `Code 01`,
   "All Requested International Forms") even beside a GIF label, and the adapter had been
   dropping it — one 4×6 label printed and nothing else. Now read into `customsFormData`, so
   UPS is the second carrier feeding `07`'s storage and printing and the first that is not
   Shopify. USPS fusing and UPS not is the case for a per-carrier capability, demonstrated
   rather than argued. Printed end to end on a second package: UPS's document is **one
   commercial invoice in triplicate** — 3 Letter pages, byte-identical, each headed "Page
   1" — so the paper cost of the gate is three sheets per international UPS parcel, same as
   Shopify's. No FedEx request sends `shippingDocumentSpecification`, and its multi-page
   international label turns out to be AWB pouch copies rather than a customs declaration —
   the exact trap of mistaking a long label for the USPS fused case. Amazon is unobserved.
   So `23`'s capability must record why a carrier returns nothing, since "nothing today"
   goes stale the moment either request is fixed.
7. [`05`](shopify-shipping-carrier/issues/05-shipping-label-cost-reconciliation.md) — build
   last, but its evidence was gathered in step 4. Then return to `12`'s charging half.

**Not scheduled.**
[`17`](shopify-shipping-carrier/issues/17-questions-a-test-label-cannot-answer.md) waits on a
real store shipping a real parcel — two of its three questions come free with the first one
anybody makes, so it is watching rather than working. `04` is closed `wontfix`, reopening if
the 15-minute void window ever costs someone something. `13` stays blocked on a multi-package
packing workflow that does not exist.

**Two open inputs this repository cannot supply.** Which carriers our install base actually
ships with, which gates the other fourteen in `14`; and the billing owner's answer on what an
unpriced line should charge, which gates `12`'s second half.

**`01` was split on 2026-09-08.** Its three unanswerable questions became `17`, which
retires the last live "blocked on `01`" edge in this directory — that edge had been dead
since the terms were accepted and was still steering the queue. `01` keeps its original
numbering, so items 7, 8 and 10 are stubs rather than gaps and its comment history still
resolves. Two references in `postage-source-split` were repointed, both being the issues the
moved questions originally came from.

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
