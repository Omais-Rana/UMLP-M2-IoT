<?php

namespace App\Console\Commands;

use App\Models\CarPark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportCarParks extends Command
{
    protected $signature = 'mobility:import-car-parks {--url=https://overpass.kumi.systems/api/interpreter : Overpass endpoint}';

    protected $description = 'Import public car parks from OpenStreetMap using Overpass';

    public function handle(): int
    {
        $bounds = DB::table('stops')
            ->selectRaw('MIN(lat) AS min_lat, MAX(lat) AS max_lat, MIN(lon) AS min_lon, MAX(lon) AS max_lon')
            ->first();

        if ($bounds?->min_lat === null || $bounds?->max_lat === null) {
            $this->error('Cannot determine the parking import area because no stops are imported.');

            return self::FAILURE;
        }

        $buffer = 0.05;
        $minLat = (float) $bounds->min_lat - $buffer;
        $maxLat = (float) $bounds->max_lat + $buffer;
        $minLon = (float) $bounds->min_lon - $buffer;
        $maxLon = (float) $bounds->max_lon + $buffer;
        $query = <<<OVERPASS
[out:json][timeout:60];
(
  node["amenity"="parking"]({$minLat},{$minLon},{$maxLat},{$maxLon});
  way["amenity"="parking"]({$minLat},{$minLon},{$maxLat},{$maxLon});
  relation["amenity"="parking"]({$minLat},{$minLon},{$maxLat},{$maxLon});
);
out center;
OVERPASS;

        try {
            $response = Http::timeout(180)
                ->withUserAgent('SmartMobility/1.0')
                ->get((string) $this->option('url'), ['data' => $query]);
        } catch (\Throwable $exception) {
            $this->error('Parking provider request failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Parking provider returned HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        $elements = $response->json('elements');
        if (! is_array($elements)) {
            $this->error('Parking provider returned an invalid response.');

            return self::FAILURE;
        }

        $imported = 0;
        foreach ($elements as $element) {
            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lon = $element['lon'] ?? $element['center']['lon'] ?? null;
            if (! is_numeric($lat) || ! is_numeric($lon)) {
                continue;
            }

            $tags = $element['tags'] ?? [];
            if (in_array($tags['access'] ?? null, ['private', 'customers'], true)) {
                continue;
            }

            $name = trim((string) ($tags['name'] ?? 'Public parking'));
            CarPark::updateOrCreate(
                ['name' => $name, 'lat' => (float) $lat, 'lon' => (float) $lon],
                [
                    'capacity' => is_numeric($tags['capacity'] ?? null) ? (int) $tags['capacity'] : 50,
                    'is_park_and_ride' => false,
                ]
            );
            $imported++;
        }

        $this->info("Imported or updated {$imported} public car parks.");

        return self::SUCCESS;
    }
}
