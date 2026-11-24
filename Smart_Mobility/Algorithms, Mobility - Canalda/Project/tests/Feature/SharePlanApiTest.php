<?php

namespace Tests\Feature;

use App\Models\SharedPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharePlanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_store_shared_plan_and_receive_unique_code(): void
    {
        $payload = [
            'option' => [
                'title' => 'Option 1 (Shared Meeting)',
                'arrival_at_destination' => '12:00',
                'strategy_type' => 'shared_midpoint',
                'meeting_points' => [
                    [
                        'name' => 'Gare de Belfort',
                        'lat' => 47.6333,
                        'lon' => 6.8542,
                        'type' => 'midpoint',
                    ],
                ],
                'itineraries' => [
                    [
                        'participant' => 'Alice',
                        'total_duration_min' => 25,
                        'legs' => [
                            [
                                'mode' => 'walk',
                                'depart' => '11:35',
                                'arrive' => '12:00',
                                'duration_min' => 25,
                                'from' => ['name' => 'Home', 'lat' => 47.63, 'lon' => 6.85],
                                'to' => ['name' => 'Destination', 'lat' => 47.64, 'lon' => 6.86],
                            ],
                        ],
                    ],
                ],
            ],
            'destination' => [
                'name' => 'UMLP Montbéliard',
                'lat' => 47.49573,
                'lon' => 6.80400,
            ],
            'participants' => [
                ['name' => 'Alice', 'lat' => 47.63, 'lon' => 6.85, 'has_car' => false, 'color' => '#2563EB', 'pickup_passengers' => []],
            ],
        ];

        $response = $this->postJson('/api/share-plan', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'code',
                'share_url',
            ]);

        $code = $response->json('code');
        $this->assertEquals(6, strlen($code));
        $this->assertDatabaseHas('shared_plans', [
            'code' => $code,
        ]);

        // Verify that all option fields (including meeting_points) are preserved
        $plan = SharedPlan::where('code', $code)->first();
        $this->assertNotNull($plan->option['meeting_points'] ?? null);
        $this->assertEquals('Gare de Belfort', $plan->option['meeting_points'][0]['name']);
        $this->assertNotNull($plan->participants);
        $this->assertNotNull($plan->expires_at);
        $this->assertTrue($plan->expires_at->isFuture());
        $this->assertGreaterThan(28, now()->diffInMinutes($plan->expires_at));
        $this->assertLessThanOrEqual(30, now()->diffInMinutes($plan->expires_at));
    }

    public function test_store_validation_fails_when_option_missing_or_invalid(): void
    {
        $response = $this->postJson('/api/share-plan', [
            'destination' => [
                'name' => 'Test',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['option']);
    }

    public function test_can_retrieve_shared_plan_by_code(): void
    {
        $plan = SharedPlan::create([
            'code' => 'K7X9PQ',
            'option' => [
                'title' => 'Option 1',
                'arrival_at_destination' => '14:30',
                'strategy_type' => 'shared_midpoint',
                'meeting_points' => [['name' => 'Gare de Belfort', 'lat' => 47.6333, 'lon' => 6.8542, 'type' => 'midpoint']],
                'itineraries' => [
                    [
                        'participant' => 'Bob',
                        'total_duration_min' => 15,
                        'legs' => [],
                    ],
                ],
            ],
            'destination' => [
                'name' => 'Belfort Centre',
                'lat' => 47.6397,
                'lon' => 6.8638,
            ],
            'participants' => [
                ['name' => 'Bob', 'lat' => 47.63, 'lon' => 6.85, 'has_car' => false, 'color' => '#2563EB', 'pickup_passengers' => []],
            ],
        ]);

        $response = $this->getJson('/api/share-plan/k7x9pq'); // test case-insensitivity

        $response->assertStatus(200)
            ->assertJson([
                'code' => 'K7X9PQ',
                'option' => [
                    'title' => 'Option 1',
                    'arrival_at_destination' => '14:30',
                    'meeting_points' => [['name' => 'Gare de Belfort']],
                ],
                'destination' => [
                    'name' => 'Belfort Centre',
                ],
                'participants' => [
                    ['name' => 'Bob'],
                ],
            ]);
    }

    public function test_returns_404_when_code_not_found(): void
    {
        $response = $this->getJson('/api/share-plan/NONEXISTENT');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Shared plan expired or not found.',
            ]);
    }

    public function test_expired_shared_plan_returns_404_and_is_deleted_from_database(): void
    {
        $plan = SharedPlan::create([
            'code' => 'EXPD30',
            'option' => [
                'title' => 'Expired Option',
                'itineraries' => [],
            ],
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertDatabaseHas('shared_plans', ['id' => $plan->id]);

        $response = $this->getJson('/api/share-plan/EXPD30');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Shared plan expired or not found.',
            ]);

        $this->assertDatabaseMissing('shared_plans', ['id' => $plan->id]);
    }

    public function test_prune_expired_removes_old_plans_and_keeps_active_plans(): void
    {
        $activePlan = SharedPlan::create([
            'code' => 'ACTV01',
            'option' => ['itineraries' => []],
            'expires_at' => now()->addMinutes(15),
        ]);

        $expiredPlan = SharedPlan::create([
            'code' => 'OLD001',
            'option' => ['itineraries' => []],
            'expires_at' => now()->subMinutes(10),
        ]);

        $prunedCount = SharedPlan::pruneExpired();

        $this->assertEquals(1, $prunedCount);
        $this->assertDatabaseHas('shared_plans', ['id' => $activePlan->id]);
        $this->assertDatabaseMissing('shared_plans', ['id' => $expiredPlan->id]);
    }
}
