<?php

namespace App\Providers;

use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PackageLabelWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\Http\Integrations\Shopify\ShopifyOAuthProvider;
use App\Http\Integrations\Ups\UpsOAuthProvider;
use App\Http\Integrations\USPS\UspsOAuthProvider;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Product;
use App\Models\ServiceApproval;
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
use App\Services\FedexRegistrationService;
use App\Services\ManifestService;
use App\Services\OAuthProviderRegistry;
use App\Services\OAuthService;
use App\Services\PackageDrafts\EloquentPackageDraftWorkflow;
use App\Services\PackageLabels\EloquentPackageLabelWorkflow;
use App\Services\PackageShipping\EloquentPackageShippingWorkflow;
use App\Services\PickBatchService;
use App\Services\RateQuoteLogger;
use App\Services\RuleEvaluator;
use App\Services\SettingsService;
use App\Services\ShippingRateService;
use App\Services\Validation\FakeAddressValidator;
use App\Services\Validation\GoogleAddressValidator;
use App\Services\Validation\UspsAddressValidator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        $this->app->singleton(ManifestService::class);
        $this->app->singleton(PickBatchService::class);
        $this->app->singleton(OAuthProviderRegistry::class);
        $this->app->singleton(OAuthService::class);
        $this->app->singleton(FedexRegistrationService::class);
        $this->app->singleton(PackageDraftWorkflow::class, EloquentPackageDraftWorkflow::class);
        $this->app->singleton(PackageLabelWorkflow::class, EloquentPackageLabelWorkflow::class);
        $this->app->singleton(PackageShippingWorkflow::class, EloquentPackageShippingWorkflow::class);

        $this->app->singleton(AddressValidationService::class, function (): AddressValidationService {
            $settings = $this->app->make(SettingsService::class);

            if (config('app.fake_carriers') || SettingsService::isDemoMode()) {
                return new AddressValidationService([new FakeAddressValidator]);
            }

            if ($settings->get('sandbox_mode', false) && ! $settings->get('address_validation_use_real_in_sandbox', false)) {
                return new AddressValidationService([new FakeAddressValidator]);
            }

            $validators = [new UspsAddressValidator];

            if ($settings->get('address_validation_google_enabled', false)) {
                $validators[] = new GoogleAddressValidator;
            }

            return new AddressValidationService($validators);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('azure', Provider::class);
        });

        AuditableObserver::observe([
            User::class,
            Carrier::class,
            CarrierAccount::class,
            CarrierService::class,
            DataSource::class,
            Location::class,
            BoxSize::class,
            ShippingMethod::class,
            ShippingRule::class,
            Product::class,
            // Every grant and every withdrawal of permission to spend money
            // unattended, with who did it — ADR-0003 decision 3.
            ServiceApproval::class,
        ]);
        Setting::observe(SettingObserver::class);

        // Register OAuth providers
        app(OAuthProviderRegistry::class)->register(new ShopifyOAuthProvider);
        app(OAuthProviderRegistry::class)->register(new UpsOAuthProvider);
        app(OAuthProviderRegistry::class)->register(new UspsOAuthProvider);

        if (config('app.fake_carriers')) {
            $registry = app(CarrierRegistry::class);

            foreach ([Carrier::USPS, Carrier::FEDEX, Carrier::UPS] as $carrier) {
                $registry->registerInstance($carrier, new FakeCarrierAdapter($carrier));
            }
        }
    }
}
