<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportCarParksTest extends TestCase
{
    use RefreshDatabase;

    public function test_parking_import_maps_nodes_and_ways_without_mock_fallback(): void
    {
        DB::table('stops')->insert([
            'feed_id' => 'fixture',
            'stop_id' => 'origin',
            'name' => 'Origin',
            'lat' => 47.64,
            'lon' => 6.85,
            'location_type' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            '*example.test/overpass*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 1,
                        'lat' => 47.641,
                        'lon' => 6.851,
                        'tags' => ['name' => 'Gare Parking', 'capacity' => '120'],
                    ],
                    [
                        'type' => 'way',
                        'id' => 2,
                        'center' => ['lat' => 47.642, 'lon' => 6.852],
                        'tags' => ['amenity' => 'parking', 'access' => 'private'],
                    ],
                ],
            ]),
        ]);

        $exitCode = Artisan::call('mobility:import-car-parks', ['--url' => 'https://example.test/overpass']);
        $this->assertSame(0, $exitCode, Artisan::output());

        $this->assertDatabaseHas('car_parks', [
            'name' => 'Gare Parking',
            'capacity' => 120,
        ]);
        $this->assertDatabaseCount('car_parks', 1);
    }

    public function test_parking_import_reports_provider_failures(): void
    {
        Http::fake([
            '*example.test/overpass*' => Http::response([], 503),
        ]);

        $exitCode = Artisan::call('mobility:import-car-parks', ['--url' => 'https://example.test/overpass']);
        $this->assertSame(1, $exitCode, Artisan::output());
    }
}
