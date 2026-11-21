<?php

namespace Tests\Unit;

use App\Services\Routing\ConnectionScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectionScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $now = now();
        DB::table('stops')->insert([
            ['feed_id' => 'sncf', 'stop_id' => 'TER_BELFORT', 'name' => 'Belfort', 'lat' => 47.63, 'lon' => 6.85, 'location_type' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['feed_id' => 'sncf', 'stop_id' => 'TER_MONTBELIARD', 'name' => 'Montbéliard', 'lat' => 47.51, 'lon' => 6.80, 'location_type' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('routes')->insert([
            'feed_id' => 'sncf', 'route_id' => 'TER_LIGNE_BM', 'short_name' => 'TER', 'long_name' => 'Belfort Montbéliard', 'route_type' => 2, 'route_color' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('calendar')->insert([
            'feed_id' => 'sncf', 'service_id' => 'TER_DAILY', 'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('trips')->insert([
            'feed_id' => 'sncf', 'trip_id' => 'TER_TRIP_1', 'route_id' => 'TER_LIGNE_BM', 'service_id' => 'TER_DAILY', 'trip_headsign' => 'Montbéliard', 'direction_id' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('stop_times')->insert([
            ['feed_id' => 'sncf', 'trip_id' => 'TER_TRIP_1', 'stop_id' => 'TER_BELFORT', 'arrival_time' => '08:30:00', 'departure_time' => '08:30:00', 'arrival_seconds' => 30600, 'departure_seconds' => 30600, 'stop_sequence' => 1, 'pickup_type' => 0, 'drop_off_type' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['feed_id' => 'sncf', 'trip_id' => 'TER_TRIP_1', 'stop_id' => 'TER_MONTBELIARD', 'arrival_time' => '08:46:00', 'departure_time' => '08:46:00', 'arrival_seconds' => 31560, 'departure_seconds' => 31560, 'stop_sequence' => 2, 'pickup_type' => 0, 'drop_off_type' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function test_connection_scan_finds_earliest_ter_trip_between_belfort_and_montbeliard(): void
    {
        $csa = new ConnectionScan;

        // Start at Belfort station at 08:15 (29700 seconds from midnight) on 2026-10-27
        $startTime = (8 * 3600) + (15 * 60); // 08:15:00 = 29700s
        $date = '2026-10-27';

        $result = $csa->earliestArrival(['TER_BELFORT' => $startTime], $date);

        $arrivals = $result['arrivals'];
        $predecessors = $result['predecessors'];

        // Must reach Montbéliard station
        $this->assertArrayHasKey('sncf:TER_MONTBELIARD', $arrivals);

        // Next train departs Belfort at 08:30 (30600s) and arrives at Montbéliard at 08:46 (31560s)
        $expectedArrival = (8 * 3600) + (46 * 60); // 31560
        $this->assertSame($expectedArrival, $arrivals['sncf:TER_MONTBELIARD']);

        // Must have recorded a transit leg
        $this->assertSame('transit', $predecessors['sncf:TER_MONTBELIARD']['type']);
        $this->assertSame('sncf:TER_LIGNE_BM', $predecessors['sncf:TER_MONTBELIARD']['route_id']);
    }

    public function test_reverse_connection_scan_finds_latest_departure(): void
    {
        $csa = new ConnectionScan;

        // Must arrive at Montbéliard station by 09:00 (32400 seconds)
        $targetArrival = 9 * 3600;
        $date = '2026-10-27';

        $latestDep = $csa->latestDeparture(['TER_MONTBELIARD' => $targetArrival], $date);

        // Should find latest departure from Belfort is 08:30 (30600s)
        $this->assertArrayHasKey('sncf:TER_BELFORT', $latestDep);
        $this->assertGreaterThanOrEqual(30600, $latestDep['sncf:TER_BELFORT']);
    }
}
