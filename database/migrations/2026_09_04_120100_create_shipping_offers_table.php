<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The authoritative purchase context behind a selectable rate — ADR-0002
     * decision 4.
     *
     * Explicitly not `rate_quotes`. That table is an audit log on a 60-day
     * purge, and repurposing it as purchase authority would make a retention
     * policy into a security boundary. This one is ephemeral in the other
     * direction: an offer is spent or abandoned within minutes.
     *
     * The browser holds `public_id` and nothing else. `purchase_context` —
     * Amazon's `requestToken` and `rateId`, neither reconstructible from a
     * carrier and a service name — stays server-side, because a rate that
     * round-trips through Livewire is a description, not an entitlement.
     */
    public function up(): void
    {
        Schema::create('shipping_offers', function (Blueprint $table) {
            $table->id();

            // The opaque identifier the browser selects by, and the
            // idempotency key a purchase is made under. One value for both, so
            // a retry cannot be told apart from the original by the source.
            $table->ulid('public_id')->unique();

            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            // The postage source *instance* the offer came from. Rate shopping
            // across sources makes collisions real: USPS Ground Advantage
            // offered directly and through Amazon are two different purchases
            // that a carrier/service pair cannot tell apart.
            $table->string('postage_source', 32);
            $table->foreignId('carrier_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('postage_data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->foreignId('rate_quote_id')->nullable()->constrained('rate_quotes')->nullOnDelete();

            // Descriptive facts, never purchase identity. Price is nullable
            // because not every offer has one: a Shopify blind purchase prices
            // the label at purchase time and reports no service at all.
            $table->string('carrier');
            // Which catalog carrier and service the offer is for, so a Ship-page purchase
            // restores both from the server's copy rather than the browser's. The carrier is
            // the one expected to carry the parcel, and dates the purchase by id, so a
            // rename between quote and purchase changes nothing. Both null on delete, like
            // the offer's other pointers: offers are short-lived and must never be what
            // stops a catalog row being removed.
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_code')->nullable();
            $table->foreignId('carrier_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name')->nullable();
            $table->decimal('price', 8, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // Carrier detail from the quote that the purchase cannot be made
            // without: FedEx reads `serviceType` unguarded, USPS reads
            // `mailClass`, `rateIndicator` and `processingCategory`. It reaches
            // the adapter through the offer for the same reason the price does
            // — it is a fact the source stated, not something the browser gets
            // to restate on the way back.
            $table->json('rate_metadata')->nullable();

            // Encrypted at rest and hidden on the model: this is the one column
            // that can spend money.
            $table->text('purchase_context')->nullable();

            // Stamped at issue rather than read back from settings, so flipping
            // sandbox_mode cannot retroactively relabel an offer's world.
            $table->string('environment', 16);
            $table->string('marketplace', 32)->nullable();

            // Amazon returns no expiry field, only a documented 10-minute
            // window, so this is tracked from request time on our side. Null
            // means the source published no window — not that the offer is
            // eternal.
            $table->timestamp('expires_at')->nullable();
            $table->string('quote_fingerprint', 64)->nullable();
            $table->string('carrier_account_fingerprint', 64)->nullable();

            // Set by the atomic claim in OfferStore::redeem(). Once set, the
            // offer is spent whether or not the purchase went on to succeed.
            $table->timestamp('consumed_at')->nullable();

            // What the source called the purchase, once it confirmed one.
            $table->string('purchase_reference')->nullable();

            // The other way an offer resolves: the source answered and
            // declined. Separate from a null purchase_reference because the
            // difference is the whole point — a source that replied "no" leaves
            // nothing to recover, while a timeout or a dropped connection
            // leaves a purchase that may have happened. Consumed with both of
            // these null is the ambiguous state, and the only one that has to
            // block further spending on the package.
            $table->timestamp('purchase_failed_at')->nullable();
            $table->string('purchase_failure_reason')->nullable();
            $table->timestamp('recovery_unanswered_at')->nullable();

            $table->timestamps();

            // What the carrier was sent for this Offer's purchase, recorded at claim time
            // (ADR-0008 decision 6). A purchase whose reply never arrived is recorded later,
            // and the Shipment may have been edited in between, so the Label's
            // `customs_terms` snapshot is read back from here. Holds no recipient tax ID,
            // only its type.
            $table->json('declared_customs_terms')->nullable();

            $table->index(['package_id', 'consumed_at']);

            // "Does this package have an offer whose outcome we do not know?",
            // asked before every purchase.
            $table->index(['package_id', 'purchase_reference', 'purchase_failed_at'], 'shipping_offers_unresolved_index');

            // Retention sweep in `data:purge`.
            $table->index(['created_at', 'consumed_at'], 'shipping_offers_retention_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_offers');
    }
};
