<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Shopify adapter now writes `shopify_honored_selection` into package
     * metadata, and the service inferrer reads only that key. Packages labeled
     * before the rename carry the British spelling; move each value across so
     * the requested-selection rung still sees it.
     */
    public function up(): void
    {
        $this->renameKey('shopify_honoured_selection', 'shopify_honored_selection');
    }

    public function down(): void
    {
        $this->renameKey('shopify_honored_selection', 'shopify_honoured_selection');
    }

    private function renameKey(string $from, string $to): void
    {
        DB::table('packages')
            ->where('metadata', 'like', "%\"{$from}\"%")
            ->select(['id', 'metadata'])
            ->chunkById(500, function ($packages) use ($from, $to): void {
                foreach ($packages as $package) {
                    $metadata = json_decode((string) $package->metadata, true);

                    if (! is_array($metadata) || ! array_key_exists($from, $metadata)) {
                        continue;
                    }

                    $metadata[$to] = $metadata[$from];
                    unset($metadata[$from]);

                    DB::table('packages')
                        ->where('id', $package->id)
                        ->update(['metadata' => json_encode($metadata)]);
                }
            });
    }
};
