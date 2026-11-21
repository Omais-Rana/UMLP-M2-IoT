<?php

namespace App\Console\Commands;

use App\Models\BikeStation;
use App\Models\CandidatePoint;
use App\Models\CarPark;
use App\Models\Stop;
use App\Services\Routing\TransitiveClosure;
use App\Services\Routing\TravelTimeProvider;
use Illuminate\Console\Command;

class BuildStaticMatrix extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mobility:build-matrix';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Precompute the static travel time matrix between nodes using transitive closure';

    /**
     * Execute the console command.
     */
    public function handle(TravelTimeProvider $travelTime, TransitiveClosure $closureService): int
    {
        $this->info('Building static network topology nodes...');

        // Aggregate static nodes
        $nodes = [];

        // 1. Candidate points
        foreach (CandidatePoint::all() as $cp) {
            $nodes[] = [
                'type' => 'candidate',
                'id' => 'cp_'.$cp->id,
                'name' => $cp->name,
                'lat' => $cp->lat,
                'lon' => $cp->lon,
            ];
        }

        // 2. Car parks
        foreach (CarPark::all() as $park) {
            $nodes[] = [
                'type' => 'car_park',
                'id' => 'park_'.$park->id,
                'name' => $park->name,
                'lat' => $park->lat,
                'lon' => $park->lon,
            ];
        }

        // 3. Bike stations
        foreach (BikeStation::all() as $bs) {
            $nodes[] = [
                'type' => 'bike_station',
                'id' => 'bike_'.$bs->id,
                'name' => $bs->name,
                'lat' => $bs->lat,
                'lon' => $bs->lon,
            ];
        }

        // 4. Key transit stations / hubs
        $keyStops = Stop::query()
            ->where('location_type', '>', 0)
            ->orWhereNotNull('parent_station')
            ->get();

        foreach ($keyStops as $stop) {
            $nodes[] = [
                'type' => 'stop',
                'id' => 'stop_'.$stop->stop_id,
                'name' => $stop->name,
                'lat' => $stop->lat,
                'lon' => $stop->lon,
            ];
        }

        $n = count($nodes);
        $this->info("Collected {$n} static nodes. Initializing distance matrices (walk, bike, car)...");

        $modes = ['walk', 'bike', 'car'];
        $matrices = [];

        foreach ($modes as $mode) {
            $matrix = [];
            for ($i = 0; $i < $n; $i++) {
                $matrix[$i] = [];
                for ($j = 0; $j < $n; $j++) {
                    if ($i === $j) {
                        $matrix[$i][$j] = 0;

                        continue;
                    }

                    $distMeters = $travelTime->distanceMeters(
                        $nodes[$i]['lat'],
                        $nodes[$i]['lon'],
                        $nodes[$j]['lat'],
                        $nodes[$j]['lon']
                    );

                    // Thresholds for direct connectivity
                    $maxDirectMeters = match ($mode) {
                        'walk' => 1500, // 1.5 km max direct walking link in static graph
                        'bike' => 5000, // 5 km max direct biking link
                        'car' => 30000, // cars can travel across entire bounding box
                    };

                    if ($distMeters <= $maxDirectMeters) {
                        $durSec = $travelTime->durationSeconds(
                            $nodes[$i]['lat'],
                            $nodes[$i]['lon'],
                            $nodes[$j]['lat'],
                            $nodes[$j]['lon'],
                            $mode
                        );
                        $matrix[$i][$j] = $durSec;
                    } else {
                        $matrix[$i][$j] = -1;
                    }
                }
            }

            $this->line("Computing transitive closure for mode: {$mode}...");
            $matrices[$mode] = $closureService->compute($matrix);
        }

        $outputPath = config('mobility.data_path').'/static_matrix.json';
        file_put_contents($outputPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'nodes_count' => $n,
            'nodes' => $nodes,
            'matrices' => $matrices,
        ], JSON_PRETTY_PRINT));

        $this->info("Precomputed static matrix saved to {$outputPath}");

        return Command::SUCCESS;
    }
}
