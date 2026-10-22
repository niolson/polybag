<?php

namespace App\Console\Commands;

use App\Enums\AppMode;
use App\Models\DataSource;
use App\Services\OAuthService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Console\Command;

/**
 * Internal tooling for our sales demo, not a self-host feature
 * (`live-store-demo/04`). Run before a demo tenant is torn down: every store
 * the prospect connected is switched off and its credentials are cleared, so
 * nothing in the tenant can read the store again while the teardown runs.
 *
 * Neither Shopify nor Amazon lets an app revoke its own access, so the
 * merchant removes the app on their side; the output says where. Rows are
 * left for the teardown to delete. The Amazon Shipping connection
 * `demo:provision` made from our own sandbox app is skipped: every demo uses
 * those credentials.
 */
class DemoDisconnect extends Command
{
    protected $signature = 'demo:disconnect';

    protected $description = '[Internal] Switch off and clear every store connection the prospect made, before the demo tenant is torn down';

    public function handle(OAuthService $oauth): int
    {
        if (AppMode::current() !== AppMode::Demo) {
            $this->error('demo:disconnect only runs when APP_ENV is demo.');

            return self::FAILURE;
        }

        $connections = DataSource::query()
            ->whereIn('source_type', [ShopifySource::class, AmazonSource::class])
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($connections as $connection) {
            if (DemoProvision::isDemoConnection($connection)) {
                $this->components->twoColumnDetail($connection->name, 'skipped, our own sandbox connection');

                continue;
            }

            if ($oauth->isDataSourceConnected($connection)) {
                $oauth->disconnectDataSource($connection->isShopify() ? 'shopify' : 'sp-api', $connection);
            }

            $connection->forceFill([
                'active' => false,
                'import_enabled' => false,
                'secret_settings' => null,
            ])->save();

            $rows[] = [$connection->name, self::removalStepFor($connection)];
        }

        if ($rows === []) {
            $this->components->info('No store connections to disconnect.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Switched off, credentials cleared. The token cannot be revoked from here, so ask the merchant to remove PolyBag:');
        $this->table(['Connection', 'Where the merchant removes it'], $rows);

        return self::SUCCESS;
    }

    private static function removalStepFor(DataSource $connection): string
    {
        return $connection->isShopify()
            ? 'Shopify admin: Settings > Apps > PolyBag > Uninstall'
            : 'Seller Central: Apps and Services > Manage Your Apps > PolyBag > Remove';
    }
}
