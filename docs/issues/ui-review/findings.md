# UI review: page-by-page findings

Status: reference

A page-by-page review of the app for layout, clarity and demo-readiness, working down the
sidebar. It is pass 2 (layout) of [`release-polish`](../release-polish/PRD.md), run as a
walk-through rather than a screenshot sweep, and uses that PRD's severities:

- **high** — breaks a demo-path step, or loses or misstates data
- **medium** — wrong or confusing on the demo path
- **low** — off the path, or cosmetic

Findings already filed under `release-polish` are linked rather than repeated. A finding
that grows into real work gets promoted to its own issue file, and its entry here says
where it went.

Pages are reviewed as an Admin at 1395×868, in light and dark mode, on local development
data in sandbox mode.

## Progress

| Page | Reviewed |
|---|---|
| Sidebar | 2026-10-16 — regrouped by task (Ship, Fulfillment, Picking, Reports, Shipping Setup, Carriers, Integrations, Admin), Generate Pick Batch moved onto Pick Batches, Device Settings moved to the user menu as Workstation Settings |
| Dashboard | 2026-10-16 |
| Scan & Pack (with Print Command Barcodes) | 2026-10-16 — up to Choose a Rate; the Ship page is reviewed on its own |
| Ship | 2026-10-16 — rates for a military (DPO) address, purchase, recovery, reprint and void on the USPS sandbox |
| Package view | 2026-10-16 — after purchase and after void only |
| Manual Ship | 2026-10-16 — a domestic address through to rates (Package 3572); not bought |
| End of Day | 2026-10-16 — viewed only; no shipping day ended and no manifest generated |
| Shipments list and Shipment view | 2026-10-16 — list, columns, filters, and Shipment #2000 |
| Packages list (with Create Package) | 2026-10-16 — list and the create form; the edit form not yet opened |
| Label Batches and Batch Ship | 2026-10-16 — a 3-Shipment sandbox batch (Batch #1), its progress and results pages, then each label voided |
| Pick Batches, Generate Pick Batch, Print Pack Slips | 2026-10-16 — viewed only; no pick batch generated and nothing printed |
| Reports | 2026-10-16 — Rate Comparison, Volume, Packages by User, Prepaid Duties, Packing Validation; Client Billing not seen (multi-client is off on this data) |
| Shipping Methods, Box Sizes, Products | 2026-10-16 — lists and one edit form each; nothing saved |
| Carrier Accounts, Carriers, Carrier Services, Special Services | 2026-10-16 — lists and the USPS account form; nothing saved, no connection tested |
| Connections, Channels, mapping pages | 2026-10-16 — lists, one Shopify connection's form, and the three mapping pages (all empty here); no import run |
| Users, Audit Log, App Settings, Dev Settings | 2026-10-16 — lists, one user form, one audit entry, the General and Shipping settings tabs, Dev Settings; nothing saved |

## Triage (2026-10-16)

The one high and every medium finding went to an issue file under [`issues/`](issues/);
low findings in the same area went with them. Each triaged heading below links to its
issue. Low findings with no link stay here as the backlog.

| Issue | Findings | Call |
|---|---|---|
| [`01`](issues/01-dates-and-delivery-estimates.md) Dates and delivery estimates | S11 (high), S1, M3 | Done 2026-10-16 |
| [`02`](issues/02-ship-page-clarity.md) Ship page clarity | S2, S3, S8; S4–S6, M4 | Done 2026-10-17 |
| [`03`](issues/03-pack-page-clarity.md) Pack page clarity | P1–P3; P4, P7, P9, P10 | Done 2026-10-17 |
| [`04`](issues/04-weight-entry.md) Weight entry | M2, S9 | Before the demo |
| [`05`](issues/05-address-check-display.md) Address check display | SH1, SH2 | Done 2026-10-16 |
| [`06`](issues/06-shipments-list-defaults.md) Shipments list defaults | SL1, SL2 (column), SL3 | Before the demo |
| [`07`](issues/07-batch-ship.md) Batch Ship | LB1, BS1, BS2; LB2–LB4, BS3–BS5 | Before the demo |
| [`08`](issues/08-dashboard.md) Dashboard | D1–D4; D5–D11 | Before the demo |
| [`09`](issues/09-end-of-day-and-carrier-status.md) End of Day and carrier status | E1, CA1, CR1; E2–E4 | Done 2026-10-16 |
| [`10`](issues/10-report-presentation.md) Report presentation | R1, R2; R3 | Before the demo |
| [`11`](issues/11-setup-list-quick-fixes.md) Setup list quick fixes | BX1, PR1, PK1 (remove New package) | Done 2026-10-16 |
| [`12`](issues/12-manual-ship-quote-before-create.md) Manual Ship quotes before creating | M1 | Before the first customer |
| [`13`](issues/13-connection-import-status.md) Connection import status | CN1 | After the freeze (feature) |
| [`14`](issues/14-usps-quoted-vs-recorded-cost.md) USPS quoted vs recorded cost | S10 | Needs info |
| [`15`](issues/15-manifest-generate-and-print.md) Manifest generate and print | E6 | Done 2026-10-17 |
| [`release-polish/51`](../release-polish/issues/51-wording-the-same-thing-named-differently-across-pages.md) | SH3 | Added as a comment there |

## Dashboard

### D1 — The items needing attention sit below the charts (medium) → [`08`](issues/08-dashboard.md)

The exception cards (Undeliverable, Failed Batch Items, Tracking Exceptions, Stuck
Pre-Transit, Needs Shipping Method, Unfinished Label Purchases, Unmapped Shipping
References) are the only actionable part of the page, and they render below three charts,
off the first screen. Widget order is mostly implicit: `CostPerPackageTrend` has `$sort = 0`,
`ExceptionsWidget` `1`, `DatabaseHealthWidget` `10`, and the rest none.

Fix: give every widget an explicit sort: banners (demo mode, MFA, device status), the stats
row, exceptions, then the charts, then database health.

### D2 — "vs last week" compares a partial week with a whole one (medium) → [`08`](issues/08-dashboard.md)

`StatsOverview` compares the cost of this week so far (Monday to today) with the whole of
last week, so early in the week it always shows a large drop (−50.7% on a Friday, in
green). A prospect reads that as a halving of postage spend.

Fix: compare with the same days of last week (Monday to the same weekday).

### D3 — The Avg Cost Per Package line is dominated by thin days (medium) → [`08`](issues/08-dashboard.md)

The last point (Oct 15, about one package) drops to $8.40 against a $12–14 range and
pulls the eye. Because the y-axis starts at 8, ordinary day-to-day variation also looks
dramatic, and the axis shows no currency. The near-empty recent days may partly be
[`release-polish/31`](../release-polish/issues/31-the-stats-refresh-runs-on-a-utc-day.md) (rollup on a UTC day).

Fix: leave out today and any day under a minimum package count, or plot them dimmed;
format the axis as dollars; consider starting at zero.

### D4 — The reference count disagrees with the Map Shipping Refs page (medium) → [`08`](issues/08-dashboard.md)

The Unmapped Shipping References card counts distinct references on *open* Shipments from
the *last 90 days*, without excluding references that already have an alias. The Map
Shipping Refs page lists (reference, client) pairs across *every* status, minus aliased
ones. The card and the page it links to can disagree, and the sidebar badge added in the
sidebar regrouping uses the page's definition, so the same screen can show two numbers.

Fix: one definition, shared through `UnmappedShippingReferences::unmappedReferences()`;
decide whether closed Shipments and the 90-day window belong in it.

### D5 — Lazy widgets paint as empty white cards (low) → [`08`](issues/08-dashboard.md)

For about a second after load, the stats rows and charts are blank white boxes with no
placeholder, then fill in and shift the layout.

Fix: a placeholder in each lazy widget, or load the stats row eagerly since it is cheap
and cached.

### D6 — Ragged grid rows (low) → [`08`](issues/08-dashboard.md)

Five stat cards in a three-column grid leave a row of two; seven exception cards in a
four-column grid leave a gap; Avg Cost Per Package sits alone at half width with the right
half empty.

Fix: fit the counts to the grid (for example, a four-card stats row with cost as the
fourth, plus a separate month card), and pair the cost chart with another chart or make it
full width.

### D7 — Stat descriptions repeat the title (low) → [`08`](issues/08-dashboard.md)

"Shipped This Week — 410 — Packages this week", "Shipped This Month — 1,739 — Packages this
month", "Shipped Today — 0 — Packages shipped today". The description line could carry
context instead, such as a comparison with the previous period.

### D8 — Two exception descriptions are hard to parse (low) → [`08`](issues/08-dashboard.md)

"Open shipments no label can be bought for" and "Packages that cannot be bought for until
settled". Suggested: "Open shipments with no shipping method that can buy a label" and
"Purchases waiting on a carrier answer before the package can ship". Check against
[`release-polish/49`](../release-polish/issues/49-wording-workflow-pages.md) before changing.

### D9 — Database Health is maintenance information (low) → [`08`](issues/08-dashboard.md)

Four row counts (Shipments, Packages, Rate Quotes, Audit Logs) each labeled "Healthy". It
is Admin-only, but on a demo it reads as noise at the bottom of an operations page, and it
never says what unhealthy would mean.

Fix: move it to Dev Settings or App Settings, or say what the threshold is.

### D10 — Carrier Breakdown shows proportions with no numbers (low) → [`08`](issues/08-dashboard.md)

The donut's legend lists carriers only; counts and percentages appear only on hover.

Fix: put the count and percentage in the legend.

### D11 — Dark mode: the content column is a different shade from the page (low) → [`08`](issues/08-dashboard.md)

At wide window sizes the main content area is a lighter band with hard edges against a
darker background on either side.

### D12 — Demo data: the sandbox always shows Stuck Pre-Transit (demo prep)

Sandbox labels are never scanned by a carrier, so Stuck Pre-Transit is always non-zero
and orange (39 here), and the shipped charts go flat after the last data load. Not a code
fix: run `demo:reset` right before a demo, and decide whether the demo data should mark
older Packages delivered.

## Scan & Pack

Walked through on a two-line Shipment: load by reference, scan each item, scan a box code,
stop at Choose a Rate.

### P1 — "Clear Shipment" means two different things (medium) → [`03`](issues/03-pack-page-clarity.md)

The **Clear Shipment** button takes every item back out of the package and keeps the box
and measurements (`clearPacking()`). The **Clear Shipment** command barcode (`%CCLEAR`),
described as "Clear the loaded shipment and start fresh", leaves the Shipment and goes back
to `/pack` (`clearShipment()`). A packer who learns one will be surprised by the other. The
button also confirms with a native `window.confirm()` dialog rather than a Filament modal.

Fix: rename the button to say what it does ("Unpack All Items"), keep "Clear Shipment" for
leaving the Shipment, and use a Filament confirmation modal.

### P2 — The Package Draft is saved silently (medium) → [`03`](issues/03-pack-page-clarity.md)

As soon as a box or item is scanned, the page saves a Package Draft in the background
(`queueSave()`), and nothing on screen says so. Leaving the page, by navigation or by the
`%CCLEAR` barcode, keeps the draft. A packer cannot tell that work was saved, and a
manager later finds unexplained unshipped Packages. Found by doing it: this review left a
draft on Shipment #2000.

Fix: show the draft state ("Draft saved", with the time) next to the shipment header, and
offer a way to discard the draft from the Pack page. (Not yet checked: what the page says
when it reopens a Shipment with a draft.)

### P3 — The chosen box is not named (medium) → [`03`](issues/03-pack-page-clarity.md)

Scanning box code `03` fills in 4 × 6 × 8 and nothing else; the page never says "8x6x4",
so a packer who scanned the wrong code sees only numbers. The Ship page had the same
problem and it was fixed in [`release-polish/17`](../release-polish/issues/17-the-ship-page-shows-custom-for-every-box.md).

Fix: show the box name and code beside the measurements, with a way to change it.

### P4 — A partly packed line says "Not Packed" (low) → [`03`](issues/03-pack-page-clarity.md)

A line with 1 of 2 packed shows the same "Not Packed" badge as one with none.

Fix: "1 of 2" in the Packed column, or a "Partly Packed" badge.

### P5 — Items can be counted only by scanning (low, needs a decision)

Each line has an unpack (−) button but no pack (+) button. That is probably deliberate,
since the scan is the check that the right item went in. But a damaged barcode has no
fallback, and a demo without a scanner means typing 13-digit barcodes.

Decide: keep scan-only, or allow + for a Manager, or in demo mode.

### P6 — The shipment header shows too little to pack by (low)

It shows the reference, recipient name and city. It does not show the shipping method
(is this expedited?), the channel, the ship-by date, or a link to the Shipment. The client
badge appears only in multi-client mode.

### P7 — Auto Ship has no explanation (low) → [`03`](issues/03-pack-page-clarity.md)

"Auto Ship: OFF" has no tooltip or helper text. It is a per-user setting
(`users.auto_ship_enabled`), not per workstation, and turning it on changes what Choose a
Rate does. A packer, or a prospect, has no way to find out what it does from the page.

### P8 — The weight is not checked against the items (low, idea)

The weight field shows the scale reading before anything is loaded, and nothing compares
it with the product weights: 0.30 lb for a canvas tote and two grooming brushes went
through without comment. The Packing Validation report catches this after the fact. An
"expected about N lb" hint would catch it at the bench, but it is a feature, so it waits
for the freeze to end.

### P9 — The Packed count jumps sideways (low) → [`03`](issues/03-pack-page-clarity.md)

The − button appears only once a line has a packed item, so the number moves right when
the first one is scanned.

Fix: reserve the button's space, or show it disabled.

### P10 — Print Command Barcodes lights up nothing in the sidebar (low) → [`03`](issues/03-pack-page-clarity.md)

The page belongs to Scan & Pack but has no sidebar item, so nothing is highlighted while
it is open. The same fix as Generate Pick Batch in the sidebar regrouping: add its route to
`Pack::getNavigationItemActiveRoutePattern()`.

## Ship

Reviewed on the Package Draft left by the Scan & Pack walk-through: Shipment #2000, two
lines, box 8x6x4, to a DPO address in AE. Only USPS returned rates. Then bought on the
USPS sandbox, reprinted from the Package page, and voided.

The first purchase failed to record because the local database was one migration behind
(`2026_10_15_120000_add_prepaid_duties_breakdown_to_package_labels_table`); that is the
environment, not the product. Running the migration and buying again recovered the same
label from USPS, as designed. S8 is about how that failure looked, which would be the same
for any recording failure in production.

**Worked well:** buying again recovered the sold label (same tracking number, one Label,
no second purchase); the void confirmation says exactly what happens; after the void the
Package returns to Ready to Ship with its measurements, and the Labels table keeps the
voided label with who voided it and when.

### S1 — A rate with no delivery date is called late (medium) → [`01`](issues/01-dates-and-delivery-estimates.md)

`RateSelector::isOnTime()` returns false when a rate has no delivery date, so for a
military address, where USPS gives a commitment name ("MILITARY") but no date, every rate
is marked "LATE" and the page says "No options meet the deliver-by date of Fri, Oct 23".
PolyBag doesn't know that; it doesn't know either way. The same rule feeds unattended rate
selection, so automation also treats "no date" as "late".

Fix: an "unknown" state beside on-time and late; show "No delivery estimate", and show the
banner only when every rate has a date and all of them are past the deadline. Decide what
automation should do with unknown dates. Note that Standard Ground has "Exclude rates that
deliver after the due-by date" on, and its help text says that also skips rates with no
date, so batch ship and auto-ship never buy a label for a military address on that method.

### S2 — Raw carrier text and caps in the rate rows (medium) → [`02`](issues/02-ship-page-clarity.md)

Each row reads like "$8.62 — MILITARY — LATE": USPS's raw commitment name, in its own
capitals, then a computed flag, also in capitals, joined by em dashes in small grey text.
The price, which is what the packer is choosing on, is the least prominent thing in the
row. The raw-text half is the kind [`release-polish/50`](../release-polish/issues/50-wording-text-that-reaches-the-ui-from-outside-filament.md) covers.

Fix: price as its own right-aligned column, the delivery date or a readable commitment
("Military mail, no date"), and Late as a badge.

### S3 — The page does not say which order it is shipping (medium) → [`02`](issues/02-ship-page-clarity.md)

The heading is just "Ship". Shipment #2000 appears nowhere, there is no link back to the
Shipment or to Pack (to correct a measurement), and nothing is highlighted in the sidebar.
On a demo, after the move from Pack, the audience loses track of which order this is.

Fix: "Ship #2000" or a subheading with the reference and recipient, a link back to Pack
and to the Shipment, and the Ship route added to Scan & Pack's active pattern (as with P10).

### S4 — "2 items" counts lines, not items (low) → [`02`](issues/02-ship-page-clarity.md)

Package Details says "Items: 2 items" for a package holding three: one tote and two
brushes.

Fix: "3 items" or "2 lines, 3 items".

### S5 — Carriers with no rate are not mentioned (low) → [`02`](issues/02-ship-page-clarity.md)

Only USPS appears. Nothing says that UPS and FedEx were asked and do not deliver to
military addresses, so on a demo it looks like two carriers are broken.

Fix: a short line naming the carriers that returned no rate, with the reason when known.

### S6 — The customs warning names a SKU and not the text (low) → [`02`](issues/02-ship-page-clarity.md)

"USPS Cuts Descriptions — USPS cuts a customs description at 30 characters: AP-00017."
names the line by SKU, does not show the description or what it will be cut to, and does
not say where to change it (the product's customs description).

Fix: show the description and its 30-character cut, link to the product, and make the
title a statement ("Customs description will be shortened").

### S7 — Demo data: the newest open orders include military addresses (demo prep)

The first Shipment picked from the top of the list shipped to DPO, AE, and so showed one
carrier with dateless "late" rates. For the multi-carrier demo, pick a domestic Shipment,
or have `demo:reset` put domestic orders first.

### S8 — A purchase that fails to record looks like nothing happened (medium) → [`02`](issues/02-ship-page-clarity.md)

USPS sold the label and PolyBag could not save it. The Ship page stayed exactly as it was
before the click, with Buy & Print Label still enabled, and showed no error. The only
trace was in the notification bell, as **two identical** notifications ("Label Bought but
Not Recorded for Package 3571"). After a reload, the page still offered an ordinary
purchase and did not mention the label waiting to be recovered. The notification text
itself is good: it says buying again asks USPS for the same label first.

Fix: show the failure on the page where it happened, with the notification's text; say on
reload that a sold label is waiting and that Buy will recover it; send the notification
once.

### S9 — The customs weight dialog hides the numbers and offers the wrong fix (medium) → [`04`](issues/04-weight-entry.md)

"Customs Weight Mismatch: The total item weight exceeds the package scale weight. Would
you like to adjust the customs item weights to match the package weight?" with **Override
& Ship** or **Cancel**. It does not show either weight, and its only way forward rewrites
the customs declaration to fit the scale, when the usual cause is a wrong scale reading
(here 0.30 lb, see P8). The override is a known workaround for bad product weights, not
the normal path.

Fix: show both weights; make "Back to Pack to Reweigh" the primary action and the
override the secondary one.

### S10 — The quoted price and the recorded cost differ (medium, needs info) → [`14`](issues/14-usps-quoted-vs-recorded-cost.md)

The rate was quoted at $8.62 (`rate_quotes`) and the Label recorded $8.07, the postage in
USPS's label response. It may be a sandbox quirk, since USPS sandbox prices are not real.
If it also happens in production, every USPS Label is recorded at a different price than
the packer chose, and Rate Comparison shows the gap. Check against a production USPS
label before deciding anything.

### S11 — Ship Date and Deliver By show one day early (high) → [`01`](issues/01-dates-and-delivery-estimates.md)

The Package page showed **Ship Date: Oct 15, 2026** for a label bought on Oct 16 (stored
`ship_date` is 2026-10-16). `ship_date` and `deliver_by` are date columns, cast to UTC
midnight, and three entries format them with `->date(timezone: Location::timezone())`,
which moves midnight UTC into the previous evening anywhere in the Americas:

- `PackageResource/Pages/ViewPackage.php` — Ship Date
- `ShipmentResource.php` — the Deliver By column, and Deliver By on the Shipment view

So the Shipments list and the Ship page (which showed Fri, Oct 23 correctly) can disagree
by a day. On the demo path (step 3) and it misstates data.

Fix: drop `timezone:` from date-only columns; add a test that renders a date column in a
US timezone.

### S12 — After a reprint, the page still shows the old print time (low)

Reprint Label worked (`last_printed_at` moved to 3:27 PM), but Label Printed on the page
stayed at 3:26 PM until a reload.

### S13 — Empty fields render two ways after a void (low)

After the void, Tracking number, Carrier, Service, Shipped At, Ship Date and Shipped By
are blank, while Cost, Tracking status and Delivered At show "—" and Label Printed says
"Not printed".

Fix: one placeholder for every empty field on the page.

### S14 — Package Items paginates two rows (low)

The Package Items table on the Package page shows "Showing 1 to 2 of 2 results" and a
Per page selector for a two-line package.

Fix: no pagination on this table, or only past a sensible count.

## Manual Ship

Filled in a domestic address (a public landmark), box 8x8x8 and Standard Ground, then Choose
a Rate. That created Shipment and Package 3572 and showed USPS, FedEx and UPS rates. Not
bought.

### M1 — Choose a Rate creates the order before showing a price (medium) → [`12`](issues/12-manual-ship-quote-before-create.md)

`ManualShip::ship()` creates the Shipment and Package, then redirects to the Ship page. A
packer who only wants a price, or who backs out, leaves an open Shipment behind: Open
Shipments went from 3,673 to 3,674, and the stray order now shows in the Shipments list,
the dashboard, pick batches and the sidebar badge.

Fix: quote from the form and create the Shipment at purchase, or create it marked as manual
and remove it if no label is bought.

### M2 — The scale overwrites a typed weight (medium) → [`04`](issues/04-weight-entry.md)

With a scale connected, every reading above zero replaces the Weight field
(`$wire.data.weight = result.weight`), including while someone is typing in it. Typing 2
or 2.5 snapped straight back to the 0.30 the scale was reporting, so a wrong reading cannot
be corrected by hand, and that wrong weight is what set off S9. Pack does not have this
problem: it updates the field only when the reading changes.

Fix: copy Pack's rule (update only on a changed reading), or stop autofilling once the
field has been edited.

### M3 — A FedEx delivery date in the past reads as the future (medium, demo) → [`01`](issues/01-dates-and-delivery-estimates.md)

FedEx Ground showed "$18.10 — Delivers Fri, Jul 28" for an order quoted on Oct 16. The
date has no year, so it looks like next July, and because Jul 28 is before the Oct 23
deadline the rate counts as on time. Probably a fixed FedEx sandbox date, but it is on
screen in every sandbox demo.

Fix: treat a delivery date before today as unknown (see S1), and show the year when it is
not this year.

### M4 — An empty Items card on the Ship page (low) → [`02`](issues/02-ship-page-clarity.md)

A manual shipment reaches the Ship page with "Items: 0 items" and an Items in Package card
with nothing in it.

Fix: hide the card, or say "No items: manual shipment".

### M5 — Postal Code is not required for a US address (low)

City and State carry the required star; Postal Code does not, although every US carrier
needs it to rate.

### M6 — The box list sorts as text and hides the codes (low)

Box Size lists "10x10x10" before "6x4x4", so the smallest boxes are at the bottom, and
shows no box codes, which are what packers know them by from the barcode sheet.

Fix: sort by volume, show the code ("03 · 8x6x4").

### M7 — Shipping Method offers international methods for a US address (low)

International Economy is offered alongside Standard Ground for a Washington, DC address.

Fix: filter methods by destination, or group them.

### M8 — "Reprint Label" sits on an empty form (low)

The Reprint Label button is shown before anything is entered and does not say what it
reprints: the last label this browser session bought.

Fix: show it only after a purchase in this session, labeled with the tracking number or
recipient.

### M9 — The phone helper text is always shown (low)

Three lines about international phone formats sit under Phone on every visit.

Fix: a placeholder in the international format, and the full sentence only on a validation
error.

### M10 — Dimensions come in a different order on each page (low)

Pack and Manual Ship ask for Height, Width, Length; the Ship page shows length × width ×
height; the Package page lists Length, Width, Height. Pick one order (L × W × H is what
carriers and box labels use) and use it everywhere.

## End of Day

Viewed with no unmanifested labels for today (the one bought in this review was voided).
No shipping day was ended and no manifest generated, since both act on the carrier.

### E1 — Six identical End Shipping Day buttons, three for carriers with no account (medium) → [`09`](issues/09-end-of-day-and-carrier-status.md)

The Carriers table lists every active carrier (Amazon Shipping, DHL Express, FedEx,
OnTrac, UPS, USPS), each with the same bright **End Shipping Day** button, whether or not
there is a carrier account (Amazon Shipping, DHL Express and OnTrac have none here) or any
label today. Ending a shipping day is not a no-op: it moves that carrier's ship date to
the next pickup, and today's labels then stay off a SCAN form
([`release-polish/13`](../release-polish/issues/13-after-the-cutoff-the-days-labels-cannot-go-on-a-scan-form.md)). On a demo, a prospect sees six
buttons that look like the main action of the page.

Fix: list only carriers with an account or a label today; make Generate Manifest the
primary action when there is one, and End Shipping Day a secondary (gray) button.

### E2 — End Shipping Day confirms with a native browser dialog (low) → [`09`](issues/09-end-of-day-and-carrier-status.md)

`wire:confirm` raises the browser's own confirm box, while Void Label on the Package page
uses a Filament modal. Same issue as P1. The text is good ("Ship date will advance from
<today> to <next ship date>"); it should be in a Filament modal.

### E3 — The page never says what it is for (low) → [`09`](issues/09-end-of-day-and-carrier-status.md)

There is no description of what a manifest (SCAN form) is, when to generate it, or what
ending a shipping day does. A new packer or a prospect sees two tables and a column of
buttons.

Fix: one line under the Carriers heading: "Generate a manifest for today's labels before
pickup, then end the shipping day so new labels are dated for the next pickup."

### E4 — Zero shows as a dash (low) → [`09`](issues/09-end-of-day-and-carrier-status.md)

The Packages column shows "—" for a carrier with no labels today. It is a count: show 0.

### E6 — Generate Manifest always prints, and prints only the last form (medium) → [`15`](issues/15-manifest-generate-and-print.md)

Raised after the walk-through. Generate Manifest creates the form and prints it at once,
with no way to only create it. When a run makes several USPS forms (one per carrier
account), each is saved with its image, but only the last is sent to the printer. The
table's "Reprint" button does not say it reprints a manifest.

### E5 — Demo data: no manifest can be reprinted, and nothing is ready to manifest (demo prep)

All 90 seeded manifests have no image, so no row has a Reprint button; and unless labels
are bought earlier the same day, Generate Manifest never appears. Demo step 6 only works if
steps 3–4 bought labels that morning and none were voided. Either seed manifests with a
sample image, or write the demo script so End of Day follows the purchases.

## Shipments

The list as an Admin, with its column and filter menus, then the view of Shipment #2000
(the one packed, bought and voided earlier in this review).

### SL1 — The list opens on every status, ten rows at a time (medium) → [`06`](issues/06-shipments-list-defaults.md)

The default tab is **All**, so open, shipped and cancelled Shipments are mixed together,
sorted by ID, ten to a page across 724 pages. What an operator, and demo step 1, wants
first is the open work.

Fix: open on the **Open** tab; 25 or 50 rows per page.

### SL2 — Nowhere to see or filter by destination (medium) → [`06`](issues/06-shipments-list-defaults.md)

There is no city, state or country column, not even as a hidden option in the column
menu, and no domestic/international filter. A packer cannot spot the international or
military orders (which behave differently on the Ship page, see S1 and S5), and demo step
1 ("orders arrive, with validated addresses") shows a Deliverable badge with no address.

Fix: a Destination column ("Washington, DC" / "DPO, AE" / country name for abroad) and a
Domestic / International / Military filter.

### SL3 — Deliver By is hidden by default (low) → [`06`](issues/06-shipments-list-defaults.md)

Deliver By and Created At are in the column menu but off. Deliver By is the urgency
signal on a shipping list.

Fix: show Deliver By by default (it also needs S11's date fix).

### SL4 — Empty cells show two ways (low)

A manual Shipment has a blank Shipment Reference cell, while Company shows "—". Same
placeholder issue as S13.

### SL5 — Two rows of tabs above the table (low)

Four status tabs and six deliverability tabs sit side by side above the table. The
deliverability set is a filter more than a view; as a second tab row it competes with the
status tabs for attention.

Fix: keep status as tabs, move deliverability into the filter panel (it already holds
Picking Status, Channel, Shipping Method and Pack Slip), and keep a single "Needs address
attention" tab if one is wanted.

## Shipment view

### SH1 — "Not validated" above "Deliverable" (medium) → [`05`](issues/05-address-check-display.md)

The Shipping Address panel's subtitle says **Not validated**, and directly below it the
Shipment shows Deliverability **Deliverable**, Validated by, and an Address Check Result.
The subtitle actually means "the validator returned no corrected address"
(`ShipmentResource.php`, the `validated_address1` check), not that nobody checked. On
demo step 1 it reads as a contradiction. Related to, but not covered by,
[`release-polish/47`](../release-polish/issues/47-wording-operational-resources.md).

Fix: "Checked, no corrections" when the address was checked and nothing changed;
"Not checked yet" only when it was not.

### SH2 — "Validated by: Fake" (medium, demo) → [`05`](issues/05-address-check-display.md)

The `AddressValidator::Fake` label is "Fake", which reaches the Shipment view on any
instance using the sample validator, including the fake-carriers fallback demo. The
check result beside it already says "(sample validator)".

Fix: label it "Sample validator".

### SH3 — One Package, two statuses (medium) → [`release-polish/51`](../release-polish/issues/51-wording-the-same-thing-named-differently-across-pages.md)

Package 3571 is **Unshipped** in this Shipment's Packages table (`PackageStatus`) and
**Ready to ship** on its own Package page (`PackageDraftState`). A presenter moving
between the two pages sees the same parcel described two ways. Belongs with
[`release-polish/51`](../release-polish/issues/51-wording-the-same-thing-named-differently-across-pages.md).

The Packages list does it on one screen: its tab says **Unshipped** and the rows under it
say **Ready to ship**.

Fix: one vocabulary for an unshipped Package across every page.

### SH4 — Deliver By shows "—" here and a date on the Ship page (low)

The Shipment view shows Deliver By only when the order supplied one; the Ship page works
it out from the shipping method (Fri, Oct 23 for Standard Ground). Same Shipment, two
answers.

Fix: show the computed date here too, marked "from Standard Ground".

### SH5 — Five header buttons, no primary one (low)

Validate Address (filled green), Pack (filled), Print Pack Slip, View Pack Slip and Edit
(filled). Three filled buttons in two colors, and the action that moves the order forward
(Pack) is not the obvious one.

Fix: Pack as the one primary button; the rest gray, with Validate Address and the pack slip
actions in a menu.

### SH6 — Headings start with "View" (low)

"View #2000" here and "View Package #3571" on the Package page: Filament's default view
heading. Use "Shipment #2000" and "Package #3571".

### SH7 — Item "Value" is a unit value (low)

The items table's Value column shows the unit value: $97.81 × 1 + $20.24 × 2 = the
Shipment's $138.29. Label it "Unit Value", or add a line total.

### SH8 — Small formatting gaps (low)

The phone shows as "4151822223"; the Packages table shows weight as "0.30" with no unit
and leaves Service and Cost blank for an unshipped Package; and the items and packages
tables paginate one or two rows (as S14).

## Packages

### PK1 — "New package" creates a Package outside the packing workflow (medium, needs a decision) → [`11`](issues/11-setup-list-quick-fixes.md)

The Packages list has a prominent **New package** button (Admins only). Its form asks for a
Shipment, a free-text **Service (as bought)**, dimensions, weight and an **Exported**
toggle, and creates a Package with no items, no box and no Label, outside
`PackageDraftWorkflow`, which AGENTS.md says package preparation must go through. On a
demo it is the first button on the page.

Decide: remove Create from `PackageResource` (Pack and Manual Ship are how Packages are
made), or keep it as a recovery tool behind an action menu. The Edit form (Managers) was
not opened in this pass and may have the same fields.

### PK2 — Row actions are unlabeled icons (low)

Shipped rows end with a map icon (Track) and a printer icon (Reprint) under a column with no
header, then a ⋮ menu. Reprint appears only when the label image is stored, so some shipped
rows have it and others do not, with no explanation.

Fix: tooltips on the icons at least; show Reprint disabled with a reason when there is no
image.

### PK3 — Weight is formatted differently here (low)

"0.3 lbs" on this list; "0.30 lbs" on the Ship and Package pages; "0.30" with no unit on
the Shipment's Packages table (SH8). One format everywhere.

### PK4 — Demo data: shipped Packages cannot be reprinted, and test drafts sit on top (demo prep)

No seeded shipped Package has a stored label image, so none can be reprinted, and demo step
5 (reprint) only works on a label bought during the demo. The top of the list is test
drafts at 0.3 lb (3566–3569, 3571, 3572), the scale reading discussed in P8 and M2.
`demo:reset` before a demo clears the second; the first needs a sample label image on the
seeded Packages.

### PK5 — The Package edit form edits label and export state by hand (low)

Found while removing Create for PK1. The Edit form, for Managers on the Package page and in
a Shipment's Packages table, has a free-text **Service (as bought)**, raw dimensions and
weight, and an **Exported** toggle. Service is part of the active Label's projection
(ADR-0004 decision 3 keeps tracking number, cost and status off the form, but not this),
and turning Exported off or on by hand changes whether the export runs again. Dimensions
and weight on a shipped Package no longer match what was bought.

Decide: drop Service and Exported from the form, and allow dimensions and weight only on an
unshipped Package (or send edits back through Pack).

## Label Batches

Batch #1 bought USPS sandbox labels for three domestic Shipments (#1997–#1999) in an 8x8x8
box, $40.98 in all, then each label was voided from its Package page. The orders are on
the Shopify development store, so the purchase exported three fulfillments and each void
cancelled its fulfillment again; no "cancel failed" notification was raised.

The batch first sat at Pending for about ten minutes because the local dev stack's queue
worker had been down since a reboot (Vite could not resolve the app's hostname at boot and
`concurrently --kill-others-on-fail` took the workers with it). That is this machine, not
the product; BS1 is about what the page shows when it happens.

**Worked well:** a clear dialog; the progress page with totals, a progress bar and per-item
status; a completion notification ("3 labels bought, 0 failed — $40.98"); and void
reversing the Shopify export.

### LB1 — Nothing says how to make a batch (medium) → [`07`](issues/07-batch-ship.md)

The page shows an X icon and "No Label Batches". A batch starts from somewhere else
entirely: tick Shipments on the Shipments list, and a **Batch Ship** button appears in the
table toolbar, which asks for one box size for all of them. The button exists only while
rows are ticked, so nobody arriving at Label Batches, or at an unticked Shipments list,
would find it.

Fix: an empty-state description ("Select open shipments on the Shipments list and choose
Batch Ship") with a button to the Shipments list on its Open tab; consider a "Batch Ship"
header action here that does the same.

### LB2 — The toolbar breaks onto two rows (low) → [`07`](issues/07-batch-ship.md)

The filter icon sits alone on one row and the column toggle on a second row beneath it,
with no search box. Every other list has search, filter and columns on one row.

### LB3 — Four names for one feature (low) → [`07`](issues/07-batch-ship.md)

The sidebar and heading say **Label Batches**, the URL is `/batch-shipments`, the action
on Shipments is **Batch Ship**, and the progress page is `/batch-ship/{id}`
(`BatchShipResults`). Pick one name for the feature (Batch Ship) and one for the record
(Label Batch), and make the URL match.

### LB4 — The batch box list sorts as text (low) → [`07`](issues/07-batch-ship.md)

Batch Ship's Box Size select orders boxes by label as text, so "10x10x10" sorts before
"6x4x4". Same fix as M6: sort by volume, show the code.

### LB5 — Demo data: no Label Batches exist (demo prep)

With no batch in the data, this page is empty on a demo unless step 4 has been run first.
Either seed one finished batch (with a failure or two, to show the error handling) or run
step 4 before showing this page.

## Batch Ship

### BS1 — A batch nobody is processing says "Processing" forever (medium) → [`07`](issues/07-batch-ship.md)

With the queue worker down, the batch page showed **Processing**, 3 Pending and $0.00 for
about ten minutes, with nothing to say that no worker had picked it up. The same would
happen on an install whose queue container stopped.

Fix: once a batch has made no progress for a minute, say so ("Waiting for the background
worker; if this does not change, check the queue service"), and consider a dashboard
warning when the queue has not run recently.

### BS2 — "Buy and print" does not print (medium) → [`07`](issues/07-batch-ship.md)

The Batch Ship dialog says it will "buy and print a label for each selected shipment". It
buys them; printing is a separate step, the **Print Labels (3)** button that appears when
the batch completes, and nothing prompts for it. Beside it, **Reprint All (3)** appears
before any label has printed. On a demo the batch finishes and nothing comes out of the
printer.

Fix: say on completion "3 labels ready to print" with the Print button beside it (or
print automatically while the page is open, if that is the intent); show Reprint All only
once something has printed; change the dialog to "Buy a label for each…, then print them
from the batch page".

### BS3 — No preview of what will be bought (low) → [`07`](issues/07-batch-ship.md)

The dialog asks only for a box size. It does not show which carrier or service each
Shipment's method will buy, what weight each Package is bought at, or an estimated total,
before **Buy Labels** spends money. The skipped Shipments are listed only afterwards.

### BS4 — The results page is thin on links and names (low) → [`07`](issues/07-batch-ship.md)

The heading is "View Label Batch" while the notification calls it "Batch #1"; the
references are not links to their Shipments or Packages; the carrier is a logo only; and
the Printed column stays empty without saying "Not printed".

### BS5 — Void says nothing about Shopify, and a batch must be voided one Package at a time (low) → [`07`](issues/07-batch-ship.md)

For an exported Package, the void confirmation says only "Voids the label with USPS",
although it also cancels the fulfillment on the Shopify order. And undoing a batch means
opening each Package's page; there is no void from the batch page.

Fix: add "and removes the tracking number from the Shopify order" when the Package was
exported; consider "Void selected" on the batch's items.

## Picking

Viewed only: generating a pick batch claims Shipments, and printing sends pages to the
document printer. There are no pick batches in the data.

### PI1 — Pick Batches: an empty page with no way forward (low)

"No Pick Batches" with an X icon, and the same two-row toolbar as LB2. The sidebar
regrouping (PR #438) adds a Generate Pick Batch button to this page; the empty state should
also say what a pick batch is and point to it.

### PI2 — Generate Pick Batch shows nothing about what it will take (low)

The form says "Maximum number of **orders**" where the app says Shipments, and gives no
count of what is eligible ("25 of 312 open shipments not in a pick batch"), so a manager
generates blind. It also does not mention pack slips, although the picking workflow allows
printing them with the batch or separately; check where that choice is made and say so on
this form.

### PI3 — "Print next 10" does not say which ten (low)

The queue prints the next N Shipments in the table's order (oldest first) with its filters
applied, but the button says only "Print next 10". The button and "Batch size" are also
in sentence case, against the Title Case rule in
[`release-polish/51`](../release-polish/issues/51-wording-the-same-thing-named-differently-across-pages.md).

Fix: "Print Next 10 (Oldest First)", or say it under the tabs; "Batch Size".

### PI4 — A Pack Slip column that is almost always blank (low)

On the Not Printed tab, the Pack Slip column shows something only for a Shipment changed
since its slip printed; on every other row it is empty, so it reads as a broken column.

Fix: hide the column unless some row has that state, or make it a filter/tab ("Changed
since printed").

### PI5 — Relative dates here, absolute dates everywhere else (low)

Created shows "2 days ago" on this page; Shipments, Packages and the views show dates.
Pick one (absolute, with the relative form on hover).

## Reports

Demo step 7. Reviewed with the default filters, plus Volume's grouping buttons. Client
Billing is hidden with multi-client off and still needs a look with it on. Report data
correctness is pass 4's territory and already has findings
([`release-polish/27`–`33`](../release-polish/PRD.md)); these are about presentation.

### R1 — "Total Potential Savings" has no period, no scale and no regard for speed (medium) → [`10`](issues/10-report-presentation.md)

Rate Comparison opens on one big number, **$12,458.58**, with no period beside it (the
From filter below says 09/16), no share of total spend, and no sense of what was traded
for it: a row counts as a saving whenever any cheaper rate was quoted, whatever it would
have delivered (UPS $20.62 against FedEx $19.52 in the first rows). To a prospect it reads
as "you overpaid $12,000".

Fix: compare only against rates that met the same delivery date (or the order's deadline);
label the period ("Last 30 days"); show it as a share of spend; and say how it is counted.

### R2 — Volume's grouping buttons are one choice that looks like two (medium) → [`10`](issues/10-report-presentation.md)

Volume shows two button groups, By Channel / By Shipping Method and By Day / By Week / By
Month, which look like two independent settings. They are one: choosing By Week
unselected By Channel. It also silently moved the From date from 09/16 to 07/18, labels
weeks "2026-W42", and does not mark the current, partial week (410 packages against
800-plus in full ones).

Fix: one segmented control, or a "Group by" select; show the date range change; label weeks
by their dates ("Oct 12–18"); mark the current period as partial; add a totals row, and a
chart for the time groupings.

### R3 — Each report opens on a different date range (low) → [`10`](issues/10-report-presentation.md)

Rate Comparison, Volume (by channel) and Packages by User default to 30 days; Volume by
week to about 13 weeks; Packing Validation to 7 days; Prepaid Duties to the first of last
month. None says its range in words.

Fix: one default (or one per kind of report, stated), shown as "Last 30 days" beside the
heading.

### R4 — Packing Validation's difference is measured against the wrong weight (low)

A Package weighed at 0.30 lb against 4.09 lb expected shows **1,263.3%**: the difference as
a share of the actual weight. Measured against what it should weigh, it is 93% light, and
the more useful figure is the weight itself, "3.79 lb under". The summary card also says
"Shipped to Unverified Addresses" where its tab says "Shipped Despite Issues".

### R5 — Packages by User says little (low)

An "All Users / By User" toggle with no explanation, and one column of package counts.
Nothing on the period's working time or rate, which is what a manager would compare.

### R6 — Only Prepaid Duties exports (low)

Prepaid Duties has Export CSV; Rate Comparison, Volume and Packages by User do not,
although a 3PL bills from exactly these numbers.

### R7 — Demo data: Prepaid Duties is empty, under a dense paragraph (demo prep)

No DDP labels in the data, so the page is an empty table under a paragraph about DDP,
IOSS and carrier billing. Leave it out of the demo or seed a few DDP labels; the paragraph
could also be shorter, with the detail behind a link.

## Shipping Methods

### SM1 — The list hides what a method allows (low)

The list shows Name, Delivery Commitment (Days) and Active, in ID order. What a method
actually allows, its carrier services and postage sources (the allowance automation buys
within), is only visible inside each method, split across two of five tabs (Carrier
Services and Postage Sources, with Special Services, Aliases and Shipping Rules between).

Fix: a summary column ("4 services · Direct only"); on the edit page, put the two allowance
tabs side by side or first.

### SM2 — "Delivery Commitment (Days)" does not say which days (low)

Five days from Fri Oct 16 gave Fri Oct 23 on the Ship page, so they are business days.
Say "business days" on the field and the list column.

### SM3 — Three names for the deadline (low)

"Due-by date" in the method's Automated Purchases setting, "deliver-by date" on the Ship
page, "Deliver By" on Shipments. Belongs with
[`release-polish/51`](../release-polish/issues/51-wording-the-same-thing-named-differently-across-pages.md).

### SM4 — Small labels on the method form (low)

"Expedited" is explained only as pick-batch priority; if that is all it does, the label
should say so. The Postage Sources column "Beyond Listed Services" is cryptic for what
reads as "Listed services only". The UPS service code shows as a bare "03".

## Box Sizes

### BX1 — The list opens on inactive carrier packaging (medium) → [`11`](issues/11-setup-list-quick-fixes.md)

The first two pages are 35 inactive USPS flat-rate envelopes and boxes; the 13 boxes the
warehouse actually uses start on page three of five. A manager looking for "8x8x8", or a
prospect shown the page, sees carrier packaging nobody packs into.

Fix: open on active boxes (an Active / Inactive / All tab), active first, ordered by size.

### BX2 — No units, and a one-click Active toggle (low)

Length, Width, Height, Max Weight and Empty Weight have no units. The Active column is an
inline toggle: one click takes a box off the barcode sheet and out of the Pack lookup, with
no confirmation.

### BX3 — Flat-rate envelopes typed as padded mailers (low, check)

"USPS Flat Rate Envelope" and "USPS Legal Flat Rate Envelope" are typed **Padded Mailer**,
like the actual "USPS Padded Flat Rate Envelope". Check the reference data
(`resources/data`) and the physical-form choices; if there is no envelope form, the type
column misdescribes them.

## Products

### PR1 — Hazmat offers classes that do nothing (medium) → [`11`](issues/11-setup-list-quick-fixes.md)

The Hazmat Classification select offers **Dry Ice** and **Cremated Remains**, and the help
text under it says they "are not yet wired to carriers". Choosing one changes nothing on
the label or the rates, so a product marked dry ice ships as if it were not. That is a
compliance risk, not just a cosmetic one.

Special Services lists both as **inactive**, so the product form offers two classes the
rest of the app has switched off.

Fix: offer only the classes whose special service is active, or refuse purchase for a
package holding one, with a message.

### PR2 — The list leaves out what matters for shipping (low)

Name, SKU, Bin, Weight and Active. The barcode (what packers scan) is not shown; Bin is
"—" on every row; weight has no unit; and nothing shows whether a product's customs details
(HS code, origin, manufacturer part number) are complete, which EU labels now require.

Fix: show Barcode; hide Bin when no product has one; a "Customs incomplete" filter or
badge.

### PR3 — Small form wording (low)

"Sku" should be "SKU"; "Weight in pounds" repeats the field's "lbs" suffix.

## Carrier Accounts

### CA1 — Working accounts say "Needs Setup" (medium) → [`09`](issues/09-end-of-day-and-carrier-status.md)

UPS and USPS show **Needs Setup** in the list, and the USPS account says "OAuth Status: Not
connected", on the same day USPS bought four labels and UPS returned rates.
`CarrierAccount::connectionStatus()` counts a USPS or UPS account as Connected only when it
holds an OAuth token. These accounts authenticate with their own client ID and secret
instead: `USPSConnector` and `UpsConnector` read both from the `CarrierAccount`, with no
global fallback, and `hasUsableCredentials()` already accepts them. The Setup Wizard uses
the same status, so it also nags about them. On a demo, two of three carriers look broken.
The form's USPS section adds to the confusion by saying it "Overrides the global USPS
credentials", which do not exist.

Fix: base USPS and UPS status on `hasUsableCredentials()`, as FedEx does, and drop the
global-credentials sentence from the form. (Corrected 2026-10-16 after review of #439: an
earlier version blamed shared global credentials.)

### CA2 — The account form points users at a repository path (low)

"Advanced / API App Credentials" says "see docs/self-hosting.md". A user of the app has no
repository; link to the published guide instead.

## Carriers

### CR1 — Every carrier is active, with or without an account (low) → [`09`](issues/09-end-of-day-and-carrier-status.md)

All six carriers are active, including DHL Express, Amazon Shipping and OnTrac, which
have no carrier account here. That is what puts six End Shipping Day buttons on End of Day
(E1) and offers them wherever carriers are listed. The list has no column for accounts.

Fix: an Accounts column; consider seeding carriers without an account as inactive, or
treating them as such in operational pages.

## Carrier Services

### CS1 — 39 services with no way to narrow them (low)

The list shows the carrier as a logo only, has search but no carrier filter, and does not
say which shipping methods use each service. Finding "every UPS service in use" takes four
pages.

Fix: a carrier filter, the carrier name beside the logo, and a "Used by" column.

## Special Services

### SP1 — The list leads with internal codes (low)

The first column is the snake_case code (`lithium_battery_in_equipment`), with the name
second. "Needs Value" shows a red X for "no", which reads as an error. "Unrestricted"
under Carrier Services means "no service limit", which for an inactive dry-ice service
reads as "allowed everywhere". Active is an inline toggle again (BX2).

Fix: name first and code in small text beneath; a gray dash for "no"; "Any service" or
nothing for inactive rows.

## Connections

### CN1 — Nowhere says whether a connection is importing (medium) → [`13`](issues/13-connection-import-status.md)

The list's Updated column is when the record was edited, not when it last imported, and
the connection's own page has no import section at all: no last run, no result, no counts,
no errors. "Is my store importing?", the first question after connecting one, has no
answer in the UI.

Fix: a Last Import column (time and result) on the list, and on the connection page the
last few runs with new, updated and closed counts and any errors.

### CN2 — List and header details (low)

The list says "Driver" (call it Type) and leaves Schedule blank for a connection whose form
says "Disabled (manual only)". The form's header has five actions, three of them colored
(Reconnect Shopify in orange, Disconnect Shopify and Delete in red, side by side).

Fix: "Manual" in the Schedule column; Disconnect and Delete into a menu, away from each
other.

### CN3 — Internal names in the URLs (low)

Connections live at `/data-sources`, Map Amazon Services at `/unmapped-observed-services`,
Label Batches at `/batch-shipments` (LB3). The release-polish wording pass keeps internal
names out of the UI; the address bar is part of it, and a URL gets pasted into chats and
support emails.

## Channels

### CH1 — Nothing says what a Channel is (low)

The page lists names and Active only. It does not say how a Channel differs from a
Connection (a Connection is the account PolyBag imports from; a Channel is the sales
channel an order is attributed to), which connection feeds each one, or how many Shipments
or aliases each has. The data also has look-alike pairs, Amazon and Amazon US, Shopify and
Shopify Store, plus a Test Channel, which `demo:reset` should not leave behind.

## Mapping pages

### MP1 — The reference pages do not say what they are for (low)

Map Shipping Refs and Map Channel Refs show a table and "No unmapped references", with no
sentence on what a reference is (the text an order arrived with), where it comes from, or
what mapping it does (adds an alias, so later orders resolve on their own). Map Amazon
Services has exactly that kind of description; copy the pattern.

## Across pages

### X1 — Empty states use an X icon (low)

Label Batches, Pick Batches, Prepaid Duties and the mapping pages all show an X in a circle
over their empty message, which reads as an error rather than "nothing here yet".

Fix: a neutral icon per page (the page's own sidebar icon works), and one line on what
would appear here and how.

## Users

### AD1 — The list says little about who people are (low)

Email is empty for every user, Auto Ship "no" is a red X (as SP1), and there is no last
sign-in. The roles, User, Manager and Admin, are not described anywhere on the list or the
form, so whoever adds a packer has to guess which role packs and which one buys in batches.

Fix: one line per role in the Role select's help text; a Last Sign-In column.

### AD2 — The password help text is wrong on both forms (low)

"Leave empty for SSO-only users (no local password)." On create the field is required, so
it cannot be left empty; on edit an empty field keeps the current password (it is not
saved), so leaving it empty does not make anyone SSO-only. The four password rules also show
as orange "(not met)" before anything is typed.

Fix: on edit, "Leave empty to keep the current password"; show the rules only once typing
starts.

### AD3 — "Edit User" does not say which user (low)

The Product form's heading names the product; the user form says only "Edit User".

## Audit Log

### AL1 — The log is hard to read (low)

The heading says "Audit Logs" while the sidebar says "Audit Log". Rows name records as
"Shipment 7286" rather than the reference, with no link. Rows look identical when they are
not: each void wrote two "Shipment Updated" rows for its Shipment, and only opening one shows
that it recorded Shopify re-pointing the fulfillment order. The entry itself shows that as
raw JSON under Metadata, with Old and New Values empty.

Fix: a one-line summary column (from the metadata's reason, or the changed fields); the
reference with a link; the metadata as labeled fields.

## App Settings

### ST1 — Names and one wrong claim (low)

The heading says "Settings" while the sidebar says "App Settings". The Batch Shipping toggle
says "admins can select multiple shipments", but Batch Ship is open to Managers too. The
**Packing Validation** toggle (scan every item) shares its name with the **Packing
Validation** report (weight mismatches), which is about something else.

### ST2 — Shipping tab text written for another context (low)

The Customs section says "Must be set before a label to an EU country can be bought for
this client" on an instance-level page with multi-client off. The Google fallback says
"Billed to PolyBag, not this tenant", which is true only of the hosted service; on a
self-hosted install there is no PolyBag bill and no tenant.

### ST3 — Demo data: EU Duties Terms is not set (demo prep)

"Not set — EU labels are refused." Any EU order in a demo is refused at purchase until this
is set, as the release-polish PRD notes under *Demo environment*. Set it in `demo:reset`.

## Dev Settings

### DS1 — The sandbox switch: Amazon left out, and a tooltip that points to the wrong page (low)

Dev Settings exists only in local and testing environments, which is right. But its
Sandbox Mode text names USPS, FedEx and UPS, while Amazon follows the same setting
(`AmazonSpApiConnector`). And the Amazon connection's import button, disabled in sandbox,
says "Disable sandbox mode in App Settings", where there is no such switch; on any
non-local install the switch is on no page at all.

Fix: name Amazon in the Sandbox Mode text; point the tooltip at the right place, or say
who can change it.
