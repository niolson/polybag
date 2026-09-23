# Approve Amazon services all at once, by carrier, or one at a time, with exceptions

Status: ready-for-agent

Repo: `polybag`

## Problem

Automation buys a discovered Amazon service only once it is approved for the client and
environment (ADR-0003 decisions 3 and 4, `06`, `07`). Two things make that too costly for
what most sellers want, which is the cheapest acceptable offer bought and printed:

- **Approval is per service.** A seller who trusts whatever Amazon offers has to approve
  each service as it is first seen. A service Amazon starts offering tomorrow stays out of
  automation until someone notices it.
- **Approval requires mapping first.** ADR-0003 decision 2 makes normalization a
  precondition of approval, and `ServiceApprovalGate::grant()` throws
  `UnnormalizedServiceApprovalException` for an unmapped service. OnTrac and DHL have no
  `Carrier` row, so approving them means authoring catalog entries first, only to get past
  the gate.

## Decision

**Approval can cover every service, one carrier's services, or one service. Exceptions
can then be carved out. Mapping is no longer required.**

| Approval | Carrier | Service |
|---|---|---|
| Everything Amazon offers, including services first seen later | `*` | `*` |
| Every service of one carrier | `ONTRAC` | `*` |
| One service (today) | `UPS` | `UPS_PTP_GND` |

An **exception** is the same shape with the opposite effect: it denies. A service is
approved when at least one approval matches it and no exception does. An exception always
wins, so "everything except OnTrac" is `*`/`*` plus an exception for `ONTRAC`/`*`.

What does not change:

- Approvals stay scoped to source, **client** and **environment**. There is no client
  wildcard (see the migration's comment on `client_id`), and a sandbox approval still
  authorizes nothing in production.
- Approving nothing still means automation buys nothing discovered. Unapproved offers stay
  on the Ship page for a person (decision 4).
- Every approval and exception records who made it and when, as today.

Rejected: **most-specific-wins**, which would allow "no OnTrac, except OnTrac Ground".
It makes a row's effect depend on which other rows exist, and nobody has asked for that
case. Exceptions always winning is one rule a seller can hold in their head.

## What to build

- **Schema.** `service_approvals` gains an `effect` column (`allow` / `deny`), and `*` is
  accepted in `external_carrier_id` and `external_service_id`. Use the `*` sentinel, not
  NULL: MySQL treats NULLs as distinct in a unique index, so `service_approvals_scope_unique`
  would stop preventing duplicate wildcard rows. Add `effect` to that index. A service
  wildcard under a carrier wildcard (`*`/`UPS_PTP_GND`) is refused, because it means
  nothing. Existing rows become `allow`.
- **Gate.** `ServiceApprovalGate::approved()` and `approvedServiceKeys()` evaluate
  wildcards and exceptions. `RateSelector` calls `approvedServiceKeys()` on the Ship
  page's hot path, so replace it with one query that returns the client's rows for the
  source and environment, then a matcher, rather than a per-rate query.
- **Mapping no longer gates approval.** Drop the normalization check from `grant()`, and
  with it `UnnormalizedServiceApprovalException` and its handling in
  `UnmappedObservedServices`. Unmapping a service no longer withdraws its approvals: what
  a service is called is not the same as whether automation may buy it. Mapping stays
  useful for naming, reports and shipping rules.
- **UI.** Per client and environment, a choice between *All services* and *Selected
  services*:
  - *All services* writes the `*`/`*` approval and shows an *Exceptions* list, with a
    carrier (all of its services) or a single service picked from what has been observed.
  - *Selected services* shows the observed services grouped by carrier, each carrier
    with an *all services of this carrier* toggle, as well as per-service checkboxes.
  - Exceptions are only offered where a wildcard would otherwise cover the service. An
    exception with nothing to except is not written.
- **ADR-0003.** Amend decision 2: normalization is no longer a precondition of approval.
  Amend decision 3 to add carrier and whole-source approvals, and exceptions. Record the
  rejected most-specific-wins rule.

## Related

- `11`: OnTrac labels bought as PDF came back blank. Purchases now ask for PNG or ZPL
  first, so this is probably fixed, but a seller who approves *All services* would buy
  OnTrac unattended. One production OnTrac PNG purchase and void should settle `11`
  first.
- `amazon-shipping-external-orders/07` asks whether an on-Amazon approval also covers
  the same service off Amazon. That question stands, and this issue does not answer it.

## Acceptance criteria

- [ ] With `*`/`*` approved, a service never seen before is bought by automation
- [ ] With `*`/`*` approved and an exception for `ONTRAC`/`*`, no OnTrac service is bought,
      and a UPS one is
- [ ] With `ONTRAC`/`*` approved, every OnTrac service is bought and a UPS one is not
- [ ] An unmapped service can be approved, and unmapping an approved service leaves it
      approved
- [ ] A sandbox `*`/`*` approval does not approve anything in production
- [ ] Existing per-service approvals behave exactly as before
- [ ] Rate selection runs one approvals query per quote, not one per rate

## Blocked by

None.
