<?php

namespace Database\Factories;

use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\VoidReason;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageLabel>
 */
class PackageLabelFactory extends Factory
{
    protected $model = PackageLabel::class;

    /**
     * A voided label by default: an active one belongs to a shipped package
     * and is created by `PackageFactory` from the package's own columns, so a
     * label made directly is almost always history being set up.
     */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'tracking_number' => fake()->regexify('[0-9]{20}'),
            'postage_source' => PostageSource::CarrierAccount,
            'carrier' => fake()->randomElement(['USPS', 'FedEx']),
            'service' => 'Ground',
            'service_evidence' => ServiceEvidence::Confirmed,
            'cost' => fake()->randomFloat(2, 5, 50),
            'label_format' => 'pdf',
            'label_orientation' => 'portrait',
            'ship_date' => today(),
            'purchased_at' => now()->subHour(),
            'purchased_by_user_id' => User::factory(),
            'voided_at' => now(),
            'voided_by_user_id' => User::factory(),
            'void_reason' => VoidReason::Operator,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason' => null,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'voided_at' => now(),
            'void_reason' => VoidReason::Operator,
        ]);
    }
}
