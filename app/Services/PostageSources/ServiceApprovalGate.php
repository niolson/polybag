<?php

namespace App\Services\PostageSources;

use App\DataTransferObjects\PostageSources\ApprovalRule;
use App\DataTransferObjects\PostageSources\ServiceApprovalRules;
use App\Enums\AmazonChannelType;
use App\Enums\ApprovalEffect;
use App\Enums\SourceEnvironment;
use App\Models\Client;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\RateSelector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Whether automation may spend money on a discovered service — ADR-0003
 * decisions 3 and 4.
 *
 * The last of the three concepts decision 2 keeps apart, and the only one that
 * is about money rather than about facts or names. {@see ObservedServiceRecorder}
 * writes what a source reported; {@see ObservedServiceMapper} says what we call
 * it; this says whether an unattended path may buy it. Since
 * `amazon-buy-shipping/18` the three are fully independent: a service nobody
 * has named can be approved, and unmapping one leaves its approvals alone.
 *
 * **Deny by default, and deny is the whole safety mechanism.** An unapproved
 * service is not hidden and not broken: a packer sees it on the Ship page with
 * its price and picks it, taking responsibility. What it may not do is win
 * `RateSelector::selectBest()` at 03:00 on an account nobody approved it for.
 * That split — on *who is choosing* — is what makes discovery acceptable at
 * all, so every answer this class gives to an unattended caller starts from no.
 *
 * An approval covers one service, one carrier's services, or everything the
 * source offers, and an exception of the same shape carves services back out.
 * An exception always wins — see {@see ServiceApprovalRules}.
 *
 * Four axes of scope, all load-bearing, and none of them wildcarded:
 *
 * - **postage source**, because a service offered through Amazon and the same
 *   service bought directly are two different purchases;
 * - **client**, because the approval is consent from whoever is billed;
 * - **environment**, because Amazon's sandbox returned only Amazon Shipping
 *   where production for the same channel returned OnTrac, UPS and USPS and no
 *   Amazon Shipping at all. An approval earned in sandbox is evidence about
 *   nothing that costs money;
 * - **channel type**, because Amazon Shipping sold for an order from another
 *   channel has its own prices and none of Buy Shipping's protections, so
 *   consent to buy a service for Amazon orders is not consent to buy it for a
 *   Shopify order (`amazon-shipping-external-orders/07`).
 *
 * Nothing here is cached. `CacheService` holds carrier services for an hour,
 * which is right for authored configuration and would be wrong here: revoking
 * an approval has to stop the next selection, not the next hour's.
 */
class ServiceApprovalGate
{
    /** How long a held approvals lock stays valid if the holder dies mid-write. */
    private const LOCK_SECONDS = 10;

    /** How long to wait for another writer to finish before giving up. */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * May an unattended path buy this service for this client?
     *
     * The environment is a required argument rather than a defaulted one on
     * purpose. `SourceEnvironment::current()` is nearly always the right value
     * to pass, and a parameter that fills itself in is exactly how a sandbox
     * approval would come to authorize a production purchase — the one thing
     * decision 3 exists to prevent. Callers holding a {@see ShippingOffer}
     * should pass the environment stamped on it, which is the world the quote
     * actually came from.
     *
     * A null client is denied rather than treated as "any". Every package
     * reaches a client through its shipment, so a missing one is a caller that
     * has lost track of whose money this is.
     */
    public function approved(
        string $source,
        SourceEnvironment $environment,
        AmazonChannelType $channelType,
        string $externalCarrierId,
        string $externalServiceId,
        ?int $clientId,
    ): bool {
        return $this->rulesFor($source, $environment, $channelType, $clientId)
            ->permits($externalCarrierId, $externalServiceId);
    }

    /**
     * Everything this client has approved and excepted from one source in one
     * world, for one kind of order, in one query.
     *
     * For matching a whole rate list rather than asking once per offer: an
     * Amazon `getRates` can return several eligible offers, and
     * {@see RateSelector} is on the Ship page's hot path.
     */
    public function rulesFor(string $source, SourceEnvironment $environment, AmazonChannelType $channelType, ?int $clientId): ServiceApprovalRules
    {
        if ($clientId === null) {
            return ServiceApprovalRules::none();
        }

        return new ServiceApprovalRules(
            ServiceApproval::query()
                ->inWorld($source, $environment, $channelType)
                ->where('client_id', $clientId)
                ->get()
                ->map(fn (ServiceApproval $approval): ApprovalRule => ApprovalRule::fromApproval($approval))
                ->values()
        );
    }

    /**
     * Approve the one service an observation names, for one client and one
     * kind of order, in the world that observation was made in.
     *
     * Mapped or not: what a service is called is not whether automation may
     * buy it, and requiring a `Carrier` row for OnTrac only to get past this
     * method was catalog authoring for its own sake.
     *
     * The approver is required rather than nullable. An approval is a standing
     * permission to spend somebody's money unattended, and one that cannot say
     * on whose authority it was granted is the row this class exists not to
     * write — a nullable parameter with a convenient default is how that row
     * gets created by accident. Callers that are not a signed-in operator have
     * to name the user they are acting for.
     */
    public function grant(ObservedService $observation, AmazonChannelType $channelType, Client $client, User $approver): ServiceApproval
    {
        return $this->grantRule(
            $observation->source,
            $observation->environment,
            $channelType,
            $client,
            ApprovalRule::service($observation->external_carrier_id, $observation->external_service_id),
            $approver,
        );
    }

    /**
     * Write one approval or exception — a service, a carrier, or everything.
     */
    public function grantRule(
        string $source,
        SourceEnvironment $environment,
        AmazonChannelType $channelType,
        Client $client,
        ApprovalRule $rule,
        User $approver,
    ): ServiceApproval {
        return ServiceApproval::updateOrCreate(
            [
                'source' => $source,
                'environment' => $environment,
                'channel_type' => $channelType,
                'external_carrier_id' => $rule->externalCarrierId,
                'external_service_id' => $rule->externalServiceId,
                'effect' => $rule->effect,
                'client_id' => $client->getKey(),
            ],
            [
                'approved_by_user_id' => $approver->getKey(),
                // Snapshotted, not read back through the relation: the point of
                // recording who authorized a spend is that the answer survives
                // both the audit log's retention and the account being deleted.
                'approved_by_name' => $approver->name,
                'approved_at' => now(),
            ],
        );
    }

    /**
     * Withdraw one client's approval of the one service an observation names,
     * for one kind of order.
     *
     * Only the row naming that service: a carrier or whole-source approval
     * that also covers it is a different decision, withdrawn on its own.
     *
     * @return int approvals withdrawn — 0 when there was nothing to withdraw
     */
    public function revoke(ObservedService $observation, AmazonChannelType $channelType, Client $client): int
    {
        return $this->withdraw(
            ServiceApproval::query()
                ->inWorld($observation->source, $observation->environment, $channelType)
                ->where('client_id', $client->getKey())
                ->where('external_carrier_id', $observation->external_carrier_id)
                ->where('external_service_id', $observation->external_service_id)
                ->where('effect', ApprovalEffect::Allow)
                ->get()
        );
    }

    /**
     * Set one client's approvals and exceptions for one source, world and kind
     * of order to exactly these, granting and withdrawing as needed.
     *
     * What the approvals page submits. One lock and one transaction over both
     * halves, so a half-applied change cannot leave a service approved because
     * the exception that was meant to come with it failed to write.
     *
     * A rule that is already on file is left as it is, author and date
     * included: re-saving the form is not re-approving.
     *
     * @param  iterable<ApprovalRule>  $rules
     * @return array{granted: int, revoked: int}
     */
    public function sync(
        string $source,
        SourceEnvironment $environment,
        AmazonChannelType $channelType,
        Client $client,
        iterable $rules,
        User $approver,
    ): array {
        $lock = "service-approvals:{$client->getKey()}:{$source}:{$environment->value}:{$channelType->value}";

        return Cache::lock($lock, self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            fn (): array => DB::transaction(function () use ($source, $environment, $channelType, $client, $rules, $approver): array {
                $wanted = collect($rules)->keyBy(fn (ApprovalRule $rule): string => $rule->key());

                $existing = ServiceApproval::query()
                    ->inWorld($source, $environment, $channelType)
                    ->where('client_id', $client->getKey())
                    ->get()
                    ->keyBy(fn (ServiceApproval $approval): string => ApprovalRule::fromApproval($approval)->key());

                $revoked = $this->withdraw($existing->diffKeys($wanted)->values());

                $granted = $wanted->diffKeys($existing);

                foreach ($granted as $rule) {
                    $this->grantRule($source, $environment, $channelType, $client, $rule, $approver);
                }

                return ['granted' => $granted->count(), 'revoked' => $revoked];
            }),
        );
    }

    /**
     * Delete approvals one hydrated model at a time.
     *
     * Not `->delete()` on a query. A mass delete never loads a model and so
     * never fires `deleted`, which is what `AuditableObserver` listens for —
     * the audit log would have carried every grant of permission to spend money
     * and no withdrawal of one, which is the half that gets asked about after
     * the fact.
     *
     * @param  iterable<ServiceApproval>  $approvals
     * @return int approvals withdrawn
     */
    private function withdraw(iterable $approvals): int
    {
        $count = 0;

        foreach ($approvals as $approval) {
            $approval->delete();
            $count++;
        }

        return $count;
    }
}
