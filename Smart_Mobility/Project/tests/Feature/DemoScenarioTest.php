<?php

namespace Tests\Feature;

use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DemoScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobility.data_path' => base_path('tests/Fixtures/gtfs/heterogeneous')]);
        Artisan::call('mobility:import-gtfs');
    }

    public function test_demo_scenario_with_four_participants_returns_optimal_solution(): void
    {
        $payload = DemoScenarioSeeder::getScenario();

        $response = $this->postJson('/api/plan', $payload);

        $response->assertStatus(200);
        $options = $response->json('options');

        $this->assertNotEmpty($options);
        $topOption = $options[0];

        $this->assertSame([], $topOption['meeting_points']);
        $this->assertCount(4, $topOption['itineraries']);
    }

    public function test_performance_timing_for_two_five_and_ten_participants(): void
    {
        $destination = ['name' => 'Gare Belfort-Montbéliard TGV', 'lat' => 47.5861, 'lon' => 6.8972];

        // 2 participants
        $p2 = [
            ['name' => 'P1', 'lat' => 47.6350, 'lon' => 6.8600, 'has_car' => true],
            ['name' => 'P2', 'lat' => 47.5100, 'lon' => 6.7980, 'has_car' => false],
        ];

        $t0 = microtime(true);
        $resp2 = $this->postJson('/api/plan', [
            'date' => '2026-10-27',
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => $destination,
            'participants' => $p2,
        ]);
        $time2 = (microtime(true) - $t0) * 1000;
        $resp2->assertStatus(200);

        // 5 participants
        $p5 = array_merge($p2, [
            ['name' => 'P3', 'lat' => 47.6450, 'lon' => 6.8400, 'has_car' => false],
            ['name' => 'P4', 'lat' => 47.5020, 'lon' => 6.8220, 'has_car' => false],
            ['name' => 'P5', 'lat' => 47.5752, 'lon' => 6.8645, 'has_car' => false],
        ]);

        $t0 = microtime(true);
        $resp5 = $this->postJson('/api/plan', [
            'date' => '2026-10-27',
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => $destination,
            'participants' => $p5,
        ]);
        $time5 = (microtime(true) - $t0) * 1000;
        $resp5->assertStatus(200);

        // 10 participants
        $p10 = array_merge($p5, [
            ['name' => 'P6', 'lat' => 47.6185, 'lon' => 6.8295, 'has_car' => false],
            ['name' => 'P7', 'lat' => 47.5275, 'lon' => 6.8252, 'has_car' => false],
            ['name' => 'P8', 'lat' => 47.5165, 'lon' => 6.8315, 'has_car' => false],
            ['name' => 'P9', 'lat' => 47.5592, 'lon' => 6.8488, 'has_car' => false],
            ['name' => 'P10', 'lat' => 47.6672, 'lon' => 6.8312, 'has_car' => false],
        ]);

        $t0 = microtime(true);
        $resp10 = $this->postJson('/api/plan', [
            'date' => '2026-10-27',
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => $destination,
            'participants' => $p10,
        ]);
        $time10 = (microtime(true) - $t0) * 1000;
        $resp10->assertStatus(200);

        // Assert reasonable completion times (under 2 seconds per query in testing)
        $this->assertLessThan(2000, $time2, "2 participants took {$time2}ms");
        $this->assertLessThan(3000, $time5, "5 participants took {$time5}ms");
        $this->assertLessThan(5000, $time10, "10 participants took {$time10}ms");
    }
}
