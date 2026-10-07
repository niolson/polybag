<?php

namespace App\Services\Validation;

use App\Enums\AddressValidationOutcome;
use App\Enums\PackageStatus;
use App\Enums\TrackingStatus;
use App\Models\AddressValidationAnswer;
use App\Models\Package;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Validator answers set against what later happened to the parcel
 * (`address-validation-routing/11`), as counts only: no Shipment, address or
 * client ever appears in the result, so it can be collected across tenants.
 *
 * One data point per Shipment that got a Label: the live answer that was
 * latest when its first Label was bought, with that answer's shadow answer
 * if FedEx was asked. Answers a re-validation replaced before then, and
 * Shipments never validated or never labelled, are not counted.
 *
 * @phpstan-type Row array<string, int|string>
 */
final class ValidationQualityReport
{
    /** Days after the first Label after which an unfinished outcome is reported as it stands. */
    public const FINAL_AFTER_DAYS = 30;

    /** Rows with fewer data points than this are too small to judge from. */
    public const SMALL_SAMPLE = 30;

    public const OUTCOMES = ['returned', 'exception', 'delivered', 'pending'];

    public const DIFFERENCES = ['street_differs', 'house_number_differs', 'city_differs', 'postcode_differs'];

    /** @var array<string, Row> */
    private array $answerRows = [];

    /** @var array<string, Row> */
    private array $shadowRows = [];

    /**
     * @return array{data_points: int, answers: list<Row>, shadow: list<Row>}
     */
    public function build(?CarbonInterface $since = null, ?CarbonInterface $until = null, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $this->answerRows = [];
        $this->shadowRows = [];
        $dataPoints = 0;

        $firstLabels = DB::table('package_labels')
            ->join('packages', 'packages.id', '=', 'package_labels.package_id')
            ->selectRaw('packages.shipment_id, MIN(COALESCE(package_labels.purchased_at, package_labels.created_at)) AS first_label_at')
            ->groupBy('packages.shipment_id');

        Shipment::query()
            ->joinSub($firstLabels, 'first_labels', fn (JoinClause $join) => $join->on('first_labels.shipment_id', '=', 'shipments.id'))
            ->when($since, fn ($query) => $query->where('first_labels.first_label_at', '>=', $since->toDateTimeString()))
            ->when($until, fn ($query) => $query->where('first_labels.first_label_at', '<=', $until->toDateTimeString()))
            ->select(['shipments.id', 'first_labels.first_label_at'])
            ->with([
                'validationAnswers',
                'packages:id,shipment_id,status,tracking_status,delivered_at',
            ])
            ->chunkById(500, function (Collection $shipments) use (&$dataPoints, $now): void {
                foreach ($shipments as $shipment) {
                    $firstLabelAt = CarbonImmutable::parse($shipment->getAttribute('first_label_at'));
                    $live = $this->answerAtFirstLabel($shipment->validationAnswers, $firstLabelAt);

                    if ($live === null) {
                        continue;
                    }

                    $dataPoints++;
                    $outcome = $this->outcome($shipment->packages, $firstLabelAt, $now);
                    $shadowAnswer = $shipment->validationAnswers->firstWhere('shadows_answer_id', $live->id);

                    $this->countAnswer($live, $outcome);

                    if ($shadowAnswer !== null) {
                        $this->countAnswer($shadowAnswer, $outcome);
                        $this->countShadow($live, $shadowAnswer, $outcome);
                    }
                }
            }, 'shipments.id', 'id');

        return [
            'data_points' => $dataPoints,
            'answers' => $this->sorted($this->answerRows),
            'shadow' => $this->sorted($this->shadowRows),
        ];
    }

    /**
     * The latest live answer at the moment the first Label was bought.
     *
     * @param  Collection<int, AddressValidationAnswer>  $answers
     */
    private function answerAtFirstLabel(Collection $answers, CarbonImmutable $firstLabelAt): ?AddressValidationAnswer
    {
        return $answers
            ->filter(fn (AddressValidationAnswer $answer): bool => ! $answer->shadow
                && $answer->created_at !== null
                && $answer->created_at->lte($firstLabelAt))
            ->sortByDesc('id')
            ->first();
    }

    /**
     * Returned and delivered are final at once; anything else stays pending
     * until the first Label is FINAL_AFTER_DAYS old, and is then reported as
     * it stands. Only Packages that are shipped now count, so a voided one
     * doesn't hold the Shipment back from delivered. A `delivered` status
     * counts without `delivered_at`: Shopify can report delivery with no time
     * and then stops polling.
     *
     * @param  Collection<int, Package>  $packages
     */
    private function outcome(Collection $packages, CarbonImmutable $firstLabelAt, CarbonImmutable $now): string
    {
        $shipped = $packages->filter(fn (Package $package): bool => $package->status === PackageStatus::Shipped);

        if ($shipped->contains(fn (Package $package): bool => $package->tracking_status === TrackingStatus::Returned)) {
            return 'returned';
        }

        if ($shipped->isNotEmpty() && $shipped->every(fn (Package $package): bool => $package->delivered_at !== null
            || $package->tracking_status === TrackingStatus::Delivered)) {
            return 'delivered';
        }

        $windowPassed = $firstLabelAt->addDays(self::FINAL_AFTER_DAYS)->lte($now);

        if ($windowPassed && $shipped->contains(fn (Package $package): bool => $package->tracking_status === TrackingStatus::Exception)) {
            return 'exception';
        }

        return 'pending';
    }

    private function countAnswer(AddressValidationAnswer $answer, string $outcome): void
    {
        $row = [
            'validator' => $answer->validator->value,
            'kind' => $answer->shadow ? 'shadow' : 'live',
            'country' => $answer->country ?? 'unknown',
            'verdict' => $this->verdict($answer),
        ];
        $key = implode('|', $row);

        $this->answerRows[$key] ??= [
            ...$row,
            'n' => 0,
            ...array_fill_keys(self::OUTCOMES, 0),
            'edited' => 0,
            ...array_fill_keys(AddressValidationAnswer::CHANGED_PARTS, 0),
        ];

        $this->answerRows[$key]['n']++;
        $this->answerRows[$key][$outcome]++;

        if ($answer->address_changed_at !== null) {
            $this->answerRows[$key]['edited']++;

            foreach (AddressValidationAnswer::CHANGED_PARTS as $part) {
                $this->answerRows[$key][$part] += (int) (bool) $answer->{$part};
            }
        }
    }

    private function countShadow(AddressValidationAnswer $live, AddressValidationAnswer $shadow, string $outcome): void
    {
        $row = [
            'country' => $live->country ?? 'unknown',
            'live_validator' => $live->validator->value,
            'live_verdict' => $this->verdict($live),
            'shadow_verdict' => $this->verdict($shadow),
        ];
        $key = implode('|', $row);

        $this->shadowRows[$key] ??= [
            ...$row,
            'n' => 0,
            ...array_fill_keys(self::OUTCOMES, 0),
            ...array_fill_keys(self::DIFFERENCES, 0),
        ];

        $this->shadowRows[$key]['n']++;
        $this->shadowRows[$key][$outcome]++;

        foreach (self::DIFFERENCES as $difference) {
            $this->shadowRows[$key][$difference] += (int) (bool) $shadow->{$difference};
        }
    }

    /**
     * The deliverability a settled answer gave, or why an answer was
     * inconclusive.
     */
    private function verdict(AddressValidationAnswer $answer): string
    {
        if ($answer->outcome === AddressValidationOutcome::Settled) {
            return $answer->deliverability->value ?? 'settled';
        }

        return 'inconclusive:'.($answer->reason->value ?? 'unknown');
    }

    /**
     * @param  array<string, Row>  $rows
     * @return list<Row>
     */
    private function sorted(array $rows): array
    {
        ksort($rows);

        return array_values($rows);
    }
}
