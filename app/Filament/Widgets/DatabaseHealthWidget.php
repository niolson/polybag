<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DatabaseHealthWidget extends BaseWidget
{
    protected static ?int $sort = 10;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Admin) ?? false;
    }

    protected function getStats(): array
    {
        $counts = Cache::remember('widget:database_health', 3600, function () {
            return [
                'shipments' => DB::table('shipments')->count(),
                'packages' => DB::table('packages')->count(),
                'rate_quotes' => DB::table('rate_quotes')->count(),
                'audit_logs' => DB::table('audit_logs')->count(),
            ];
        });

        return [
            $this->buildStat('Shipments', $counts['shipments']),
            $this->buildStat('Packages', $counts['packages']),
            $this->buildStat('Rate Quotes', $counts['rate_quotes']),
            $this->buildStat('Audit Logs', $counts['audit_logs']),
        ];
    }

    private function buildStat(string $label, int $count): Stat
    {
        $color = match (true) {
            $count >= 500_000 => 'danger',
            $count >= 100_000 => 'warning',
            default => 'success',
        };

        $description = match (true) {
            $count >= 500_000 => 'Consider archiving',
            $count >= 100_000 => 'Growing',
            default => 'Healthy',
        };

        return Stat::make($label, number_format($count))
            ->description($description)
            ->color($color);
    }

    protected function getHeading(): ?string
    {
        return 'Database Health';
    }
}
