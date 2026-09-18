<?php

namespace App\Console\Commands;

use App\Enums\PostageSource;
use App\Models\AuditLog;
use App\Models\RateQuote;
use App\Models\ShippingOffer;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PurgeData extends Command
{
    protected $signature = 'data:purge
        {--days= : Override retention days for audit logs}';

    protected $description = 'Purge old audit logs, rate quotes, shipping offers, and read notifications';

    public function handle(SettingsService $settings): int
    {
        $this->purgeAuditLogs($settings);
        $this->purgeRateQuotes($settings);
        $this->purgeShippingOffers($settings);
        $this->purgeNotifications();

        return self::SUCCESS;
    }

    private function purgeAuditLogs(SettingsService $settings): void
    {
        $days = (int) ($this->option('days')
            ?? $settings->get('audit_log_retention_days', 365));

        if ($days === 0) {
            $this->info('Audit log retention is set to 0 (keep forever). Skipping.');

            return;
        }

        $cutoff = now()->subDays($days);
        $total = 0;

        do {
            $deleted = AuditLog::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Purged {$total} audit log entries older than {$days} days.");
    }

    private function purgeRateQuotes(SettingsService $settings): void
    {
        $days = (int) $settings->get('rate_quote_retention_days', 60);

        if ($days === 0) {
            $this->info('Rate quote retention is set to 0 (keep forever). Skipping.');

            return;
        }

        $cutoff = now()->subDays($days);
        $total = 0;

        do {
            $deleted = RateQuote::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            $this->info("Purged {$total} rate quotes older than {$days} days.");
        }
    }

    /**
     * Offers are ephemeral in a way rate quotes are not: one is spent or
     * abandoned within minutes, so its retention is days rather than months.
     * Deliberately a separate setting for that reason — the audit log of what
     * was quoted and the authority to buy it answer different questions and
     * should not be kept to the same clock.
     *
     * `observed_services` is the third case and is purged by nothing at all: a
     * service identity is durable, and its mapping and first-seen date are
     * worth more the older they get.
     */
    private function purgeShippingOffers(SettingsService $settings): void
    {
        $days = (int) $settings->get('shipping_offer_retention_days', 7);

        if ($days === 0) {
            $this->info('Shipping offer retention is set to 0 (keep forever). Skipping.');

            return;
        }

        $cutoff = now()->subDays($days);

        // An offer consumed without the source either confirming or declining
        // is the only evidence that a label may exist which we never recorded.
        // Deleting one destroys the answer to "was this parcel already paid
        // for?", so age alone never removes it. A declined purchase is not one
        // of these: it resolved, and it goes with the rest.
        $unresolvedQuery = fn () => ShippingOffer::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('consumed_at')
            ->whereNull('purchase_reference')
            ->whereNull('purchase_failed_at');

        // Of those, the ones worth a warning: a channel purchase nobody can
        // ask about from here, or a direct-carrier purchase the carrier was
        // asked about and could not settle. A direct-carrier offer nobody has
        // retried yet is not a real unknown — the next Ship attempt on its
        // package asks USPS or UPS, and usually gets an answer.
        $unresolved = $unresolvedQuery()
            ->where(fn ($query) => $query
                ->where('postage_source', '!=', PostageSource::CarrierAccount)
                ->orWhereNotNull('recovery_unanswered_at'))
            ->count();
        $notYetAsked = $unresolvedQuery()
            ->where('postage_source', PostageSource::CarrierAccount)
            ->whereNull('recovery_unanswered_at')
            ->count();

        $total = 0;

        do {
            $deleted = ShippingOffer::query()
                ->where('created_at', '<', $cutoff)
                ->where(fn ($query) => $query
                    ->whereNull('consumed_at')
                    ->orWhereNotNull('purchase_reference')
                    ->orWhereNotNull('purchase_failed_at'))
                ->limit(1000)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            $this->info("Purged {$total} shipping offers older than {$days} days.");
        }

        if ($unresolved > 0) {
            $this->warn(
                "Kept {$unresolved} consumed shipping offer(s) with no confirmed purchase. "
                .'Each one may correspond to a label bought at the source and never recorded here.'
            );
        }

        if ($notYetAsked > 0) {
            $this->info(
                "Kept {$notYetAsked} direct-carrier offer(s) whose purchase got no reply and has not been retried; "
                .'the next Ship attempt on each package asks the carrier what became of it.'
            );
        }
    }

    private function purgeNotifications(): void
    {
        // Delete read notifications older than 30 days
        $readDeleted = DatabaseNotification::whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        // Delete all notifications older than 90 days regardless of read status
        $allDeleted = DatabaseNotification::where('created_at', '<', now()->subDays(90))
            ->delete();

        $total = $readDeleted + $allDeleted;

        if ($total > 0) {
            $this->info("Purged {$total} old notifications ({$readDeleted} read, {$allDeleted} expired).");
        }
    }
}
