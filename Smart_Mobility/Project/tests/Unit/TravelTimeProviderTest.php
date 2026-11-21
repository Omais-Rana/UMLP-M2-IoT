<?php

namespace Tests\Unit;

use App\Services\Routing\HaversineTravelTime;
use Tests\TestCase;

class TravelTimeProviderTest extends TestCase
{
    public function test_distance_and_duration_calculations(): void
    {
        $provider = new HaversineTravelTime(
            walkSpeedKmh: 4.8,
            bikeSpeedKmh: 15.0,
            carSpeedUrbanKmh: 35.0,
            carSpeedInterurbanKmh: 70.0,
            detourFactor: 1.3
        );

        // Belfort Gare (47.6334, 6.8542) to Montbeliard Gare (47.5118, 6.7981)
        // Straight line distance ~14.1 km, with 1.3 detour ~18.3 km
        $dist = $provider->distanceMeters(47.6334, 6.8542, 47.5118, 6.7981);
        $this->assertGreaterThan(17000, $dist);
        $this->assertLessThan(20000, $dist);

        // Car duration at interurban speed (70 km/h)
        $carDuration = $provider->durationSeconds(47.6334, 6.8542, 47.5118, 6.7981, 'car');
        // ~18.3 km at 70 km/h is ~15-18 minutes (900-1100s)
        $this->assertGreaterThan(800, $carDuration);
        $this->assertLessThan(1200, $carDuration);

        // Same point distance is 0
        $this->assertSame(0.0, $provider->distanceMeters(47.6334, 6.8542, 47.6334, 6.8542));
        $this->assertSame(0, $provider->durationSeconds(47.6334, 6.8542, 47.6334, 6.8542));
    }
}
