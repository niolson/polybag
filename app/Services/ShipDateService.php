<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShipDateService
{
    private const DEFAULT_PICKUP_DAYS = [1, 2, 3, 4, 5]; // Mon-Fri

    /** USPS SCAN form cutoff — packages shipped after this hour go on the next day's form. */
    private const USPS_CUTOFF_HOUR = 20; // 8 PM local time

    public function getShipDate(string $carrierName, ?int $locationId = null): CarbonImmutable
    {
        $location = $this->resolveLocation($locationId);
        $tz = $location?->timezone ?? 'America/New_York';
        $pivot = $this->getPivot($carrierName, $locationId);
        $pickupDays = $pivot ? json_decode($pivot->pickup_days, true) : self::DEFAULT_PICKUP_DAYS;
        $lastEndOfDay = $pivot?->last_end_of_day_at ? CarbonImmutable::parse($pivot->last_end_of_day_at) : null;
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();

        // If we already ended the shipping day today (in local time), ship date = next pickup day
        if ($lastEndOfDay && $lastEndOfDay->tz($tz)->isToday()) {
            return $this->getNextPickupDay($pickupDays, $today);
        }

        // USPS: after 8 PM local time, advance to next pickup day
        if ($carrierName === 'USPS' && $now->hour >= self::USPS_CUTOFF_HOUR) {
            return $this->getNextPickupDay($pickupDays, $today);
        }

        // Otherwise, today if it's a pickup day, else next pickup day
        if (in_array($today->dayOfWeek, $pickupDays)) {
            return $today;
        }

        return $this->getNextPickupDay($pickupDays, $today);
    }

    public function getNextPickupDay(array|string $pickupDaysOrCarrier, CarbonImmutable|int|null $afterOrLocationId = null, ?CarbonImmutable $after = null): CarbonImmutable
    {
        // Support both calling conventions:
        // getNextPickupDay(array $pickupDays, CarbonImmutable $after)
        // getNextPickupDay(string $carrierName, ?int $locationId, ?CarbonImmutable $after)
        if (is_string($pickupDaysOrCarrier)) {
            $carrierName = $pickupDaysOrCarrier;
            $locationId = $afterOrLocationId;
            $location = $this->resolveLocation($locationId);
            $tz = $location?->timezone ?? 'America/New_York';
            $afterDate = $after ?? CarbonImmutable::today($tz);
            $pivot = $this->getPivot($carrierName, $locationId);
            $pickupDays = $pivot ? json_decode($pivot->pickup_days, true) : self::DEFAULT_PICKUP_DAYS;
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

    public function endShippingDay(string $carrierName, ?int $locationId = null): void
    {
        $locationId = $locationId ?? Location::getDefault()?->id;

        if (! $locationId) {
            return;
        }

        $carrier = Carrier::where('name', $carrierName)->first();

        if (! $carrier) {
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

    public function getPickupDays(string $carrierName, ?int $locationId = null): array
    {
        $pivot = $this->getPivot($carrierName, $locationId);

        return $pivot ? json_decode($pivot->pickup_days, true) : self::DEFAULT_PICKUP_DAYS;
    }

    private function resolveLocation(?int $locationId = null): ?Location
    {
        if ($locationId) {
            return Location::find($locationId);
        }

        return Location::getDefault();
    }

    private function getPivot(string $carrierName, ?int $locationId = null): ?object
    {
        $locationId = $locationId ?? $this->resolveLocation()?->id;

        if (! $locationId) {
            return null;
        }

        return DB::table('carrier_location')
            ->join('carriers', 'carriers.id', '=', 'carrier_location.carrier_id')
            ->where('carriers.name', $carrierName)
            ->where('carrier_location.location_id', $locationId)
            ->select('carrier_location.*')
            ->first();
    }
}
