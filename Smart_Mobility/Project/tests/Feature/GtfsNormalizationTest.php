<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GtfsNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_heterogeneous_gtfs_fixture_is_normalized(): void
    {
        config(['mobility.data_path' => base_path('tests/Fixtures/gtfs/heterogeneous')]);

        Artisan::call('mobility:import-gtfs');

        $this->assertDatabaseHas('transport_feeds', [
            'feed_id' => 'fixture',
            'feed_name' => 'Réseau Prototype',
            'version' => 'fixture-1',
        ]);
        $this->assertDatabaseHas('stops', [
            'feed_id' => 'fixture',
            'stop_id' => 'shared-stop',
            'name' => 'Gare Centre',
        ]);
        $this->assertDatabaseHas('trips', [
            'feed_id' => 'fixture',
            'trip_headsign' => 'Hôtel de Ville',
        ]);
        $this->assertDatabaseHas('stop_times', [
            'feed_id' => 'fixture',
            'arrival_time' => '25:05:00',
            'arrival_seconds' => 90300,
        ]);
        $this->assertDatabaseHas('transfers', [
            'feed_id' => 'fixture',
            'from_feed_id' => 'fixture',
            'to_feed_id' => 'fixture',
            'source' => 'gtfs',
        ]);
        $this->assertDatabaseCount('agencies', 1);
        $this->assertDatabaseCount('shapes', 2);
    }

    public function test_calendar_dates_import_is_idempotent(): void
    {
        config(['mobility.data_path' => base_path('tests/Fixtures/gtfs/heterogeneous')]);

        Artisan::call('mobility:import-gtfs');
        Artisan::call('mobility:import-gtfs');

        $this->assertDatabaseCount('calendar_dates', 2);
        $this->assertDatabaseHas('calendar_dates', [
            'feed_id' => 'fixture',
            'service_id' => 'service-1',
            'date' => '2026-10-21',
            'exception_type' => 2,
        ]);
        $this->assertDatabaseHas('calendar_dates', [
            'feed_id' => 'fixture',
            'service_id' => 'service-1',
            'date' => '2026-10-22',
            'exception_type' => 1,
        ]);
    }

    public function test_force_import_clears_previous_calendar_dates(): void
    {
        config(['mobility.data_path' => base_path('tests/Fixtures/gtfs/heterogeneous')]);

        Artisan::call('mobility:import-gtfs');
        Artisan::call('mobility:import-gtfs', ['--force' => true]);

        $this->assertDatabaseCount('calendar_dates', 2);
    }
}
