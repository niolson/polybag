<?php

namespace App\Providers;

use App\Http\Integrations\Shopify\ShopifyOAuthProvider;
use App\Http\Integrations\Ups\UpsOAuthProvider;
use App\Http\Integrations\USPS\UspsOAuthProvider;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Location;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\User;
use App\Observers\AuditableObserver;
use App\Observers\SettingObserver;
use App\Services\AddressValidationService;
use App\Services\CacheService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\LabelGenerationService;
use App\Services\ManifestService;
use App\Services\OAuthProviderRegistry;
use App\Services\OAuthService;
use App\Services\RateQuoteLogger;
use App\Services\RuleEvaluator;
use App\Services\SettingsService;
use App\Services\ShipmentImport\RuntimeConfig;
use App\Services\ShippingRateService;
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
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(CacheService::class);
        $this->app->singleton(RateQuoteLogger::class);
        $this->app->singleton(RuleEvaluator::class);
        $this->app->singleton(CarrierRegistry::class);
        $this->app->singleton(ShippingRateService::class);
        $this->app->singleton(LabelGenerationService::class);
        $this->app->singleton(ManifestService::class);
        $this->app->singleton(OAuthProviderRegistry::class);
        $this->app->singleton(OAuthService::class);
        $this->app->singleton(RuntimeConfig::class);

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
        AuditableObserver::observe([
            User::class,
            Carrier::class,
            CarrierService::class,
            Location::class,
            BoxSize::class,
            ShippingMethod::class,
            ShippingRule::class,
            Product::class,
        ]);
        Setting::observe(SettingObserver::class);

        // Register OAuth providers
        app(OAuthProviderRegistry::class)->register(new ShopifyOAuthProvider);
        app(OAuthProviderRegistry::class)->register(new UpsOAuthProvider);
        app(OAuthProviderRegistry::class)->register(new UspsOAuthProvider);

        if (config('app.fake_carriers')) {
            $registry = app(CarrierRegistry::class);

            foreach (['USPS', 'FedEx', 'UPS'] as $carrier) {
                $registry->registerInstance($carrier, new FakeCarrierAdapter($carrier));
            }
        }
    }
}
