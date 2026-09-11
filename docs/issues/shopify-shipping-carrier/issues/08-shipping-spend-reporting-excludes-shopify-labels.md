# Shipping spend reports silently understate Shopify Shipping postage

Status: done — 2026-09-05

Repo: `polybag`

## Problem

`packages.cost` is null for every Shopify Shipping label, and `AggregateShippingStats` sums
it with `COALESCE(SUM(p.cost), 0)`. So each Shopify package contributes **$0.00** to
`total_cost` in `daily_shipping_stats` — not flagged, not estimated, not absent. It reads as
a complete total and is not one.

**Null is still the right storage.** A fabricated `0.00` on the package would read as a
*free label* everywhere cost appears and be indistinguishable from a genuine zero. The
defect is in the reporting layer.

## Decision

The two options — disclose the gap on the total, and drop unpriced packages out of the
average — are not alternatives. They fix different numbers, and both need the same missing
datum. `daily_shipping_stats` is a pre-aggregated rollup, so *how many of these packages
had a null cost* is unrecoverable from it once a day is aggregated. **Add
`costed_package_count` to the rollup**, then do both.

Two alternatives rejected: **keying off `carrier = 'Shopify'`** hardcodes one seller into
the reporting layer and misses manual ships and failed cost writes, which produce the same
hole; **waiting for `05`** does not remove the need, since it is gated behind Shopify
Payments scopes, matches heuristically, and covers only Shopify Payments shops.

## What shipped

- `daily_shipping_stats.costed_package_count`, **nullable** — null means "never computed
  for this row" and readers fall back to `package_count`. `default(0)` was avoided for the
  same reason `0.00` was: it would assert every package in the row was unpriced.
- A backfill migration, since the nightly schedule only rebuilds yesterday and today.
- `VolumeReport` divides by priced packages, reports an unknown average rather than zero
  when nothing in a group was priced, and prints *"excludes N with no reported cost"*.
- `CostPerPackageTrend` uses the same divisor and **gaps** the series on a day where
  nothing reported a cost instead of dipping to zero.
- `StatsOverview` appends the excluded count to the weekly cost stat.

- [x] The rollup records how many of its packages reported a cost
- [x] Existing rows backfilled rather than left reading as fully priced
- [x] Cost-per-package divides by priced packages only, in report and widget
- [x] An all-unpriced group reports an unknown average, not `$0.00`
- [x] Every surface showing a cost total says how many packages it left out
- [x] A rollup row from before the column reads exactly as it did

## Comments

- **2026-09-05, review** — two fixes. The **backfill matched grouping keys with COALESCE
  sentinels**, collapsing `service = ''` into `service IS NULL`; both are real states on a
  shipped package and GROUP BY keeps them apart, so each of two rollup rows was handed the
  other's packages (a 3/1 split gave the single-package row a costed count of 3). Every
  nullable key now matches by equality OR both sides being null. And **`StatsOverview`
  disclosed this week's unpriced packages while comparing against last week's total**,
  which has the same hole — an understated last week inflates the percentage and looks no
  different from a measured one, while carrying a colour and a trend arrow. The change is
  withheld whenever either week is incomplete.
- Widget cache payloads changed shape, so keys are versioned (`…:v2`) and
  `InvalidateDashboardCache` follows — without that, an entry written by the previous
  deploy is read back into code expecting the new shape.
- `stats:aggregate` had no test coverage before this, for a reason worth recording: SQLite
  has no date type, so a date-cast column round-trips as `"Y-m-d H:i:s"` and the command's
  `BETWEEN` on plain Y-m-d bounds matches nothing under the test database. The new tests
  normalise `packages.ship_date` to what MySQL would hold.
- **Spun out `12`** — `ClientBillingReport` has the identical `COALESCE(SUM(p.cost), 0)` on
  its own query path, where the number is *invoiced* rather than displayed.
