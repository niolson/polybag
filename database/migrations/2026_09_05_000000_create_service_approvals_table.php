<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permission for automation to spend money on a discovered service —
     * ADR-0003 decision 3.
     *
     * The third of the ADR's three concepts, and the only one that is about
     * money. `observed_services` says what a source reported;
     * `observed_services.carrier_service_id` says what we call it; a row here
     * says an unattended path may buy it. A service with all three is
     * ordinary configuration. A service with the first two is still perfectly
     * usable — a packer picks it off the rate list, having seen the price.
     *
     * Deny by default: the absence of a row is the answer, so nothing has to
     * be revoked to be safe, and a table that has never been written to
     * behaves exactly like the deny-by-default catalog we had before
     * discovery existed.
     *
     * Scope is the ADR's three axes plus the identity being approved:
     *
     * - **postage source** — the kind of source, matching `observed_services.source`
     * - **client** — the party being billed, and the one whose consent this is
     * - **environment** — sandbox and production are different worlds, not
     *   stale copies of one
     *
     * The identity is `(external_carrier_id, external_service_id)` rather than
     * a `carrier_service_id`, because what gets bought is Amazon's service, not
     * our name for it: two observed identities may be aliased onto one
     * `CarrierService`, and approving one of them must not vouch for the other.
     * Normalization was then still a precondition, just not the subject;
     * `amazon-buy-shipping/18` dropped it.
     */
    public function up(): void
    {
        Schema::create('service_approvals', function (Blueprint $table) {
            $table->id();

            // Matches observed_services: the kind of postage source, not one
            // instance of it. Two Amazon data sources quote the same catalog.
            $table->string('source', 32);

            // The axis that bites. Amazon's sandbox returned only Amazon
            // Shipping where production for the same channel returned OnTrac,
            // UPS and USPS and no Amazon Shipping at all — so an approval
            // earned against sandbox identifiers is evidence about nothing that
            // can be bought with real money.
            $table->string('environment', 16);

            // The source's own identifiers, as observed. Deliberately not a FK
            // to observed_services: that table is keyed per environment *and*
            // per marketplace, and an approval that stopped applying because a
            // second marketplace reported the same service would be automation
            // silently switching off through nobody's decision.
            $table->string('external_carrier_id', 64);
            $table->string('external_service_id', 128);

            // Whose parcels, and whose money. No wildcard row: a client-less
            // "approved for everyone" is exactly the grant that spends on a
            // client who never agreed, and `clients.blind_purchase_enabled`
            // already settled that consent of this kind is per client.
            //
            // Restricted rather than cascading, like every other client-scoped
            // table here (`shipments`, `products`, `shipping_method_aliases`).
            // A database-level cascade deletes rows without loading a model, so
            // `AuditableObserver` never sees it: deleting a client would
            // withdraw its approvals to spend money and leave no record that it
            // had. Restricting means the withdrawal has to go through
            // `ServiceApprovalGate`, which is audited, before the client can go.
            $table->foreignId('client_id')->constrained();

            // Who authorized the spending, kept on the row rather than left to
            // the audit log alone: audit entries are purged on
            // `audit_log_retention_days` (365 by default) and an approval
            // outlives that comfortably.
            //
            // Two columns, because neither alone is durable. The foreign key is
            // exact while the account exists and nulls when it is deleted;
            // `approved_by_name` is a snapshot taken at approval time, so an
            // approval authorized by someone who has since left still says who
            // authorized it. Users are not soft-deleted here, and an approval
            // whose author became unknowable is the outcome this column pair
            // exists to prevent.
            //
            // The name is NOT NULL while the key is nullable, which is the
            // asymmetry the pair is for: `ServiceApprovalGate::grant()` requires
            // a user, so every approval is authored, and the database says so
            // even after the account behind it is gone.
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name');
            $table->timestamp('approved_at');

            $table->timestamps();

            $table->unique([
                'source',
                'environment',
                'external_carrier_id',
                'external_service_id',
                'client_id',
            ], 'service_approvals_scope_unique');

            // The gate's question, asked per rate selection: "what has this
            // client approved from this source, in this world?"
            $table->index(['client_id', 'source', 'environment'], 'service_approvals_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_approvals');
    }
};
