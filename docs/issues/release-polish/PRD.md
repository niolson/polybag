# Release polish: ready to demo to prospective customers

Status: reference

Repo: `polybag`

Opened 2026-10-12.

## Why

PolyBag is about to be shown to prospective customers. Since August, work has gone into
new capability: postage sources, label history, the carrier catalog, customs, pack
slips. Each change was reviewed on its own diff, and `project-review` checked whole flows
against their ADRs. Nobody has yet walked the app the way a prospect sees it: every page,
every message, every workflow from start to finish on realistic data.

This is a release. It has a scope, a finish line, and an end state that is tagged.

## Rules while it is open

- **No new features.** Bug fixes, layout, wording, demo data and tests only. Anything
  bigger gets an issue file in its own feature directory and waits.
- **The demo path gets the high bar.** Every page in the demo script below is polished.
  Pages off the path need to work and not embarrass anyone; they don't need polishing.
- **Findings are recorded before they are fixed.** Each pass collects findings first, so
  fixes can be done by category, worst first.

## Demo script

Proposed; edit to match the pitch. The **core** is what every demo shows and what the
exit criteria hold to. The **extensions** are shown when the audience calls for them, and
are polished only after the core is done.

### Core

1. **Orders arrive.** The Shipments list after an import, with validated addresses and a
   client indicator.
2. **Packing.** Scan a box, scan items, take the weight from the scale (`/pack/{id}`).
3. **Shipping.** Rates from several carriers, buy a Label and print it (`/ship/{id}`).
4. **Batch ship.** Labels for many Packages at once under shipping-method rules.
5. **Fixing mistakes.** Reprint, void, buy again.
6. **End of day.** A SCAN form (`/end-of-day`).
7. **Reports.** Volume, rate comparison, and client billing for the 3PL pitch.

### Extensions

- **Setup.** First-run setup wizard (`SetupWizard`), a Location, a carrier account and a
  Connection.
- **Picking.** Generate a pick batch and print the picking summary
  (`/generate-pick-batch`).
- **International.** A customs order through to its documents.
- **Multi-client.** The same flow for a second client with its own carrier account scope
  and pack slip branding.
- **Multi-location.** A second warehouse: orders routed to it from their source
  location, carrier accounts scoped to it, and End of Day run for one location at a time.

### Fallback on fake carriers

A sandbox will fail mid-demo sooner or later, so the core has to run on
`FAKE_CARRIERS=true` too. Today it cannot:

- `FakeCarrierAdapter` returns a blank 1×1 PDF, so a printed Label is an empty page.
- The fakes produce no customs documents.
- `ManifestService` calls USPS directly instead of going through the adapters, so End of
  Day fails on fake carriers.

Making the fakes demo-capable is in scope (issue `01`): a realistic sample label that is
obviously synthetic (marked SAMPLE, never scannable as a real barcode), sample customs
documents, and a fake manifest path. Until that lands, the fallback script is core steps
1–5 and 7 only.

**Switch to a separate instance, never flip the flag on the one in use.**
`FAKE_CARRIERS` swaps the adapters in `CarrierRegistry` for every Package, including those
bought before the switch. Buy a sandbox Label, switch to fakes, then void it: the fake
adapter reports success, and PolyBag clears the Label without cancelling it at the
carrier. Sample fixtures don't fix that. So the fallback is a second demo instance,
prepared in advance with `FAKE_CARRIERS=true` and its own `demo:reset` data, and the
demo moves to it rather than continuing the same records. Making one instance safe to
switch with purchases from both modes in it would need each Label to remember which
adapter bought it, and is out of scope.

## Passes

Each pass looks for one kind of problem.

1. **Does it work?** Run the demo script end to end on `demo:reset` data, once with
   `FAKE_CARRIERS=true` and once against the carrier sandboxes. Record every rough edge.
2. **Layout.** Screenshot every page at workstation resolution, in light and dark mode.
   Look for empty states, table columns, form section grouping, and consistency between
   resources.
3. **Wording.** Pull every user-facing string (labels, helper text, notifications,
   validation messages, empty states) into one list and read it side by side. Check terms
   against `CONTEXT.md`. Internal names must not reach the UI, for example DataSource
   where the UI says Connection.
4. **Bugs and architecture.** A time-boxed review by area, as `project-review` did, of
   the code changed since it (reviewed 2026-09-28, closed 2026-10-02): customs terms,
   address validation routing, pack slips, scan codes, label address characters. Bugs are fixed
   here. Architectural findings get filed and wait.
5. **Regression net.** Extend `tests/Browser` past `ShippingFlowTest` to cover
   pack → ship → reprint → void → buy again → reprint, asserting that a reprint
   straight after the void is refused, plus batch ship and end of day. Then fixes made during the
   other passes cannot quietly break the demo.

## Open issues against the release

| Directory | Call | Why |
|---|---|---|
| Fake carriers for the fallback (`01`) | **In** | The fallback script cannot print, document or manifest without it |
| `box-size-seed-data` | **In** | Default box names disagree with their dimensions; a prospect sees them first. `02` and `03` (active/inactive, the inactive carrier catalog) brought in 2026-10-12 so the barcode sheet prints on one page without deleting sizes |
| `order-reconciliation` `01` | **In** | Showing where each void came from is a display fix |
| `end-of-day-count` | **Check in pass 1** | End of Day is on the demo path |
| `order-reconciliation` `06`, `07`, ADR-0009 | **Before the first customer**, off the demo's critical path | Imports closing the orders a channel closed, so they are not shipped twice; the billing definition |
| `international-customs-terms` | Out, but see demo data | Blocked on carrier answers |
| `shelved-drafts`, `reshipments`, `warehouse-permissions` | Out | Features |
| `pii-retention`, `product-identifier-import`, `eu-product-identifiers` | Out | Not visible in a demo |
| `shopify-shipping-carrier`, `amazon-buy-shipping`, `amazon-shipping-external-orders`, `postage-source-split`, `package-label-history`, `usps-military-destinations`, `usps-processing-category` | Out unless pass 1 hits them | Tails of finished features |
| `tech-debt` Phases 2–4 | Out | Refactors and polish don't mix |

## Demo environment

- **Realistic data.** Clients, products with weights and images, international orders,
  and more than one Client for the 3PL pitch.
- **Customs terms.** The demo Clients need a duties policy for EU destinations. Under
  ADR-0008 decision 1, an EU Label with no terms chosen is refused.
- **A fresh install.** Run `scripts/install-onprem.sh` on a clean VM. For a prospect who
  self-hosts, that is the first impression.
- **Hardware rehearsal.** QZ trust, scale pairing and printer setup on a workstation
  other than the developer's.
- **A fallback.** A second instance on fake carriers, reset alongside the main one before
  each demo. Rehearse moving to it mid-demo (see the fallback above).

## Exit criteria

- The core script runs clean three times in a row on fresh `demo:reset` data, against
  the sandboxes and on fake carriers.
- The browser tests in pass 5 pass in CI.
- No open finding marked high severity on the demo path.
- The fresh install succeeds from the README instructions alone.
- `main` is tagged, and the tag is what gets demoed.

## How findings are recorded

Each finding is an issue file, `issues/NN-<slug>.md`, numbered in the order found, with a
`Pass:` line (1–5), the page or flow, and a severity:

- **high** — breaks a demo-path step, or loses or misstates data
- **medium** — wrong or confusing on the demo path
- **low** — off the path, or cosmetic

## Comments

- 2026-10-14 — Triaged the pass 3 and 4 findings into three groups. Fixed now, on the demo
  path (#421): `04`, `05`, `10`, `13`, `17`, `18`, `27`, `30`, `31`, `39`, half of `03`, and
  the demo-path wording of `47`–`51`; `16` closed as intended. Before the first customer, kept
  here: `02`, `06`, `20`, `23`, `28`, `29`, `32`, `34`, `35`, `40`, `43`, `44`. Deferred and
  moved to their feature directories, numbered there: `08`, `09`, `37` to
  `international-customs-terms` (`13`–`15`); `24`, `38` to `pii-retention` (`05`, `06`); `25`,
  `26` to `order-reconciliation` (`12`, `13`); `41`, `42` to `eu-product-identifiers` (`08`,
  `09`). The rest are low and off the demo path, fixed if their file is open anyway.
