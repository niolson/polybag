<?php

namespace App\Providers;

use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\AddressValidationService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
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
