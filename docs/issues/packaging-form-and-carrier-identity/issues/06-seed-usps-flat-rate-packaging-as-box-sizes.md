# Seed USPS flat-rate packaging as Box Sizes?

Status: done — option 1, shipped 2026-09-16; the starter set carries the six Priority Mail flat-rate packagings (seven rows, the medium box in both shapes) with `carrier_packaging` stamped, and code `16` — already labelled the padded envelope — is stamped on reseed

Repo: `polybag`

## Parent

[ADR-0005](../../../adr/0005-packaging-form-and-carrier-identity.md), "Foreseen, not
decided — seeding carrier packaging as box sizes".

## What to decide

USPS publishes the dimensions of its flat-rate packaging, and the flat-rate services
ignore weight to 70 lb, so a seeder could create the six USPS rows — three envelopes,
three boxes — as `BoxSize`s with `materials_cost = 0`, the published dimensions,
`type` set to the right physical form (`PADDED_MAILER` for the padded envelope, `BOX` for
the boxes; the plain and legal envelopes are the open question, since nothing today
represents a flat envelope as a form), and `carrier_packaging` set. `BoxSize` has no
location scope, so there is no per-location choice to offer and no packaging inventory
to model.

Three options:

1. **Global, in `BoxSizeSeeder`.** Every install gets them, with codes an operator can
   scan. Simplest; puts six rows in front of a warehouse that stocks none of them, and
   the Pack page's box-code scan is where a wrong row costs time.
2. **Opt-in.** A Setup Wizard step or a Box Sizes page action, "Add USPS flat-rate
   packaging", idempotent on `carrier_packaging`. Lets the warehouse that has them get
   them in one click; adds a UI surface.
3. **By hand.** Operators create rows as they would any box size. Nothing to build;
   dimensions get typed wrong.

FedEx and UPS packaging is a separate question: their dimensions are also published, but
One Rate is the only service that prices on them today, and `03` carries every existing
FedEx declaration across.

## Acceptance criteria

- [x] One of the three is chosen and the reason recorded here
- [x] If 1 or 2: the seeder or action, idempotent, with barcode codes that do not collide
      with `BoxSizeSeeder`'s `01`–`21`, and a test
- [ ] If 3: this issue closes `wontfix` with the reason

## Blocked by

- [`03`](03-box-size-carrier-packaging-replaces-fedex-package-type.md) — the column

## Decision

**Option 1**, for two reasons that were true before this issue was written:

- `BoxSizeSeeder` is not global. It runs only when the Setup Wizard's "Load recommended
  starter box sizes" toggle is on (default off) or under `db:seed`, so an install that
  stocks no USPS packaging never sees the rows unless it asked for the starter set — and
  the wizard already says the rows can be edited or removed. Option 2's opt-in already
  exists; building a second one would be a UI surface for a choice the wizard makes.
- `03` had already put FedEx packaging in the same seeder as `18`–`21` with
  `carrier_packaging` stamped. USPS following the same path is the consistent choice;
  a separate action for USPS would have left the two carriers seeded two different ways.

The seeder also carried code `16`, "USPS Flat Rate Padded Envelope", with **no**
`carrier_packaging` — so scanning it rated as the packer's own padded mailer, never at
the flat-rate price `05` made possible. `updateOrCreate` on code stamps it on the next
reseed, which the test covers for an install that seeded it before this change.

## What shipped

Seven rows, all `materials_cost = 0` (USPS supplies them free) and `max_weight = 70`
(the flat-rate services price to 70 lb; the rest of the seeder's `35` is a packer's-own
default), published outside dimensions:

| Code | Label | Form | `carrier_packaging` |
|---|---|---|---|
| `16` | USPS Padded Flat Rate Envelope | `PADDED_MAILER` | `usps_padded_flat_rate_envelope` |
| `22` | USPS Flat Rate Envelope | `PADDED_MAILER` | `usps_flat_rate_envelope` |
| `23` | USPS Legal Flat Rate Envelope | `PADDED_MAILER` | `usps_legal_flat_rate_envelope` |
| `24` | USPS Small Flat Rate Box | `BOX` | `usps_small_flat_rate_box` |
| `25` | USPS Medium Flat Rate Box (top-loading) | `BOX` | `usps_medium_flat_rate_box` |
| `26` | USPS Medium Flat Rate Box (side-loading) | `BOX` | `usps_medium_flat_rate_box` |
| `27` | USPS Large Flat Rate Box | `BOX` | `usps_large_flat_rate_box` |

- The two flat envelopes are `PADDED_MAILER`, the same call `03` made for the FedEx
  Envelope at `20`: nothing today represents a flat envelope as a form, and the ADR's
  "Foreseen, not decided" keeps `LETTER`/`FLAT` for shipper-owned envelopes, which these
  are not. The form is not what prices them; `carrier_packaging` is.
- The medium box is two shapes (11¼×8¾×6 top-loading, 14×12×3½ side-loading) with one
  carrier identity. Both are seeded so the label on the Pack page matches the box in the
  packer's hand; the rating is identical.
- The three Priority Mail Express envelopes are not seeded. They are separate USPS stock
  and nobody has asked; a warehouse that has them adds three rows.

`tests/Feature/BoxSizeSeederTest.php`: every case present with the right form, weight
and cost; the medium box twice and no Express envelope; a reseed adds nothing; a
pre-existing unstamped `16` is stamped.

## Not in this slice

- **An active/inactive flag on `BoxSize`.** Raised while triaging: seed everything and
  let a warehouse switch off what it does not stock. Reasonable on its own — a box size
  that a `label_batches` row references cannot be deleted, so "remove it later" is not
  always available — but it is a column plus a filter in `CacheService`, Pack, Manual
  Ship, `ShipmentResource`, Print Barcodes and the resource UI: a box-size lifecycle
  feature, not a seeding one. Open its own issue if a warehouse asks.
- **FedEx and UPS dimensions**, as the issue says. FedEx's four remain from `03`.
