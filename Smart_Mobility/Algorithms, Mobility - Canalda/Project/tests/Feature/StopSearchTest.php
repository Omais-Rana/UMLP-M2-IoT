<?php

namespace Tests\Feature;

use App\Models\Stop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StopSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_stop_search_returns_feed_qualified_results(): void
    {
        Stop::create([
            'feed_id' => 'optymo',
            'stop_id' => 'same-id',
            'name' => 'Hôtel de Ville',
            'lat' => 47.64,
            'lon' => 6.85,
        ]);
        Stop::create([
            'feed_id' => 'evolity',
            'stop_id' => 'same-id',
            'name' => 'Hôtel de Ville',
            'lat' => 47.51,
            'lon' => 6.80,
        ]);

        $response = $this->getJson('/api/stops/search?q=hotel&lat=47.64&lon=6.85&radius_m=500');

        $response->assertOk()
            ->assertJsonPath('data.0.feed_id', 'optymo')
            ->assertJsonPath('data.0.stop_id', 'same-id')
            ->assertJsonPath('data.0.name', 'Hôtel de Ville');
        $this->assertCount(1, $response->json('data'));
    }
}
