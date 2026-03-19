<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PurgeAuditLogs extends Command
{
    protected $signature = 'audit:purge
        {--days= : Override retention days (default: from settings or 90)}';

    protected $description = 'Purge old audit log entries and read notifications';

    public function handle(SettingsService $settings): int
    {
        $this->purgeAuditLogs($settings);
        $this->purgeNotifications();

        return self::SUCCESS;
    }

    private function purgeAuditLogs(SettingsService $settings): void
    {
        $days = (int) ($this->option('days')
            ?? $settings->get('audit_log_retention_days', 90));

        if ($days === 0) {
            $this->info('Audit log retention is set to 0 (keep forever). Skipping purge.');

            return;
        }

        $cutoff = now()->subDays($days);
        $total = 0;

        $this->info("Purging audit logs older than {$days} days (before {$cutoff->toDateString()})...");

        do {
            $deleted = AuditLog::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Purged {$total} audit log entries.");
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
