# The PII purge has always failed on a NOT NULL city, so retention has never applied

Status: done — 2026-09-10

Repo: `polybag`

## Problem

`PurgePiiCommand` nulls seventeen recipient fields on an eligible shipment in one
`update()`. Sixteen of them are nullable. `shipments.city` is not:

```
database/migrations/2025_01_01_000024_create_shipments_table.php:23
    $table->string('city');
```

So the statement raises an integrity violation on the first eligible shipment, the command
throws, and **nothing downstream of that line ever runs** — including the packages update
that nulls `label_data`, the field that actually carries an image of the recipient's address.

It is scheduled daily in `bootstrap/app.php` at 01:30. Nothing reads its exit status, so it
has been failing quietly for as long as retention has existed. The purge has never purged
anything, in any deployment, and `pii_retention_days` — configurable globally in App
Settings and per channel on the Channel resource — has described a policy that does not run.

Found while implementing
[`shopify-shipping-carrier/07`](../../shopify-shipping-carrier/issues/07-customs-form-printing.md),
whose constraint 2 requires the purge to null a stored customs form "alongside `label_data`".
That constraint could not have held, and writing a test for it is what surfaced this.

## Fix

`city` is nullable at the database level. Nothing else changes: an import still has to supply
a city, and address validation and the carrier requests still require one. The column now
describes a row whose recipient has deliberately been forgotten, which is the one case that
needs it.

`tests/Feature/PurgePiiTest.php` covers the purge completing for an eligible shipment and
leaving one inside its retention window alone. That first assertion is the regression test
for this issue — before the fix it throws.

**Rolling the migration back is lossy, and has to be.** Once the repaired purge has run, the
rows it forgot carry `city = NULL`, which a restored NOT NULL constraint has nowhere to put —
so `down()` backfills those rows with the empty string before re-adding the constraint, or the
rollback fails on them instead of reversing. The empty string is the least-claiming value
available: not PII, and not mistakable for a place name. Rolling forward again cannot tell a
purged city from a backfilled one, which is the honest cost of reversing a migration whose
point is to let data be destroyed.

## Operational tail

**The first run after this deploys will purge a backlog**, not a day's worth: every shipment
that has been eligible since retention was configured becomes eligible at once. That is the
intended behaviour and the whole point of the policy, but it is irreversible, and the rows it
touches include `label_data`, so it is worth knowing before it happens rather than after.

Two things worth doing per deployment, neither of which this change does:

- Run `shipments:purge-pii --dry-run` first to see the count.
- Check that the backlog being purged matches expectations for that tenant's retention
  setting. A deployment whose `pii_retention_days` was set years ago and never enforced will
  purge a great deal more than one configured last month.

## What this does not cover

Nothing alerts on a scheduled command that throws. This failed daily for the lifetime of the
feature and the only thing that found it was a test written for an unrelated issue. Worth a
separate issue — the interesting half is which failures should page and which should not,
which is a decision, not a fix.
