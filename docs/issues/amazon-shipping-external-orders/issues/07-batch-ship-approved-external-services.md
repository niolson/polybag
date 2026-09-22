# Allow approved off-Amazon Amazon Shipping services in batch ship

Status: needs-triage

Repo: `polybag`

## What to build

Batch ship and shipping rules may buy off-Amazon Amazon Shipping Labels only for services
that are normalized to a `CarrierService` and explicitly approved through `ServiceApproval`
for the Client and environment — the same gate ADR-0003 applies to on-Amazon services.
Unmapped or unapproved observed services stay available for attended purchase on the Ship
page and are excluded from every unattended path.

Decide and record whether an approval granted for a service on the on-Amazon path also
covers the same service on the off-Amazon path, or whether approvals are distinguished by
channel. The price and terms may differ between the two, which argues for distinguishing
them.

## Acceptance criteria

- [ ] Batch ship selects an approved off-Amazon Amazon Shipping service for a non-Amazon
      Package with a scoped connection
- [ ] Unapproved or unmapped off-Amazon services are excluded from batch ship and shipping
      rules (test)
- [ ] The approval-scope decision is recorded here and in ADR-0003 if it changes its rules

## Blocked by

- `06` — purchase and dispatch
