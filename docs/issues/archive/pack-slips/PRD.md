# Pack slips, separated from picking

Status: reference
Created: 2026-09-30

## Problem Statement

The core loop of PolyBag is: print a pack slip for each Shipment, and scan that slip at
Scan & Pack to load the Shipment. Today the only way to print pack slips is from a pick
batch. That has three consequences:

- A tenant that picks with another system, or does not batch-pick at all, has to turn on
  picking and generate pick batches just to get pack slips. Generating a batch is
  Manager-only, and it changes the Shipments' picking status as a side effect.
- Nothing shows which open Shipments have had a pack slip printed. The only record is on
  the pick-batch membership, so "what still needs a slip?" cannot be answered outside a
  batch.
- A pack slip counts as printed as soon as the PDF is sent to the browser, before the
  print bridge has even accepted the job. If the bridge is not connected or rejects the
  job, the Shipment looks done, and nobody will pack an order whose paper never came out.

A third kind of tenant prints no pack slips from PolyBag at all. Their ERP prints a slip
with a barcode that encodes the ERP's own shipment ID, and a Database connection imports
that ID as the Shipment's `shipment_reference`. That already works: Scan & Pack opens a
Shipment by an exact match on `shipment_reference`. But PolyBag's pack slip screens and
indicators still appear for these tenants, which is noise.

## Solution

Pack slips become their own step, independent of picking.

- Two tenant-wide settings, also asked in the Setup Wizard: **PolyBag prints pack slips**
  (new, on by default) and **PolyBag prints pick batches** (the existing picking setting).
- A new **Print Pack Slips** page lists open Shipments whose pack slip has not been
  printed, or is out of date. The operator prints the next N (a batch size), or selected
  Shipments, in a sensible order.
- A pack slip counts as printed only when the print bridge reports that it sent the job to
  the printer. That is the limit of what the bridge can tell PolyBag: a printer that jams
  afterwards is recovered by reprinting from the Printed tab. On workstations without the
  print bridge, an explicit **Mark as printed** on the viewed slips does the same thing by
  hand.
- The printed state lives on the Shipment. Slips printed from a pick batch and slips
  printed from the new page are the same thing.
- If a Shipment's line items change after the data on its latest slip was read, the slip
  is out of date. The Shipment returns to the page flagged as changed, and Scan & Pack
  warns the packer that the latest slip is out of date.
- Pick batches keep their separate Picking Summary and Pack Slips buttons, so either
  order works (slips into the bins first, or pick first and print slips afterwards), and
  gain a **Print Both** button.
- ERP tenants turn pack slips off. PolyBag then shows no pack slip screens or indicators,
  and Scan & Pack keeps opening Shipments by their ERP reference.

## User Stories

### Settings and setup

1. As an admin setting up PolyBag, I want the Setup Wizard to ask who prints our pack slips (PolyBag or another system), so that PolyBag shows only the screens we will use.
2. As an admin setting up PolyBag, I want the Setup Wizard to ask whether we pick orders with PolyBag, so that pick batches appear only if we use them.
3. As an admin who answers that we pick with PolyBag, I want to be offered "Require picking before shipping" in the wizard with the same explanation as in Settings, so that I can decide it during setup.
4. As an admin whose ERP prints pack slips, I want the Order Import step to tell me to map the value our pack slip barcode encodes to Shipment Reference, so that our existing slips open Shipments at Scan & Pack.
5. As an admin, I want the wizard's Summary step to show both workflow answers, so that I can check them before finishing.
6. As an admin, I want both workflow settings in Settings as well, so that I can change them after setup.
7. As an admin, I want the "Require picking before shipping" help text to say that pack slips then print from pick batches, so that I understand why the Print Pack Slips page goes quiet.
8. As an admin who turns pack slips off, I want the pack slip branding fields to stay (shown as inactive, with a note), so that turning pack slips back on does not lose our logo and message.
9. As an admin of an existing install, I want pack slips to stay on after upgrading, so that nothing about our current workflow disappears.

### Print Pack Slips page

10. As a shipper, I want a Print Pack Slips page listing open Shipments whose pack slip has not been printed, so that I know what still needs paper.
11. As a shipper, I want to print the next N pack slips with one action, so that I can produce a stack of a size that suits our floor.
12. As a shipper, I want the batch size to be remembered for me, so that I do not re-enter it every time.
13. As a shipper, I want to select particular Shipments and print only those, so that I can push a rush order through.
14. As a shipper, I want "Print next N" to take the most urgent Shipments (expedited first, then oldest), whichever Client they belong to, so that one Client's backlog never holds back another's expedited orders.
15. As a 3PL shipper, I want the slips in a run that covers several Clients to come out grouped by Client, keeping urgency order within each, so that each client's slips come out together.
16. As a 3PL shipper, I want to filter by Client, so that I can print one client's work at a time when that is how we pack.
17. As a shipper, I want to filter by Channel and Shipping Method, so that I can print, say, only today's expedited Amazon orders.
18. As a 3PL, I want each slip branded with its own Shipment's Client (logo, company name, message, return address), falling back to the tenant's pack slip logo, so that a mixed run is still correctly branded.
19. As a shipper, I want each slip to carry the Shipment's PolyBag scan code and its order reference, so that it opens exactly that Shipment at Scan & Pack.
20. As a shipper, I want a Shipment to leave the "Not printed" list only after the print bridge sent its slip to the printer, so that a print that never left the workstation never makes an order look done.
21. As a shipper whose print failed, I want to be told the slips were not recorded as printed, so that I retry rather than assume.
22. As a shipper without the print bridge installed, I want to view the slips in the browser and then mark those slips as printed, so that I can still use PolyBag.
23. As a manager, I want every printed mark (automatic or manual) to record who did it, so that an unexpected mark can be traced.
24. As a shipper, I want marking slips as printed by hand to record the slips I actually viewed, so that marking a slip I viewed before its items changed does not hide the change.
25. As a shipper, I want a late acknowledgment of an older print never to overwrite a newer one, so that an outdated warning does not come back and the right person stays recorded.
26. As a shipper, I want a large run sent as several print jobs of limited size, so that one failure cannot leave me retrying hundreds of slips.
27. As a shipper, I want Shipments that are in an in-progress pick batch left off the page, with a line saying how many were left off and why and linking to those batches, so that I do not print a second slip without its tote code.
28. As a shipper, I want a Shipment whose pick batch is cancelled to reappear on the page, so that it is not stranded.
29. As a shipper when picking is required before shipping, I want the page to explain that pack slips print from pick batches and link there, instead of listing slips for Shipments I cannot pack yet.

### Reprints and out-of-date slips

30. As a shipper whose printer jammed mid-run, I want a Printed tab listing open Shipments by most recently printed, so that I can select the run and reprint it.
31. As a shipper who lost one slip, I want to reprint it from the Shipment's page, so that I do not have to find it in a list.
32. As a shipper reprinting a slip for a Shipment in a pick batch from its page, I want to be told the tote code is not on it, so that I know to write it on or use the batch.
33. As a shipper, I want a Shipment whose items changed after its slip was printed to reappear on the Not printed tab, marked "Changed since printed", so that the box does not ship with a wrong slip.
34. As a packer, I want Scan & Pack to warn me when the Shipment's latest recorded pack slip is out of date, without blocking me, so that I can reprint it and still pack. (The barcode names the Shipment, not the copy, so an older copy scanned after a current reprint gives no warning.)
35. As a packer, I want an address change alone not to mark the slip out of date, so that I am not interrupted for something the label covers.

### Pick batches

36. As a manager, I want to print the pick summary and the pack slips in one action, so that I can put slips in the bins before picking with one click.
37. As a manager, I want to keep printing the summary and the slips separately, in either order, so that a team that picks first and prints slips afterwards can still work that way.
38. As a manager, I want batch pack slips to count as printed only when the print bridge succeeds, like the new page, so that the two agree.
39. As a manager printing batch slips from the browser, I want to mark the viewed batch slips as printed, so that browser-printed batches are not stuck looking unprinted.
40. As a manager, I want batch pack slips hidden when PolyBag does not print pack slips, so that an ERP tenant using pick batches sees only the pick summary.

### Visibility

41. As a manager, I want a "Pack slip printed" column and filter on the Shipments list, so that I can see at a glance which orders have paper.
42. As a manager, I want the Shipment page to show when its slip was printed, by whom, whether it is out of date, and which pick batch it is in, so that I can answer "where is this order's slip?".

### ERP-printed pack slips

43. As a tenant whose ERP prints pack slips, I want Scan & Pack to open a Shipment when I scan the ERP's barcode, as long as it matches the imported Shipment Reference exactly, so that our existing paperwork drives packing.
44. As a tenant whose ERP prints pack slips, I want no Print Pack Slips page, no pack slip buttons, and no printed indicators, so that PolyBag does not suggest a step we do not do.

## Implementation Decisions

### Terms

- The document is a **pack slip**. Customers may say "invoice"; PolyBag's code and prose
  say pack slip. Add it to `CONTEXT.md` with a short definition.
- A pack slip is **printed** when the print bridge reported that it sent the job to the
  printer, or a user marked the viewed slip printed. "Printed" never means the paper
  physically came out: the bridge cannot report that reliably. It is **out of date** when
  the Shipment's items version has moved past the version the slip was rendered from.

### Settings

- New tenant-wide setting `pack_slips_enabled`, default true (also for existing installs).
  Tenant-wide, not per Client: a mixed setup where PolyBag prints some clients' slips and
  an ERP prints others' is considered unlikely. If it appears, the setting can move to
  Client without changing the rest of this design.
- `picking_enabled` is unchanged apart from its label ("PolyBag prints pick batches").
  `require_picking_before_shipping` stays nested under it.
- The two settings are independent. All four combinations are valid, including picking on
  with pack slips off (an ERP that prints slips, with pick summaries from PolyBag).
- Setup Wizard: a new **Workflow** step between Channels & Shipping and Order Import,
  asking both questions. Answering "another system prints pack slips" shows the
  Shipment Reference mapping note on the Order Import step. The Summary step lists both.

### Schema

- Shipments gain:
  - `items_version`: an unsigned integer, default 0, incremented whenever the Shipment's
    items change.
  - `pack_slip_items_version`: nullable; the items version the latest recorded slip was
    rendered from. Null means never printed.
  - `pack_slip_receipt_issued_at`: microsecond precision; when the receipt behind the
    latest recorded slip was issued. It orders receipts of the same version.
  - `pack_slip_printed_at` and `pack_slip_printed_by` (user, nullable, null on user
    delete): when and by whom the latest print was recorded, for display and for
    sorting the Printed tab. They do not decide whether a slip is out of date.
- Out of date is `items_version > pack_slip_items_version`, compared as integers, so
  there is no timestamp precision problem.
- The migration copies the latest `pack_slip_printed_at` from pick-batch membership onto
  each Shipment and sets `pack_slip_items_version` to the Shipment's current
  `items_version` (0) for those rows, then drops the column from pick-batch membership.
  The batch's `summary_printed_at` stays.
- An index supporting the queue query (open status, printed version).

### Modules

- **Pack slip queue.** One place that answers which Shipments belong on each tab:
  - Not printed: open Shipments never printed, or whose slip is out of date.
  - Printed: open Shipments whose latest slip is current, most recently printed first.
  - Excluded from both: Shipments in an in-progress pick batch. The queue also reports how
    many were excluded and which batches, for the explanatory line.
  - When picking is required before shipping, the queue reports that pack slips print from
    batches, and the page shows its empty state instead.
  - **Selection priority and paper grouping are separate.** The queue orders by
    expedited first, then oldest first, and that order decides which Shipments "Print
    next N" takes and what the table shows first. Client plays no part in selection, so
    a steady backlog for one Client can never hold back another Client's expedited
    orders.
  - Once the Shipments are chosen, whether as the next N or as the user's selection, the
    printed run is grouped by Client (Clients ordered by name), keeping the priority order
    within each Client.
  - Filters: Client, Channel, Shipping Method.

  The Filament page, "Print next N" and "Print selected" all use it, so the page and the
  N-selection cannot disagree about what is waiting.
- **Pack slip renderer.** Takes an ordered set of Shipments and an optional tote code per
  Shipment, and returns the pack slip document (the HTML view, and the PDF through the
  existing PDF renderer). Branding resolves per Shipment from its Client, falling back to
  the tenant's pack slip logo. The tote row prints only when a tote code is given. The
  pick batch view and download route, the new page and the single-Shipment action all
  use it. It takes over the rendering and logo resolution currently duplicated between
  the pick batch controller and the View Pick Batch page.
- **Print receipts.** Every rendering of pack slips, whether as a PDF for the print bridge
  or as the HTML view, comes with a signed, expiring receipt. The receipt names, for each
  Shipment, the `items_version` read **before** that Shipment's items were loaded, plus
  the user and an issue time in microseconds. Reading the version first means a change
  that lands while the slip is being rendered leaves it out of date: the check errs
  towards a reprint, never towards stale content being treated as current. The browser
  can only acknowledge Shipments the server rendered, which follows the rule that browser
  state is never the authority.
- **Redeeming a receipt** records a print for each Shipment in it, as one conditional
  update per Shipment that only moves forward. It applies only when the receipt's
  (items version, issue time) is newer than the stored
  (`pack_slip_items_version`, `pack_slip_receipt_issued_at`), or nothing is stored. It
  then writes those two values plus `pack_slip_printed_at` (now) and
  `pack_slip_printed_by`. So a duplicate redemption changes nothing, and a late
  acknowledgment of an older document cannot overwrite a newer print, bring back an
  outdated warning, or change the recorded user. Shipments that have since shipped or
  been deleted are skipped. Redeeming changes nothing but these fields.
- **Manual mark.** "Mark as printed" redeems the receipt of the document the user viewed,
  exactly like an automatic acknowledgment. It never records a print against the
  Shipment's current state. If an import changed a Shipment's items after the user
  viewed its slip, marking that view printed leaves the Shipment out of date. It is
  always an explicit action and never happens automatically. It is also the recovery path
  when a print succeeded but its acknowledgment did not reach the server.
- **Item versioning.** `items_version` is incremented atomically (a database increment,
  not read-modify-write) when a Shipment's items are created or deleted, or when an
  item's product or quantity changes. `ShipmentItem` model events cover this for UI
  edits and for the item importer, which writes rows one at a time and so fires them.
  The importer's authoritative-items removal is a bulk delete that fires no events, so
  that path increments the version explicitly. An update that leaves product and
  quantity unchanged (an import with nothing new) does not increment it, so re-imports
  do not mark every slip out of date. Address and other header changes do not
  increment it.
- **An item change and its version increment commit together or not at all.** Every
  path that writes items runs the item write and the increment in one database
  transaction: one Shipment's item import (including the authoritative bulk delete), and
  each UI create, edit or delete of an item. If the increment fails, the item change
  rolls back with it, so an item can never change while its slip still looks current.

### Print path in the browser

- The existing report-print listener gains an optional receipt. When the print bridge
  reports the job sent, it posts the receipt to a new acknowledgment endpoint, in the
  same way labels post their print acknowledgment. Success is reported as "Sent to the
  printer", not "Printed", and names the recovery: if paper did not come out, reprint
  from the Printed tab. A failed print or a failed acknowledgment is shown to the user.
  A failed acknowledgment says the slips were sent but not recorded, and offers Mark as
  printed for the same receipt.
- One print run is one print-bridge job. On failure, nothing in the run is marked.
- Every HTML view of pack slips (from the new page, a pick batch, or a Shipment) carries
  its receipt and a Mark as printed control, hidden from print output. That is the one
  manual path, so browser-printed batch slips and single slips are confirmed the same
  way as queue slips.
- **One QZ job holds at most about 200 slips**, with the exact figure set during
  implementation. The limit is enforced once, in the shared print path, for every
  caller: the new page, pick batches (which allow up to 500 Shipments) and Print Both.
  - A run larger than the limit is sent as consecutive jobs of up to that size, each
    with its own receipt, in the run's order.
  - The run stops at the first failed job. Earlier jobs stay recorded, and the user is
    told how many slips were sent and where it stopped.
  - Print Both sends the picking summary first, then the pack slip jobs.
  - Nothing refuses a run for its size; the limit only bounds how much one failure costs.

### Screens

- **Print Pack Slips** page in the Operations navigation group, visible when
  `pack_slips_enabled`. Tabs for Not printed and Printed. Header action "Print next N"
  with a per-user batch size (default 25), kept like the other workstation preferences.
  Bulk actions "Print selected" / "Reprint" and "View", where the view carries the Mark
  as printed control. Rows show "Changed
  since printed" where it applies. A line above the table explains Shipments left off
  because they are in active pick batches, with links to those batches. Empty state when
  picking is required before shipping.
- **View Pick Batch:** the existing Picking Summary and Pack Slips buttons remain, plus
  Print Both (summary then slips, as two print-bridge jobs; the slips carry a receipt).
  Pack slip buttons are hidden when `pack_slips_enabled` is off. In print mode, batch
  pack slips are acknowledged through receipts; the batch no longer marks printed on
  render. In view mode, the batch's pack slip view carries its receipt and the Mark as
  printed control. The batch's Shipments table keeps its Slip Printed columns, reading
  the Shipment's printed state, and hides them when `pack_slips_enabled` is off.
- **Shipment page:** a Print / Reprint Pack Slip action, and the slip's printed
  time, printed by, out-of-date state and active pick batch. A slip reprinted here for a
  batched Shipment carries no tote code, and the action says so.
- **Shipments list:** a "Pack slip printed" column and a printed / not printed / out of
  date filter, hidden when pack slips are off.
- **Scan & Pack:** a non-blocking warning, "This Shipment's latest pack slip is out of
  date", shown only when pack slips are on. It describes the latest recorded slip, not
  the paper that was scanned: the barcode names the Shipment, not the copy. No change to how scans resolve: PolyBag scan codes are
  matched exactly, and anything else is matched exactly against `shipment_reference`.

### Permissions

- Shippers (the `User` role) can use the Print Pack Slips page: print, reprint and mark
  as printed. This follows the direction in `warehouse-permissions` ("Print pack slips:
  Allow"). The pack slip shows no prices, which answers that issue's open question for
  this document.
- Creating pick batches stays Manager-only; that decision belongs to
  `warehouse-permissions`.

### ERP-printed pack slips

- No new matching logic. For a Database connection the ERP's shipment ID mapped to
  `shipment_reference` is also the Shipment's source record identity and the key the
  item query is bound to, so one ERP primary key imports the Shipment, pulls its items,
  and opens it at Scan & Pack.
- Matching stays exact (after trimming whitespace). An ERP barcode that differs from the
  stored reference (zero-padding, a prefix, a check digit) is out of scope. When a real
  tenant needs it, the fix is a normalization rule on the connection, not a global one;
  see Out of Scope.

## Testing Decisions

A good test here drives the behavior a user or the print bridge sees: which Shipments a
tab lists, what a receipt redemption records, what a page shows or hides. It does not
assert on query structure or on which method was called.

- **Pack slip queue** (feature, database-backed): each exclusion and inclusion rule
  above, individually: never printed, printed, out of date, in an active batch, in a
  cancelled batch, shipped, picking required. Also: "next N" takes the top of the
  priority order regardless of Client. For example, with Client A holding more than N
  older standard orders and Client B one expedited order, B's order is selected. The
  printed run is then grouped by Client with priority order kept within each. Prior art:
  `PickBatchService` tests of `autoGenerate` ordering and filtering.
- **Print receipts** (feature): redeeming a valid receipt records the receipt's version,
  its issue time and the user; a tampered, expired or other-user receipt is refused; a
  Shipment shipped between render and redemption is skipped. An item change between
  reading the version and redemption leaves the slip out of date, including a change
  made while the slip was being rendered. Redeeming the same receipt twice changes
  nothing. Redeeming an older receipt after a newer one leaves the newer print, its
  user and its current state in place. Manual Mark as printed of a view whose items
  have since changed leaves the Shipment out of date. Prior art: the label print
  acknowledgment tests.
- **Item versioning** (feature): an import that changes an item's product or quantity,
  adds one or removes one increments `items_version`, and one that changes nothing
  doesn't. The authoritative-items bulk delete increments it; this test must fail if
  that explicit increment is removed. Editing items in the UI increments it; an
  address-only change does not. A failure injected into the increment rolls back the
  item change, both for an import (including the bulk delete) and for a UI edit.
  Prior art: shipment import tests covering `update_if_changed`.
- **Pack slip renderer** (feature, HTML view): per-Shipment Client branding and fallback,
  the tote row present only with a tote code, the scan code and reference on each slip.
  Prior art: existing pick batch pack slip view tests.
- **Screens** (Filament/Livewire): the Print Pack Slips page tabs, actions, the
  active-batch line and the picking-required empty state; everything hidden when pack
  slips are off; View Pick Batch Print Both and receipt dispatch; the Shipment page
  action; the Scan & Pack warning. Prior art: the existing View Pick Batch, Pack and
  Settings page tests.
- **Setup Wizard** (Filament): the Workflow step saves both settings, and the Order Import
  note appears only for "another system".
- **Migration:** the batch printed time is copied onto Shipments.
- **Screens with a view:** the batch, Shipment and queue HTML views each carry a receipt
  and a working Mark as printed.
- The browser half of the print path (posting the receipt after the bridge reports the
  job sent) is covered by the endpoint tests plus a manual check with a real print
  bridge; there is no QZ-driven browser test today.

## Out of Scope

- Per-Client choice of who prints pack slips.
- Knowing that paper physically came out. `qz.print()` resolves when the job is sent to
  the printer; completion and jam reporting through printer status depends on the driver
  and is not reliable enough to build on. Recovery is the Printed tab.
- Identifying which copy of a slip was scanned. The barcode names the Shipment
  (ADR-0007); putting a document version in it would reopen that ADR. An old copy left
  in a tote after a current reprint is a floor-process matter.
- Normalizing scanned references (padding, prefixes, check digits, case) before matching.
  Recorded as a `needs-info` follow-up, to be picked up when a real ERP needs it.
- Named or stored print runs ("reprint run #14"). The Printed tab sorted by most recent
  covers jams.
- A reprint count or print history beyond the latest print and who did it.
- Changing who may create pick batches (`warehouse-permissions`).
- Detecting orders cancelled or edited at the source while a Package Draft is shelved
  (`shelved-drafts`). This PRD's out-of-date rule only sees item changes that an import
  or the UI actually writes.
- Scanning an external reference that begins with the scan-code prefix (`scan-codes`
  issue 01).
- Changes to the pack slip layout beyond making the tote row optional.

## Further Notes

- The existing behavior of marking batch pack slips printed when they render is a defect
  this PRD fixes. It should not be kept for compatibility.
- The ERP workflow this was designed around: the ERP prints a slip per shipment whose
  barcode encodes the ERP's shipment primary key, and the same key is what the ERP's
  tables are queried by. PolyBag's Database connection already works that way.
- Related: ADR-0007 (scan code grammar) for what a pack slip barcode encodes;
  `warehouse-permissions`, `shelved-drafts` and `scan-codes` as noted above.
