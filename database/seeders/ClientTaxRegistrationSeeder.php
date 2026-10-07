<?php

namespace Database\Seeders;

use App\Enums\DutiesTerms;
use App\Enums\TaxRegistrationRegime;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use Illuminate\Database\Seeder;

/**
 * An opt-in demo client that ships DDP into the EU under a synthetic IOSS
 * registration, for trying customs terms by hand:
 * `php artisan db:seed --class=ClientTaxRegistrationSeeder`.
 *
 * Deliberately not called by {@see DatabaseSeeder}. A registration is declared
 * on every label its regime covers, and a development instance can buy real
 * labels, so no client gets one it was not given on purpose.
 */
class ClientTaxRegistrationSeeder extends Seeder
{
    public const CLIENT_NAME = 'EU Demo Client';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $client = Client::firstOrCreate(
            ['name' => self::CLIENT_NAME],
            [
                'active' => true,
                'duties_policy' => [Client::DUTIES_POLICY_EU => DutiesTerms::Ddp->value],
            ],
        );

        ClientTaxRegistration::firstOrCreate(
            ['client_id' => $client->id, 'regime' => TaxRegistrationRegime::Ioss],
            ['number' => 'IM0000000001'],
        );
    }
}
