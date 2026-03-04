<?php

namespace App\Providers;

use App\Services\AddressValidationService;
use App\Services\Validation\UspsAddressValidator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\SettingsService::class);
        $this->app->singleton(\App\Services\CacheService::class);
        $this->app->singleton(\App\Services\RateQuoteLogger::class);
        $this->app->singleton(\App\Services\RuleEvaluator::class);
        $this->app->singleton(\App\Services\Carriers\CarrierRegistry::class);
        $this->app->singleton(\App\Services\ShippingRateService::class);
        $this->app->singleton(\App\Services\LabelGenerationService::class);
        $this->app->singleton(\App\Services\ManifestService::class);

        $this->app->singleton(AddressValidationService::class, function () {
            return new AddressValidationService([
                new UspsAddressValidator,
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
