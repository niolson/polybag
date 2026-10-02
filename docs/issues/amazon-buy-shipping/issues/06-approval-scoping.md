# Scope service approval to postage source, client and environment

Status: done

Repo: `polybag`

## Problem

ADR-0003 decision 3. Approval is what lets automation spend money on a service, so its scope
has to be exact.

**Environment is the part that bites.** Amazon's sandbox and production identifiers differ —
and worse than differ: the sandbox run returned only `AMZN_US` / `std-us-swa-mfn`, while
production for the same channel returned OnTrac, UPS and USPS and no Amazon Shipping at all.
An approval earned against sandbox identifiers must never authorize a production purchase.

## What to build

Approval scoped to `(postage source, client, environment)`, checked before any automated
selection.

Normalization is a precondition of approval, not a parallel track — you cannot approve what
has not been named. See `05`.

## Acceptance criteria

- [x] Approval is recorded per postage source, per client, per environment
- [x] A sandbox approval does not authorize production spending, and vice versa
- [ ] An unapproved service cannot be reached by any automated path — **carried to `07`**,
      which owns a criterion per call site. What is delivered here is the door, not the
      behavior: `ServiceApprovalGate` answers no without an approval row and nothing can
      obtain a yes another way, but no automated path calls it yet. Left unchecked rather than
      reworded to match what was built — the criterion names the outcome, and the outcome
      arrives with `07`
- [x] Approving requires the service to have been normalized first
- [x] Revoking approval takes effect without needing a re-quote

## Blocked by

- `05-service-aliasing-and-mapping-page`

## What shipped

One table, one model, one service, one policy, and an action on the page `05` built.

- **`service_approvals`** + `ServiceApproval` — one row per
  `(source, environment, external_carrier_id, external_service_id, client_id)`, unique on
  exactly that. Absence is denial, so an install that never approves anything behaves the way
  it did before discovery existed.
- **`ServiceApprovalGate`** — `approved()` answers the unattended question,
  `approvedServiceKeys()` answers it for a whole rate list in one query, and
  `grant()` / `revoke()` / `syncClients()` are the writes. Audited: `ServiceApproval` joined
  `AuditableObserver::observe()`.
- **Approve** on *Map Carrier Services*, next to the mapping it depends on, with an
  **Approved for** column and an **Approval** filter beside the mapping one.

Decisions the issue left open:

**The subject of an approval is the source's identity, not our name for it.** The alternative
was to hang approval off `carrier_service_id` — "automation may buy Ground Advantage through
Amazon for this client" — which reads better to an operator and is wrong. Two observed
identities can be aliased onto one `CarrierService`, and under that model approving either one
vouches for both: the same collapse the environment axis exists to prevent, one level down.
What a purchase spends money on is Amazon's service id, so that is what gets approved.

Normalization stays a precondition — `grant()` throws `UnnormalizedServiceApprovalException`
on an unmapped identity and the button is not rendered for one — it is just the gate, not the
subject.

**Environment is in the scope; marketplace is not.** That is ADR-0003's three axes taken
literally, and the marketplace half is a deliberate omission rather than an oversight. A new
marketplace reporting a service creates a new `observed_services` row, and an approval keyed
per row would leave automation switching itself off through nobody's decision — the same
defect `05` caught in the recorder, in its fail-safe direction. Approval is about which
service, for whose parcels, in which world; where the catalog happened to be read is not one of
those.

**No wildcard client.** A nullable `client_id` meaning "everyone" is the one grant that spends
on a client who never agreed, and `clients.blind_purchase_enabled` had already settled that
consent of this kind is per client. A 3PL approving one service for forty clients ticks forty
boxes in one form, which is the cost of the property being worth having.

**Unmapping revokes; re-mapping does not.** `ObservedServiceMapper::unmap()` now returns
`{observations, approvals}` and withdraws every approval for the service first. Withdrawing the
name has to withdraw the permission, or "approved requires normalized" becomes a property two
queries have to agree about rather than an invariant one place maintains. Re-aliasing onto a
different `CarrierService` deliberately keeps the approval: it changes what we call the
service, not what a purchase buys.

The revoke is environment-blind while the gate is not, which looks inconsistent and is the only
correct pair. A mapping covers every world the service was seen in
(`ObservedService::scopeSameService()`), so unmapping a production row also unmaps the sandbox
one — a revocation narrower than the unmapping that triggered it would leave a sandbox approval
on a service that is now named nothing.

**`grant()` takes `ObservedService::MAPPING_LOCK`; `revoke()` does not.** The grant reads
`carrier_service_id` and then writes a row on the strength of it, straddling a column the
mapping page is editing — the third writer in the pair `05` documented. Revocation only ever
moves toward the safe answer, so racing it produces nothing worth protecting against. `unmap()`
calls `revokeAll()` from inside the lock it already holds, which is why that method does not
take it itself.

**Nothing is cached, and that is what makes revocation immediate.**
`CacheService::getActiveCarrierServices()` holds authored configuration for an hour, which is
right for authored configuration and would mean a withdrawn approval kept spending for up to an
hour. The gate is one indexed lookup asked at selection time. Note for `07`:
`Ship::loadRateOptions()` caches `PackageShippingOptions` for `RATE_CACHE_SECONDS`, so the
exclusion belongs at selection, not in the cached rate list — filtering into that cache would
put the approval behind a TTL through the back door.

**Approving is admin-gated, one step above mapping.** The page opens at `Manager` and *Assign*
is a manager's to use; *Approve* is hidden unless `can('create', ServiceApproval::class)`
passes, matching `ClientResource`, where the other consent-to-spend flag is edited. Naming a
service is a manager's job. Deciding money may be spent on it with nobody watching is not.

**`approved_by_user_id` and `approved_at` sit on the row despite the audit log.**
`PurgeData` clears audit entries on `audit_log_retention_days` — a year by default — and an
approval outlives that easily. "Who authorized this" should not become unanswerable because a
retention setting elapsed.

**The approval counts are correlated subqueries, not relations.** Approvals are keyed on the
service identity and observations on the sighting, so there is no foreign key between the two
tables to count through. Two subqueries in the table query rather than a lookup per row,
because two different questions are asked — see the review notes below.

**The client list is unfiltered, inactive clients included.** A checkbox list submits what it
shows, so filtering to active clients would let anyone saving the form for an unrelated reason
silently withdraw an inactive client's approval. Inactive ones are labeled rather than hidden.

### From review

Three findings, all three real, all three fixed here.

**Withdrawals were invisible to the audit log.** `->delete()` on a query builder never loads a
model, so `AuditableObserver` heard every grant of permission to spend money and no withdrawal
of one — the half that actually gets asked about afterwards. `ServiceApprovalGate::withdraw()`
now hydrates and deletes row by row, which is what all three revoke paths go through. The row
count is bounded by clients × environments for one service, so the audit trail costs a delete
per approval and nothing else. Three tests cover it: revoking directly, unticking a client, and
unmapping.

**A manager could withdraw admin-controlled approvals by unmapping.** *Unmap* opens at
`Manager` along with the rest of the page, and it now cascades into deleting `ServiceApproval`
rows that `ServiceApprovalPolicy` says are an admin's. The button is now hidden from anyone who
fails `can('deleteAny', ServiceApproval::class)` *when the service has approvals*; a manager
keeps it for a service nobody has approved, which is the ordinary case and their job. The
policy gained `deleteAny()` — Filament's convention for a model-less delete check, and the
right home for the rule so the page is not restating it.

Gating that needed a second count. The **Approved for** column is per environment, because an
approval only ever authorizes spending in its own world; what unmapping withdraws spans every
world, because the mapping does. So the query selects both `environment_approvals_count` and
`service_approvals_count`, and the modal says which of the two it is about to act on. A test
covers the case that separates them: an approval in sandbox, an unmap pressed on production.

**Unmapping was two commits, not one.** The revoke committed before the mapping update with no
transaction around the pair, so a failing update would have left the service mapped and
silently de-approved — the safe direction, but a state nobody chose and nothing would report.
Both writes are now inside one `DB::transaction`, the way `promote()` already was.

### From review, second round

Five more, all five real.

**The client foreign key cascaded, which is a withdrawal nothing records.** A database-level
cascade deletes rows without loading a model, so deleting a client would have withdrawn its
permissions to spend money with no audit entry — the same defect as the first round's mass
delete, arriving through the schema instead of through a query. `client_id` is now a plain
`constrained()`, which is what every other client-scoped table here does
(`shipments`, `products`, `shipping_method_aliases`); a client holding approvals cannot be
deleted until they are withdrawn through the audited path. That is not a new obstacle in
practice — a client with a single shipment or product is already undeletable for the same
reason.

**A deleted administrator erased the attribution the row exists to keep.**
`approved_by_user_id` nulls on delete, so once audit retention had elapsed an approval would
have stayed live with its author unknowable — precisely what the column was added to prevent,
and users are not soft-deleted here. The row now also carries `approved_by_name`, snapshotted
at approval time. The foreign key stays for exactness while the account exists; the snapshot is
what survives it.

**Granting still accepted no author.** `grant()` and `syncClients()` took `?User $approver = null`,
so the API permitted the exact row the two attribution columns had just been added to prevent:
a live permission to spend money unattended with no answer to "on whose authority". The
approver is now a required `User` — a type-level guarantee rather than a runtime check, and one
that pushes the question onto any future console or seeder caller instead of letting a default
answer it. `approved_by_name` went NOT NULL with it, which is the same invariant stated where
it cannot be argued with; the foreign key beside it stays nullable, because that is the half
that legitimately disappears when an account does.

**The factory built a row the gate cannot.** `approved_by_user_id` created one user and
`approved_by_name` invented an unrelated one, so a fixture claimed a different author than its
own foreign key pointed at — and any future test reading provenance off a factory-built
approval would have proved nothing. The snapshot is now derived from the user the factory
creates, with an `approvedBy()` state for naming a person and a `formerApprover()` state for
the one case where the two columns legitimately part company: the account deleted, the
attribution left behind. A test exercises all three.

**The tracker claimed an acceptance criterion this issue did not meet.** The third criterion
says an unapproved service cannot be reached by any automated path, and the gate has no
automated callers — this document assigns that wiring to `07` in the same breath. It is
unchecked again, with the split spelled out. `docs/issues/` is the tracker of record, so a box
ticked here is a claim about the app and not about the door being ready.

Two things worth carrying to `07` and `03`:

- The gate's question is `(source, environment, external carrier id, external service id,
  client id)`. A `RateResponse` today carries none of the first four — the identity has to
  reach `07` from the offer or from rate metadata, and `03` is what decides which. Client comes
  from the package's shipment, which is never null.
- `SourceEnvironment` is a required argument with no default. `current()` is nearly always what
  a caller should pass, and a parameter that fills itself in is precisely how a sandbox
  approval would come to authorize a production purchase. A caller holding a `ShippingOffer`
  should pass the environment stamped on it instead.
