<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PlanApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobility.data_path' => base_path('tests/Fixtures/gtfs/heterogeneous')]);
        Artisan::call('mobility:import-gtfs');
    }

    public function test_api_plan_happy_path_returns_options_and_itineraries(): void
    {
        $payload = [
            'date' => '2026-10-27',
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => [
                'name' => 'UTBM Belfort',
                'lat' => 47.6425,
                'lon' => 6.8436,
            ],
            'participants' => [
                [
                    'name' => 'Robin',
                    'lat' => 47.5100,
                    'lon' => 6.8000,
                    'has_car' => false,
                ],
                [
                    'name' => 'Alice',
                    'lat' => 47.6350,
                    'lon' => 6.8600,
                    'has_car' => true,
                ],
            ],
        ];

        $response = $this->postJson('/api/plan', $payload);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'meta' => [
                'feed_snapshot_date',
                'valid_from',
                'valid_until',
                'computation_time_ms',
            ],
            'options' => [
                '*' => [
                    'meeting_points',
                    'arrival_at_destination',
                    'itineraries' => [
                        '*' => [
                            'participant',
                            'legs' => [
                                '*' => [
                                    'mode',
                                    'from',
                                    'to',
                                    'depart',
                                    'arrive',
                                    'duration_min',
                                    'distance_m',
                                ],
                            ],
                            'wait_min',
                            'transfers',
                            'walking_m',
                        ],
                    ],
                ],
            ],
        ]);

        $options = $response->json('options');
        $this->assertNotEmpty($options);
        $this->assertLessThanOrEqual(3, count($options));
    }

    public function test_api_plan_arrive_by_does_not_return_late_options(): void
    {
        $response = $this->postJson('/api/plan', [
            'date' => '2026-10-27',
            'time' => '11:30',
            'time_type' => 'arrive_by',
            'destination' => [
                'name' => 'UTBM Belfort',
                'lat' => 47.6425,
                'lon' => 6.8436,
            ],
            'participants' => [
                [
                    'name' => 'Robin',
                    'lat' => 47.5100,
                    'lon' => 6.8000,
                    'has_car' => false,
                ],
            ],
        ]);

        $response->assertStatus(200);
        foreach ($response->json('options') as $option) {
            $this->assertLessThanOrEqual('11:30', $option['arrival_at_destination']);
        }
    }

    public function test_api_plan_depart_at_does_not_return_arrivals_before_requested_time(): void
    {
        $response = $this->postJson('/api/plan', [
            'date' => '2026-10-27',
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => [
                'name' => 'UTBM Belfort',
                'lat' => 47.6425,
                'lon' => 6.8436,
            ],
            'participants' => [
                [
                    'name' => 'Robin',
                    'lat' => 47.5100,
                    'lon' => 6.8000,
                    'has_car' => false,
                ],
            ],
        ]);

        $response->assertStatus(200);
        foreach ($response->json('options') as $option) {
            $this->assertGreaterThanOrEqual('08:30', $option['arrival_at_destination']);
        }
    }

    public function test_api_plan_rejects_date_outside_validity_window(): void
    {
        $payload = [
            'date' => '2030-01-01', // Far in the future outside feed validity
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => [
                'name' => 'UTBM Belfort',
                'lat' => 47.6425,
                'lon' => 6.8436,
            ],
            'participants' => [
                [
                    'name' => 'Robin',
                    'lat' => 47.5100,
                    'lon' => 6.8000,
                    'has_car' => false,
                ],
            ],
        ];

        $response = $this->postJson('/api/plan', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date']);
    }

    public function test_api_plan_rejects_point_outside_belfort_montbeliard_bounding_box(): void
    {
        $payload = [
            'date' => '2026-10-27',
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => [
                'name' => 'Paris',
                'lat' => 48.8566, // Paris latitude outside 47.45 - 47.72
                'lon' => 2.3522,
            ],
            'participants' => [
                [
                    'name' => 'Robin',
                    'lat' => 47.5100,
                    'lon' => 6.8000,
                    'has_car' => false,
                ],
            ],
        ];

        $response = $this->postJson('/api/plan', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['destination.lat']);
    }

    public function test_api_plan_rejects_ride_sharing_for_a_driver(): void
    {
        $payload = [
            'date' => '2026-10-27',
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => [
                'name' => 'UTBM Belfort',
                'lat' => 47.6425,
                'lon' => 6.8436,
            ],
            'participants' => [
                [
                    'name' => 'Alice',
                    'lat' => 47.6350,
                    'lon' => 6.8600,
                    'has_car' => true,
                    'share_ride' => true,
                ],
            ],
        ];

        $response = $this->postJson('/api/plan', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['participants.0.share_ride']);
    }
}
