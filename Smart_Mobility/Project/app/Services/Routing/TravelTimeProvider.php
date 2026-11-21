<?php

namespace App\Services\Routing;

interface TravelTimeProvider
{
    /**
     * Compute ground distance in meters between two coordinates.
     */
    public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float;

    /**
     * Compute travel duration in seconds between two coordinates for the given mode.
     * Supported modes: walk, bike, car, car_passenger, shared_car.
     */
    public function durationSeconds(float $lat1, float $lon1, float $lat2, float $lon2, string $mode = 'walk'): int;
}
