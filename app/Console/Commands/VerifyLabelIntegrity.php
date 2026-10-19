<?php

namespace App\Console\Commands;

use App\Enums\PackageStatus;
use App\Models\Package;
use App\Models\PackageLabel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Name every package whose label record disagrees with it. ADR-0004 decision 4.
 *
 * The writers assert the structural rule — `Shipped` ⇔ exactly one unvoided
 * label — inside their own transactions, and the unique index makes "at most
 * one" a database fact. Whether a shipped package's projected columns still
 * match its active label's is checked nowhere else, so this is detection, not
 * prevention. Exits non-zero when anything is found; the scheduler runs the
 * report nightly. Nothing is repaired: each finding is for a person to look at.
 */
class VerifyLabelIntegrity extends Command
{
    protected $signature = 'app:verify-label-integrity';

    protected $description = 'Report every package whose label record disagrees with it';

    public function handle(): int
    {
        $shippedWithoutLabel = $this->shippedWithoutLabel();
        $unshippedWithLabel = $this->unshippedWithActiveLabel();
        $drifted = $this->drifted();

        $this->report('Shipped with no active label', $shippedWithoutLabel);
        $this->report('Not shipped but holding an active label', $unshippedWithLabel);
        $this->report('Shipped with a projection that disagrees with its label', $drifted);

        $remaining = count($shippedWithoutLabel) + count($unshippedWithLabel) + count($drifted);

        if ($remaining === 0) {
            $this->info('Every package agrees with its label record.');

            return self::SUCCESS;
        }

        $this->error("{$remaining} package(s) disagree with their label record.");

        return self::FAILURE;
    }

    /**
     * @return array<int, string> package ID => description
     */
    private function shippedWithoutLabel(): array
    {
        return DB::table('packages')
            ->where('status', PackageStatus::Shipped->value)
            ->whereNotExists(fn (Builder $query) => $query
                ->select(DB::raw(1))
                ->from('package_labels')
                ->whereColumn('package_labels.package_id', 'packages.id')
                ->whereNull('voided_at'))
            ->orderBy('id')
            ->pluck('tracking_number', 'id')
            ->map(fn (?string $trackingNumber): string => 'tracking '.($trackingNumber ?? '(none)'))
            ->all();
    }

    /**
     * @return array<int, string> package ID => description
     */
    private function unshippedWithActiveLabel(): array
    {
        return DB::table('packages')
            ->join('package_labels', 'package_labels.package_id', '=', 'packages.id')
            ->where('packages.status', '!=', PackageStatus::Shipped->value)
            ->whereNull('package_labels.voided_at')
            ->orderBy('packages.id')
            ->pluck('packages.status', 'packages.id')
            ->map(fn (string $status): string => "status {$status}")
            ->all();
    }

    /**
     * Shipped packages whose projected columns differ from their active label's.
     *
     * Compared in PHP rather than in SQL so the same code runs on both engines
     * and so a decimal, a timestamp and a date each compare as what they are
     * rather than as whatever string the driver returns.
     *
     * @return array<int, string> package ID => the columns that differ
     */
    private function drifted(): array
    {
        $drifted = [];

        DB::table('packages')
            ->join('package_labels', 'package_labels.package_id', '=', 'packages.id')
            ->where('packages.status', PackageStatus::Shipped->value)
            ->whereNull('package_labels.voided_at')
            ->select(array_merge(
                ['packages.id as id'],
                array_map(fn (string $column): string => "packages.{$column} as package_{$column}", array_keys(PackageLabel::PROJECTED_COLUMNS)),
                array_map(fn (string $column): string => "package_labels.{$column} as label_{$column}", array_values(PackageLabel::PROJECTED_COLUMNS)),
            ))
            ->orderBy('packages.id')
            ->chunkById(500, function ($rows) use (&$drifted): void {
                foreach ($rows as $row) {
                    $differing = [];

                    foreach (PackageLabel::PROJECTED_COLUMNS as $packageColumn => $labelColumn) {
                        $packageValue = $this->normalize($packageColumn, $row->{"package_{$packageColumn}"});
                        $labelValue = $this->normalize($packageColumn, $row->{"label_{$labelColumn}"});

                        if ($packageValue !== $labelValue) {
                            $differing[] = $packageColumn === $labelColumn ? $packageColumn : "{$packageColumn}/{$labelColumn}";
                        }
                    }

                    if ($differing !== []) {
                        $drifted[$row->id] = 'differs on '.implode(', ', $differing);
                    }
                }
            }, 'packages.id', 'id');

        return $drifted;
    }

    private function normalize(string $column, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($column) {
            'cost' => number_format((float) $value, 2, '.', ''),
            'shipped_at', 'label_printed_at' => CarbonImmutable::parse($value)->format('Y-m-d H:i:s'),
            'ship_date' => CarbonImmutable::parse($value)->format('Y-m-d'),
            default => (string) $value,
        };
    }

    /**
     * @param  array<int, string>  $packages
     */
    private function report(string $heading, array $packages): void
    {
        $this->line(sprintf('%s: %d', $heading, count($packages)));

        foreach ($packages as $id => $description) {
            $this->line("  package {$id} — {$description}");
        }
    }
}
