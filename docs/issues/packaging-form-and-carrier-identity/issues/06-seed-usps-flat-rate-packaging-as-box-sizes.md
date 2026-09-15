# Seed USPS flat-rate packaging as Box Sizes?

Status: needs-triage

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

- [ ] One of the three is chosen and the reason recorded here
- [ ] If 1 or 2: the seeder or action, idempotent, with barcode codes that do not collide
      with `BoxSizeSeeder`'s `01`–`21`, and a test
- [ ] If 3: this issue closes `wontfix` with the reason

## Blocked by

- [`03`](03-box-size-carrier-packaging-replaces-fedex-package-type.md) — the column
