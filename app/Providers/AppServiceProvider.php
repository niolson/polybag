<?php

namespace App\Providers;

use App\Http\Integrations\Shopify\ShopifyOAuthProvider;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Location;
use App\Models\Package;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
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
use App\Services\ShippingRateService;
use App\Services\Validation\FakeAddressValidator;
use App\Services\Validation\UspsAddressValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

        // Register API routes here (before Filament's catch-all at path('/'))
        // so they take priority over the panel's {any} wildcard route.
        Route::prefix('api')->group(function () {
            Route::get('/health', function () {
                $status = ['status' => 'ok'];

                try {
                    DB::connection()->getPdo();
                    $status['db'] = 'ok';
                } catch (\Throwable) {
                    $status['db'] = 'failed';
                    $status['status'] = 'degraded';
                }

                if (config('app.fake_carriers')) {
                    $status['fake_carriers'] = true;
                }

                return response()->json($status, $status['status'] === 'ok' ? 200 : 503);
            });

            if (app()->environment('local') && config('app.fake_carriers')) {
                Route::post('/test/create-package', function () {
                    $shipment = Shipment::whereDoesntHave('packages', fn ($q) => $q->whereNotNull('shipped_at'))
                        ->whereNotNull('postal_code')
                        ->first();

                    abort_unless($shipment, 422, 'No shippable shipment found');

                    $boxSize = BoxSize::first();

                    $package = Package::create([
                        'shipment_id' => $shipment->id,
                        'box_size_id' => $boxSize?->id,
                        'weight' => 1.5,
                        'length' => $boxSize?->length ?? 10,
                        'width' => $boxSize?->width ?? 8,
                        'height' => $boxSize?->height ?? 4,
                    ]);

                    return response()->json(['package_id' => $package->id]);
                });
            }
        });

        if (config('app.fake_carriers')) {
            $registry = app(CarrierRegistry::class);

            foreach (['USPS', 'FedEx', 'UPS'] as $carrier) {
                $registry->registerInstance($carrier, new FakeCarrierAdapter($carrier));
            }
        }
    }
}
