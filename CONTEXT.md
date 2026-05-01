# PolyBag

PolyBag is a shipping workstation for preparing Packages, buying labels, printing labels, and tracking fulfillment work.

## Language

**Shipment**:
An order-like shipping request containing recipient details and items that need fulfillment.

**Package**:
A physical parcel prepared from a Shipment and measured before label purchase.

**Package Draft**:
An unshipped Package that was prepared but has not yet completed label purchase.
_Avoid_: Orphan package, temporary package

## Relationships

- A **Shipment** can produce one or more **Packages**.
- A **Package Draft** belongs to exactly one **Shipment**.
- A **Package Draft** becomes a shipped **Package** when label purchase succeeds.
- For now, a **Shipment** should have at most one active **Package Draft** in the packing workflow.
- When a **Package Draft** exists, the packing workflow resumes from the draft as source of truth.
- `PackageCreated` means the **Package Draft** was first persisted as a Package row, not that it is ready for label purchase.

## Example dialogue

> **Dev:** "If the operator prepares a Package but leaves before buying the label, should we delete it?"
> **Domain expert:** "No — that is a Package Draft. Keep it so the operator can resume, edit, ship, or explicitly delete it."
>
> **Dev:** "If the operator scans the same Shipment again, do we create another draft?"
> **Domain expert:** "Not yet — resume the existing Package Draft for that Shipment."
>
> **Dev:** "When resuming, should the new scan state overwrite the draft?"
> **Domain expert:** "No — load the Package Draft first and continue from that source of truth."
>
> **Dev:** "Should PackageCreated fire when the draft is ready to ship?"
> **Domain expert:** "No — fire it when the Package row is created in the database."

## Flagged ambiguities

- "orphan package" was used for an unshipped Package left by an interrupted workflow — resolved: this is a **Package Draft**, not something to silently delete.
