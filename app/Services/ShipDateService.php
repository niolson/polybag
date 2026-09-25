<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShipDateService
{
    /**
     * The pickup days a carrier has at a location nobody has configured one for.
     *
     * Mon–Fri is a deliberate policy rather than a placeholder, and it applies to
     * every carrier: nothing seeds a `carrier_location` row, so this is what USPS,
     * FedEx and OnTrac alike run on until an operator says otherwise. Saturday
     * pickup varies by warehouse, which is the case the per-location config exists
     * to serve, so seeding Mon–Sat globally would assert something about every
     * install that is only true of some. See ADR-0002.
     *
     * The direction matters: this default never dates a label for a pickup that
     * does not happen. Its cost is a Saturday-packed parcel carrying Monday's date,
     * which an operator fixes by adding the carrier here.
     */
    private const DEFAULT_PICKUP_DAYS = [1, 2, 3, 4, 5]; // Mon-Fri

    /**
     * The date a label goes out on, for the carrier expected to carry it.
     *
     * Takes the carrier row, never a name: the purchase is dated by the carrier
     * resolved when its offer was issued, so a rename between quote and
     * purchase changes nothing (ADR-0006, guideline 12). Null is a carrier with
     * no row — one Amazon names that nobody has seeded — and gets no cutoff
     * and the default pickup days.
     */
    public function getShipDate(?Carrier $carrier, ?int $locationId = null): CarbonImmutable
    {
        $location = $this->resolveLocation($locationId);
        $tz = $location?->timezone ?? 'America/New_York';
        $pivot = $this->getPivot($carrier, $locationId);
        $pickupDays = $this->pickupDaysFor($pivot);
        $lastEndOfDay = $pivot?->last_end_of_day_at ? CarbonImmutable::parse($pivot->last_end_of_day_at) : null;
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();

        // If we already ended the shipping day today (in local time), ship date = next pickup day
        if ($lastEndOfDay && $lastEndOfDay->tz($tz)->isToday()) {
            return $this->getNextPickupDay($pickupDays, $today);
        }

        // The cutoff is a property of the carrier row. A carrier with no row, or
        // one with no cutoff configured, simply has no cutoff.
        $cutoffHour = $carrier?->pickup_cutoff_hour;

        if ($cutoffHour !== null && $now->hour >= $cutoffHour) {
            return $this->getNextPickupDay($pickupDays, $today);
        }

        // Otherwise, today if it's a pickup day, else next pickup day
        if (in_array($today->dayOfWeek, $pickupDays)) {
            return $today;
        }

        return $this->getNextPickupDay($pickupDays, $today);
    }

    /**
     * @param  array<int, int>|Carrier|null  $pickupDaysOrCarrier
     */
    public function getNextPickupDay(array|Carrier|null $pickupDaysOrCarrier, CarbonImmutable|int|null $afterOrLocationId = null, ?CarbonImmutable $after = null): CarbonImmutable
    {
        // Support both calling conventions:
        // getNextPickupDay(array $pickupDays, CarbonImmutable $after)
        // getNextPickupDay(?Carrier $carrier, ?int $locationId, ?CarbonImmutable $after)
        if (! is_array($pickupDaysOrCarrier)) {
            $locationId = is_int($afterOrLocationId) ? $afterOrLocationId : null;
            $location = $this->resolveLocation($locationId);
            $tz = $location?->timezone ?? 'America/New_York';
            $afterDate = $after ?? CarbonImmutable::today($tz);
            $pickupDays = $this->pickupDaysFor($this->getPivot($pickupDaysOrCarrier, $locationId));
        } else {
            $pickupDays = $pickupDaysOrCarrier;
            $afterDate = $afterOrLocationId instanceof CarbonImmutable ? $afterOrLocationId : CarbonImmutable::today();
        }

        $date = $afterDate->addDay();

        for ($i = 0; $i < 7; $i++) {
            if (in_array($date->dayOfWeek, $pickupDays)) {
                return $date;
            }
            $date = $date->addDay();
        }

        // Safety: if no pickup days configured, return tomorrow
        return $afterDate->addDay();
    }

    /**
     * End a carrier's shipping day at a location.
     *
     * Moves the date of everything the carrier dates, whichever source sells
     * it: direct labels, Amazon's offers on this carrier, and Shopify labels
     * dated as this carrier.
     */
    /**
     * The last pickup day strictly before a date, within the week before it.
     *
     * @param  array<int, int>  $pickupDays
     */
    private function getPreviousPickupDay(array $pickupDays, CarbonImmutable $before): CarbonImmutable
    {
        $date = $before->subDay();

        for ($i = 0; $i < 7; $i++) {
            if (in_array($date->dayOfWeek, $pickupDays)) {
                return $date;
            }
            $date = $date->subDay();
        }

        return $before->subDay();
    }

    public function endShippingDay(Carrier $carrier, ?int $locationId = null): void
    {
        $locationId = $locationId ?? Location::getDefault()?->id;

        if (! $locationId) {
            return;
        }

        $exists = DB::table('carrier_location')
            ->where('carrier_id', $carrier->id)
            ->where('location_id', $locationId)
            ->exists();

        if ($exists) {
            DB::table('carrier_location')
                ->where('carrier_id', $carrier->id)
                ->where('location_id', $locationId)
                ->update([
                    'last_end_of_day_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('carrier_location')->insert([
                'carrier_id' => $carrier->id,
                'location_id' => $locationId,
                'last_end_of_day_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * The carrier's last End of Day at a location, if it ended the batch now
     * waiting: on or after the pickup day before the current ship date.
     *
     * End of Day counts a label under its carrier if its ship date is the
     * carrier's current one, or it was bought after this moment. The second
     * test catches a label whose date came from another carrier's policy, as
     * a Shopify `auto` label dated by the connection's carrier does, when
     * this carrier's day has already moved on. An End of Day older than the
     * last pickup ended some earlier batch, not this one, so it is ignored
     * rather than letting everything since then count. Null when the day has
     * never been ended there, which leaves the ship date alone to decide.
     */
    public function lastEndOfDayForCurrentBatch(Carrier $carrier, ?int $locationId = null): ?CarbonImmutable
    {
        $pivot = $this->getPivot($carrier, $locationId);

        if (! $pivot?->last_end_of_day_at) {
            return null;
        }

        $location = $this->resolveLocation($locationId);
        $tz = $location !== null ? $location->timezone : 'America/New_York';
        $lastEndOfDay = CarbonImmutable::parse($pivot->last_end_of_day_at);
        $previousPickupDay = $this->getPreviousPickupDay(
            $this->pickupDaysFor($pivot),
            $this->getShipDate($carrier, $locationId),
        );

        return $lastEndOfDay->tz($tz)->startOfDay()->gte($previousPickupDay) ? $lastEndOfDay : null;
    }

    /**
     * @return array<int, int>
     */
    public function getPickupDays(?Carrier $carrier, ?int $locationId = null): array
    {
        return $this->pickupDaysFor($this->getPivot($carrier, $locationId));
    }

    /**
     * @return array<int, int>
     */
    private function pickupDaysFor(?object $pivot): array
    {
        if (! $pivot || ! $pivot->pickup_days) {
            return self::DEFAULT_PICKUP_DAYS;
        }

        // An empty set falls back rather than passing through. A row can hold one
        // — `endShippingDay()` writes the pivot without pickup days, and a saved
        // config with nothing ticked stores `[]` — and passing it through would
        // leave `getNextPickupDay()` no day to find, sending it to its tomorrow
        // fallback and dating labels for Sundays.
        //
        // Note that nothing here means "this carrier never collects at this
        // location": an empty set and a deleted row both land on the default,
        // because a package shipped on that carrier still needs a ship date and
        // there is no honest one to give it otherwise. Keeping a carrier out of a
        // location is what carrier account scoping does; these days only say when
        // a carrier that does serve it collects.
        return json_decode($pivot->pickup_days, true) ?: self::DEFAULT_PICKUP_DAYS;
    }

    private function resolveLocation(?int $locationId = null): ?Location
    {
        if ($locationId) {
            return Location::find($locationId);
        }

        return Location::getDefault();
    }

    private function getPivot(?Carrier $carrier, ?int $locationId = null): ?object
    {
        $locationId = $locationId ?? $this->resolveLocation()?->id;

        if (! $carrier || ! $locationId) {
            return null;
        }

        return DB::table('carrier_location')
            ->where('carrier_id', $carrier->id)
            ->where('location_id', $locationId)
            ->first();
    }
}
