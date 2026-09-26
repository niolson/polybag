<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Service code => [seeded name, name by weight band].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NAMES = [
        '92' => ['UPS Ground Saver', 'UPS Ground Saver (under 1 lb)'],
        '93' => ['UPS Ground Saver', 'UPS Ground Saver (1 lb and over)'],
    ];

    /**
     * UPS Ground Saver's two rows were both seeded as "UPS Ground Saver", so a
     * method form listed two identical entries — `carrier-catalog-reset/11`.
     * Name them by weight band, as `CarrierSeeder` now does for new installs.
     *
     * Only a row still carrying the name it was seeded with is renamed, so one
     * an Admin has already renamed keeps its name.
     */
    public function up(): void
    {
        foreach (self::NAMES as $code => [$seeded, $band]) {
            $this->rename($code, from: $seeded, to: $band);
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $code => [$seeded, $band]) {
            $this->rename($code, from: $band, to: $seeded);
        }
    }

    private function rename(string $code, string $from, string $to): void
    {
        DB::table('carrier_services')
            ->whereIn('carrier_id', DB::table('carriers')->where('name', 'UPS')->select('id'))
            ->where('service_code', $code)
            ->where('name', $from)
            ->update(['name' => $to, 'updated_at' => now()]);
    }
};
