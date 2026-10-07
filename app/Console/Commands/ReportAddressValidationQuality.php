<?php

namespace App\Console\Commands;

use App\Services\Validation\ValidationQualityReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Prints `ValidationQualityReport` for this instance: counts only, so the
 * output can be collected from every tenant and added up
 * (`address-validation-routing/11`).
 */
class ReportAddressValidationQuality extends Command
{
    protected $signature = 'address-validation:report
                            {--since= : Only Shipments whose first Label was bought on or after this date}
                            {--until= : Only Shipments whose first Label was bought on or before this date}
                            {--json : Print JSON for collecting across instances}';

    protected $description = 'Count validator answers against what later happened to the parcels';

    public function handle(ValidationQualityReport $report): int
    {
        try {
            $since = $this->dateOption('since')?->startOfDay();
            $until = $this->dateOption('until')?->endOfDay();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $result = $report->build($since, $until);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => now()->toIso8601String(),
                'since' => $since?->toDateString(),
                'until' => $until?->toDateString(),
                'final_after_days' => ValidationQualityReport::FINAL_AFTER_DAYS,
                ...$result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("{$result['data_points']} Shipment(s) with a validator answer and a Label.");
        $this->line('Outcomes other than delivered and returned stay pending until the first Label is '.ValidationQualityReport::FINAL_AFTER_DAYS.' days old.');
        $this->line('Rows marked * have fewer than '.ValidationQualityReport::SMALL_SAMPLE.' data points: too few to judge from.');

        $this->newLine();
        $this->line('<comment>Answers against outcomes and later address edits</comment>');
        $this->table(
            ['', 'Validator', 'Kind', 'Country', 'Verdict', 'n', 'Returned', 'Exception', 'Delivered', 'Pending', 'Edited', 'Street', 'Unit', 'Locality', 'Postcode'],
            array_map(fn (array $row): array => [$this->smallMark($row['n']), ...array_values($row)], $result['answers']),
        );

        $this->newLine();
        $this->line('<comment>FedEx shadow answers against the live answer</comment>');
        $this->table(
            ['', 'Country', 'Live validator', 'Live verdict', 'FedEx verdict', 'n', 'Returned', 'Exception', 'Delivered', 'Pending', 'Street differs', 'House no. differs', 'City differs', 'Postcode differs'],
            array_map(fn (array $row): array => [$this->smallMark($row['n']), ...array_values($row)], $result['shadow']),
        );

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if (blank($value)) {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            throw new InvalidArgumentException("--{$name} must be a date in YYYY-MM-DD form.");
        }

        return CarbonImmutable::parse((string) $value);
    }

    private function smallMark(int $n): string
    {
        return $n < ValidationQualityReport::SMALL_SAMPLE ? '*' : '';
    }
}
