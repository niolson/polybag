<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Carbon;

/**
 * Says in words the date range a report's `date_range` filter is on, so the
 * range is stated beside the heading rather than read off a date picker.
 */
trait DescribesReportRange
{
    public function getSubheading(): ?string
    {
        return $this->getDateRangeLabel();
    }

    public function getDateRangeLabel(): string
    {
        $state = $this->tableFilters['date_range'] ?? [];
        $from = filled($state['from'] ?? null) ? Carbon::parse($state['from'])->startOfDay() : null;
        $until = filled($state['until'] ?? null) ? Carbon::parse($state['until'])->startOfDay() : null;

        if ($from === null && $until === null) {
            return 'All dates';
        }

        if ($until === null) {
            $days = (int) $from->diffInDays(today());

            return in_array($days, [7, 30, 90], true)
                ? "Last {$days} days"
                : 'Since '.$from->format('M j, Y');
        }

        if ($from === null) {
            return 'Through '.$until->format('M j, Y');
        }

        return $from->format('M j, Y').' – '.$until->format('M j, Y');
    }
}
