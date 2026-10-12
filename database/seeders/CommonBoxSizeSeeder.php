<?php

namespace Database\Seeders;

use App\Enums\BoxSizeType;
use App\Models\BoxSize;
use Illuminate\Database\Seeder;

/**
 * Generic stock sizes most box and mailer suppliers carry: a starting point a
 * warehouse trims and adds to, not any one warehouse's list.
 *
 * Every row is stored length ≥ width ≥ height and named in that order. A code
 * that already exists is left alone, so reseeding never changes a row an
 * operator owns. Loaded by the Setup Wizard's starter toggle; carrier
 * packaging is seeded separately, inactive, by {@see CarrierPackagingSeeder}.
 */
class CommonBoxSizeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $boxes = [
            ['code' => '01', 'label' => '6x4x4', 'type' => BoxSizeType::BOX, 'length' => '6', 'width' => '4', 'height' => '4'],
            ['code' => '02', 'label' => '6x6x6', 'type' => BoxSizeType::BOX, 'length' => '6', 'width' => '6', 'height' => '6'],
            ['code' => '03', 'label' => '8x6x4', 'type' => BoxSizeType::BOX, 'length' => '8', 'width' => '6', 'height' => '4'],
            ['code' => '04', 'label' => '8x8x8', 'type' => BoxSizeType::BOX, 'length' => '8', 'width' => '8', 'height' => '8'],
            ['code' => '05', 'label' => '10x8x6', 'type' => BoxSizeType::BOX, 'length' => '10', 'width' => '8', 'height' => '6'],
            ['code' => '06', 'label' => '10x10x10', 'type' => BoxSizeType::BOX, 'length' => '10', 'width' => '10', 'height' => '10'],
            ['code' => '07', 'label' => '12x9x3 Mailer Box', 'type' => BoxSizeType::BOX, 'length' => '12', 'width' => '9', 'height' => '3'],
            ['code' => '08', 'label' => '12x12x12', 'type' => BoxSizeType::BOX, 'length' => '12', 'width' => '12', 'height' => '12'],
            ['code' => '09', 'label' => '16x12x8', 'type' => BoxSizeType::BOX, 'length' => '16', 'width' => '12', 'height' => '8'],
            ['code' => '10', 'label' => '13x10 Poly Mailer', 'type' => BoxSizeType::POLYBAG, 'length' => '13', 'width' => '10', 'height' => '0.5'],
            ['code' => '11', 'label' => '19x14.5 Poly Mailer', 'type' => BoxSizeType::POLYBAG, 'length' => '19', 'width' => '14.5', 'height' => '0.5'],
            ['code' => '12', 'label' => '#0 Padded Mailer (10x6)', 'type' => BoxSizeType::PADDED_MAILER, 'length' => '10', 'width' => '6', 'height' => '0.5'],
            ['code' => '13', 'label' => '#2 Padded Mailer (12x8.5)', 'type' => BoxSizeType::PADDED_MAILER, 'length' => '12', 'width' => '8.5', 'height' => '0.5'],
        ];

        foreach ($boxes as $box) {
            BoxSize::firstOrCreate(
                ['code' => $box['code']],
                [...$box, 'max_weight' => '35', 'empty_weight' => '0'],
            );
        }
    }
}
