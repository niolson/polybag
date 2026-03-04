<?php

namespace App\Providers;

use App\Services\AddressValidationService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\Validation\FakeAddressValidator;
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
            $validators = config('app.fake_carriers')
                ? [new FakeAddressValidator]
                : [new UspsAddressValidator];

            return new AddressValidationService($validators);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.fake_carriers')) {
            $registry = app(CarrierRegistry::class);

            foreach (['USPS', 'FedEx', 'UPS'] as $carrier) {
                $registry->registerInstance($carrier, new FakeCarrierAdapter($carrier));
            }
        }
    }
}
