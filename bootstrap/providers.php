<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\AppPanelProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    AppPanelProvider::class,
];
