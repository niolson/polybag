<?php

namespace App\Filament\Concerns;

/**
 * A list page with both a status tab group and a `status` table filter would
 * otherwise apply both, so a filter for Shipped under the default Open tab
 * matches nothing. The most recent choice wins: picking a status in the filter
 * moves the tab to All, and picking a status tab clears the filter.
 *
 * @property ?string $activeStatusTab
 */
trait KeepsStatusTabInStepWithStatusFilter
{
    protected function hasStatusFilter(): bool
    {
        return filled($this->tableFilters['status']['value'] ?? null);
    }

    protected function handleTableFilterUpdates(): void
    {
        parent::handleTableFilterUpdates();

        if ($this->hasStatusFilter()) {
            $this->activeStatusTab = 'all';
        }
    }

    protected function clearStatusFilterForStatusTab(): void
    {
        // Cleared on the state rather than through removeTableFilter(), which
        // resets form fields: a page may leave `status` out of its filters form
        // and reach it only from a URL.
        if ($this->activeStatusTab !== 'all' && $this->hasStatusFilter()) {
            $this->tableFilters['status']['value'] = null;
            $this->handleTableFilterUpdates();
        }
    }
}
