<?php

namespace Tests\Unit;

use App\Models\CarShareStation;
use App\Services\Planning\MeetingPointFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeetingPointFinderSharedCarTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_city_journey_does_not_offer_shared_car(): void
    {
        $this->seedSharedCarStation();

        $strategy = $this->sharedCarStrategy([
            ['name' => 'Alice', 'lat' => 47.63, 'lon' => 6.84, 'has_car' => false],
        ], ['name' => 'Belfort', 'lat' => 47.64, 'lon' => 6.85]);

        $this->assertNull($strategy);
    }

    public function test_cross_boundary_journey_offers_shared_car(): void
    {
        $this->seedSharedCarStation();

        $strategy = $this->sharedCarStrategy([
            ['name' => 'Alice', 'lat' => 47.53, 'lon' => 6.80, 'has_car' => false],
        ], ['name' => 'Belfort', 'lat' => 47.64, 'lon' => 6.85]);

        $this->assertNotNull($strategy);
        $this->assertSame('gbfs_car', $strategy['strategy_type']);
        $this->assertCount(1, $strategy['itineraries']);
    }

    public function test_unavailable_station_does_not_offer_shared_car(): void
    {
        $this->seedSharedCarStation(0);

        $strategy = $this->sharedCarStrategy([
            ['name' => 'Alice', 'lat' => 47.53, 'lon' => 6.80, 'has_car' => false],
        ], ['name' => 'Belfort', 'lat' => 47.64, 'lon' => 6.85]);

        $this->assertNull($strategy);
    }

    public function test_shared_car_route_ends_at_destination_after_parking(): void
    {
        $this->seedSharedCarStation();

        $strategy = $this->sharedCarStrategy([
            ['name' => 'Alice', 'lat' => 47.53, 'lon' => 6.80, 'has_car' => false],
        ], ['name' => 'Belfort', 'lat' => 47.64, 'lon' => 6.85]);

        $legs = $strategy['itineraries'][0]['legs'];

        $this->assertSame(['walk', 'car_share', 'walk'], array_column($legs, 'mode'));
        $this->assertSame('Belfort', $legs[2]['to']['name']);
    }

    public function test_shared_car_route_has_no_return_journey(): void
    {
        $this->seedSharedCarStation();

        $strategy = $this->sharedCarStrategy([
            ['name' => 'Alice', 'lat' => 47.53, 'lon' => 6.80, 'has_car' => false],
        ], ['name' => 'Belfort', 'lat' => 47.64, 'lon' => 6.85]);

        $legs = $strategy['itineraries'][0]['legs'];

        $this->assertCount(3, $legs);
        $this->assertNotSame('car_share', $legs[array_key_last($legs)]['mode']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sharedCarStrategy(array $participants, array $destination): ?array
    {
        $finder = $this->app->make(MeetingPointFinder::class);
        $method = new \ReflectionMethod($finder, 'sharedCarStrategy');
        $method->setAccessible(true);

        return $method->invoke($finder, $participants, $destination, 8 * 3600);
    }

    private function seedSharedCarStation(int $cars = 1): void
    {
        CarShareStation::query()->create([
            'station_id' => 'car-1',
            'name' => 'Montbéliard station',
            'lat' => 47.53,
            'lon' => 6.80,
            'num_cars_available' => $cars,
            'car_types' => ['electric'],
        ]);

        DB::table('car_parks')->insert([
            'name' => 'Belfort parking',
            'lat' => 47.64,
            'lon' => 6.85,
            'capacity' => 50,
            'is_park_and_ride' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
