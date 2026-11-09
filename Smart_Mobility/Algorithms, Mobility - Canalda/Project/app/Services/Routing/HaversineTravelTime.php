<?php

namespace App\Services\Routing;

class HaversineTravelTime implements TravelTimeProvider
{
    protected const EARTH_RADIUS_METERS = 6371000.0;

    public function __construct(
        protected float $walkSpeedKmh = 4.8,
        protected float $bikeSpeedKmh = 15.0,
        protected float $carSpeedUrbanKmh = 35.0,
        protected float $carSpeedInterurbanKmh = 70.0,
        protected float $detourFactor = 1.3,
    ) {
        $this->walkSpeedKmh = (float) config('mobility.walk_speed_kmh', $this->walkSpeedKmh);
        $this->bikeSpeedKmh = (float) config('mobility.bike_speed_kmh', $this->bikeSpeedKmh);
        $this->carSpeedUrbanKmh = (float) config('mobility.car_speed_urban_kmh', $this->carSpeedUrbanKmh);
        $this->carSpeedInterurbanKmh = (float) config('mobility.car_speed_interurban_kmh', $this->carSpeedInterurbanKmh);
        $this->detourFactor = (float) config('mobility.detour_factor', $this->detourFactor);
    }

    /**
     * Compute ground distance in meters using the Haversine formula, adjusted by detour factor.
     */
    public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        if (abs($lat1 - $lat2) < 1e-7 && abs($lon1 - $lon2) < 1e-7) {
            return 0.0;
        }

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        $haversineMeters = $angle * self::EARTH_RADIUS_METERS;

        return $haversineMeters * $this->detourFactor;
    }

    /**
     * Compute travel duration in seconds between two points for the specified mode.
     */
    public function durationSeconds(float $lat1, float $lon1, float $lat2, float $lon2, string $mode = 'walk'): int
    {
        $distanceMeters = $this->distanceMeters($lat1, $lon1, $lat2, $lon2);

        if ($distanceMeters <= 0.0) {
            return 0;
        }

        $speedKmh = match ($mode) {
            'bike' => $this->bikeSpeedKmh,
            'car', 'car_passenger', 'shared_car' => $distanceMeters > 4000.0
                ? $this->carSpeedInterurbanKmh
                : $this->carSpeedUrbanKmh,
            default => $this->walkSpeedKmh,
        };

        $speedMps = ($speedKmh * 1000.0) / 3600.0;

        return (int) max(1, round($distanceMeters / $speedMps));
    }
}
