# Allow approved off-Amazon Amazon Shipping services in batch ship

Status: needs-triage

Repo: `polybag`

## What to build

Batch ship and shipping rules may buy off-Amazon Amazon Shipping Labels only for services
that are explicitly approved through `ServiceApproval` for the Client and environment,
the same gate ADR-0003 applies to on-Amazon services. `amazon-buy-shipping/18` removes
the mapping prerequisite and adds carrier-wide and all-services approvals with exceptions.
Unapproved observed services stay available for attended purchase on the Ship page and
are excluded from every unattended path.

Decide and record whether an approval granted for a service on the on-Amazon path also
covers the same service on the off-Amazon path, or whether approvals are distinguished by
channel. The price and terms may differ between the two, which argues for distinguishing
them.

## Acceptance criteria

- [ ] Batch ship selects an approved off-Amazon Amazon Shipping service for a non-Amazon
      Package with a scoped connection
- [ ] Unapproved off-Amazon services are excluded from batch ship and shipping
      rules (test)
- [ ] The approval-scope decision is recorded here and in ADR-0003 if it changes its rules

## Blocked by

- ~~`06` — purchase and dispatch~~ done
- ~~[`amazon-buy-shipping/15`](../../amazon-buy-shipping/issues/15-filter-amazon-offers-by-shipping-method-service-class.md)~~
  `wontfix`. Its job, stopping an approved service of the wrong speed from winning a
  batch, moves to
  [`amazon-buy-shipping/17`](../../amazon-buy-shipping/issues/17-move-offer-requirements-to-the-shipping-method.md):
  *exclude rates that deliver after the due-by date* on the shipping method, with the
  due-by date taken from `commitment_days` when an off-Amazon order has no `deliver_by`
- [`amazon-buy-shipping/17`](../../amazon-buy-shipping/issues/17-move-offer-requirements-to-the-shipping-method.md)
  — the speed guard above
