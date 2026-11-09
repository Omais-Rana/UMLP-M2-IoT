<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DemoScenarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artisan::call('mobility:import-gtfs');
        Artisan::call('mobility:import-gbfs');
        Artisan::call('mobility:build-transfers');
        Artisan::call('mobility:build-matrix');
    }

    /**
     * Provide standard 4-participant demo scenario data.
     *
     * @return array<string, mixed>
     */
    public static function getScenario(): array
    {
        return [
            'date' => '2026-10-27',
            'time' => '08:30',
            'time_type' => 'depart_at',
            'destination' => [
                'name' => 'UTBM Campus de Sevenans',
                'lat' => 47.5878,
                'lon' => 6.8665,
            ],
            'participants' => [
                [
                    'name' => 'Alice (Belfort Conductrice)',
                    'lat' => 47.6350,
                    'lon' => 6.8600,
                    'has_car' => true,
                ],
                [
                    'name' => 'Robin (Belfort)',
                    'lat' => 47.6450,
                    'lon' => 6.8400,
                    'has_car' => false,
                ],
                [
                    'name' => 'Camille (Montbéliard)',
                    'lat' => 47.5100,
                    'lon' => 6.7980,
                    'has_car' => false,
                ],
                [
                    'name' => 'Lucas (Montbéliard Axone)',
                    'lat' => 47.5020,
                    'lon' => 6.8220,
                    'has_car' => false,
                ],
            ],
        ];
    }
}
