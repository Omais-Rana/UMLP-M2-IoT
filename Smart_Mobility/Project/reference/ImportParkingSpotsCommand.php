<?php

namespace App\Console\Commands;

use App\Models\ParkingSpot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportParkingSpotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-parking-spots';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import public parking spots from OpenStreetMap using Overpass API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting parking spots import from Overpass API...');

        // Calculate bounding box for all stops in the DB to fetch parking spots in our active regions
        $stats = DB::table('stops')->selectRaw('MIN(stop_lat) as min_lat, MAX(stop_lat) as max_lat, MIN(stop_lon) as min_lon, MAX(stop_lon) as max_lon')->first();

        if (! $stats || ! $stats->min_lat) {
            $this->error('No stops found to calculate bounding box.');

            return;
        }

        // Add a small buffer around the bounding box (e.g. 0.05 degrees ~ 5km)
        $buffer = 0.05;
        $minLat = $stats->min_lat - $buffer;
        $maxLat = $stats->max_lat + $buffer;
        $minLon = $stats->min_lon - $buffer;
        $maxLon = $stats->max_lon + $buffer;

        $this->info("Calculated Bounding Box: [$minLat, $minLon, $maxLat, $maxLon]");

        // Overpass QL query to find all parking amenities
        $query = <<<QL
        [out:json][timeout:25];
        (
          node["amenity"="parking"]({$minLat},{$minLon},{$maxLat},{$maxLon});
          way["amenity"="parking"]({$minLat},{$minLon},{$maxLat},{$maxLon});
          relation["amenity"="parking"]({$minLat},{$minLon},{$maxLat},{$maxLon});
        );
        out center;
        QL;

        $this->info('Fetching data from Overpass API...');

        try {
            $response = Http::timeout(180)
                ->withUserAgent('TransitFinder/1.0 (contact@example.com)')
                ->get('https://overpass.kumi.systems/api/interpreter', [
                    'data' => $query,
                ]);

            if ($response->failed()) {
                throw new \Exception('API Error: '.$response->status().' - '.$response->body());
            }

            $data = $response->json();
        } catch (\Exception $e) {
            $this->error('Failed to fetch from Overpass API: '.$e->getMessage());
            $this->info('Falling back to mock parking spots for demonstration...');

            $mockSpots = [
                ['name' => 'Parking du Château (Belfort)', 'lat' => 47.6385, 'lon' => 6.8640, 'osm_id' => 'mock/1', 'capacity' => 150],
                ['name' => 'Parking de la Gare (Belfort)', 'lat' => 47.6343, 'lon' => 6.8533, 'osm_id' => 'mock/2', 'capacity' => 80],
                ['name' => 'Parking des Alliés (Montbéliard)', 'lat' => 47.5099, 'lon' => 6.7981, 'osm_id' => 'mock/3', 'capacity' => 200],
                ['name' => 'Parking Axone (Montbéliard)', 'lat' => 47.4983, 'lon' => 6.8041, 'osm_id' => 'mock/4', 'capacity' => 500],
                ['name' => 'Parking Centre (Héricourt)', 'lat' => 47.5772, 'lon' => 6.7645, 'osm_id' => 'mock/5', 'capacity' => 120],
            ];

            foreach ($mockSpots as $spot) {
                ParkingSpot::updateOrCreate(
                    ['osm_id' => $spot['osm_id']],
                    $spot
                );
            }
            $this->info('Successfully seeded mock parking spots!');

            return;
        }

        if (! isset($data['elements'])) {
            $this->error('Unexpected response format.');

            return;
        }

        $elements = $data['elements'];
        $count = 0;

        $bar = $this->output->createProgressBar(count($elements));
        $bar->start();

        foreach ($elements as $element) {
            // Overpass can return nodes (which have lat/lon directly) or ways/relations (which have center lat/lon)
            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lon = $element['lon'] ?? $element['center']['lon'] ?? null;

            if (! $lat || ! $lon) {
                continue;
            }

            $id = $element['type'].'/'.$element['id'];
            $tags = $element['tags'] ?? [];
            $name = $tags['name'] ?? 'Public Parking';
            $capacity = isset($tags['capacity']) && is_numeric($tags['capacity']) ? (int) $tags['capacity'] : null;

            // Optional: filter out private parking
            if (isset($tags['access']) && in_array($tags['access'], ['private', 'customers'])) {
                continue;
            }

            ParkingSpot::updateOrCreate(
                ['osm_id' => $id],
                [
                    'name' => $name,
                    'lat' => $lat,
                    'lon' => $lon,
                    'capacity' => $capacity,
                ]
            );
            $count++;
            $bar->advance();
        }

        $bar->finish();

        $this->newLine();
        $this->info("Successfully imported/updated $count parking spots!");
    }
}
