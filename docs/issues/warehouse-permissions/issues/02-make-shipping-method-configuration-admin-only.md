# Make a shipping method's configuration Admin-only

Status: ready-for-agent
Category: enhancement
Created: 2026-11-11

## Problem

A shipping method decides what automation may buy and how much it may spend. The
maintainer's direction (2026-11-11) is that changing a method's configuration should
generally be Admin-only.

Today part of it is and part of it is not. As a Manager, checked against the running
policies on 2026-11-11:

| Part of the method | Manager today | Enforced by |
|---|---|---|
| The method form: name, delivery commitment, active, expedited, due-by exclusion; creating and deleting methods | Edits | `ShippingMethodPolicy`, Manager for everything |
| Aliases tab | Adds, edits, deletes | Nothing: `ShippingMethodAlias` has no policy |
| Shipping Rules tab | Adds, edits, deletes | Nothing: `ShippingRule` has no policy |
| Postage Sources tab, including OTDR protection | Sees, cannot change | `ShippingMethodPostageSourcePolicy` |
| Carrier Services and Special Services tabs | Cannot see | `CarrierServicePolicy`, `SpecialServicePolicy`, Admin-only `viewAny` |

ADR-0006 decision 5 says a Manager edits the method's services, due-by setting and rules.
That never matched the services tabs, and the direction above reverses the rest. The ADR
was amended on 2026-11-11 to point here (`amazon-buy-shipping/17` moved OTDR onto the
Admin-only Amazon row).

## What to build

- `ShippingMethodPolicy`: create, update, delete and deleteAny need Admin. A Manager keeps
  `viewAny` and `view`, so the Shipping Methods list stays visible for reference.
- Aliases and Shipping Rules: an Admin-only policy for `ShippingMethodAlias` and
  `ShippingRule` (Manager may view), so the tabs' create, edit and delete actions are
  hidden and refused for a Manager, not only hidden.
- A Manager who opens a method should see it read-only. The resource has no View page,
  and the Edit page is gated on `update`, so either add a View page or decide that a
  Manager sees the list only. Pick the smaller one that keeps the tabs readable.
- Amend ADR-0006 decision 5's Manager sentence to say a method's configuration is
  Admin-only, and close this note in its 2026-11-11 amendment.

## Acceptance criteria

- [ ] A Manager cannot create, edit or delete a shipping method, its aliases or its rules:
      actions hidden, and calling them directly through Livewire is refused
- [ ] An Admin can do all of it, as today
- [ ] A Manager can still see the Shipping Methods list
- [ ] `tests/Feature/AuthorizationTest.php` covers both roles for each tab
- [ ] ADR-0006 decision 5 matches

## Not in scope

Anything outside the shipping method resource. The wider role matrix is `01`.

## Comments
