<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\RateQuote;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PurgeData extends Command
{
    protected $signature = 'data:purge
        {--days= : Override retention days for audit logs}';

    protected $description = 'Purge old audit logs, rate quotes, and read notifications';

    public function handle(SettingsService $settings): int
    {
        $this->purgeAuditLogs($settings);
        $this->purgeRateQuotes($settings);
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
