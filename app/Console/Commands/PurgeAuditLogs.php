<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class PurgeAuditLogs extends Command
{
    protected $signature = 'audit:purge
        {--days= : Override retention days (default: from settings or 90)}';

    protected $description = 'Purge audit log entries older than the configured retention period';

    public function handle(SettingsService $settings): int
    {
        $days = $this->option('days')
            ?? $settings->get('audit_log_retention_days', 90);

        $cutoff = now()->subDays((int) $days);
        $total = 0;

        $this->info("Purging audit logs older than {$days} days (before {$cutoff->toDateString()})...");

        do {
            $deleted = AuditLog::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Purged {$total} audit log entries.");

        return self::SUCCESS;
    }
}
