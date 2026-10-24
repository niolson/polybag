<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * An import the scheduler started. Unique until it starts, so a worker that
 * falls behind holds one waiting import per connection rather than one per
 * scheduler tick.
 */
class ScheduledDataSourceImportJob extends RunDataSourceImportJob implements ShouldBeUniqueUntilProcessing
{
    public function __construct(int $dataSourceId)
    {
        parent::__construct($dataSourceId, null);
    }

    public function uniqueId(): string
    {
        return (string) $this->dataSourceId;
    }
}
