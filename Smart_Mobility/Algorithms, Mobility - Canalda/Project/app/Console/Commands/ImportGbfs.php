<?php

namespace App\Console\Commands;

use App\Models\BikeStation;
use App\Models\CarShareStation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ImportGbfs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mobility:import-gbfs {--force : Overwrite existing stations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import GBFS data for Optymo shared bikes and Autopartage cars';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting GBFS Import from Optymo feeds...');

        $this->importBikeStations();
        $this->importCarShareStations();

        $this->info('GBFS Import completed successfully.');

        return Command::SUCCESS;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveGbfsFeedUrls(string $rootUrl): array
    {
        $infoUrl = null;
        $statusUrl = null;

        try {
            $resp = Http::withHeaders(['User-Agent' => 'SmartMobility/1.0'])
                ->timeout(10)
                ->get($rootUrl);

            if ($resp->successful()) {
                $json = $resp->json();
                $feeds = $json['data']['en']['feeds'] ?? $json['data']['fr']['feeds'] ?? [];
                if (empty($feeds) && isset($json['data']) && is_array($json['data'])) {
                    $firstLang = array_values($json['data'])[0] ?? [];
                    $feeds = $firstLang['feeds'] ?? [];
                }

                foreach ($feeds as $feed) {
                    if (($feed['name'] ?? '') === 'station_information') {
                        $infoUrl = $feed['url'] ?? null;
                    }
                    if (($feed['name'] ?? '') === 'station_status') {
                        $statusUrl = $feed['url'] ?? null;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn("Error discovering GBFS feeds from {$rootUrl}: {$e->getMessage()}");
        }

        return [$infoUrl, $statusUrl];
    }

    /**
     * Fetch a live status feed without allowing a failed request to replace
     * static station information or the last known status.
     *
     * @return array<string, array<string, mixed>>|null
     */
    protected function fetchCachedStatuses(?string $url, string $network): ?array
    {
        if (! $url) {
            return null;
        }

        $result = Cache::remember(
            'gbfs:'.$network.':status:'.sha1($url),
            (int) config('mobility.gbfs.status_cache_ttl', 60),
            function () use ($url): array {
                try {
                    $response = Http::withHeaders(['User-Agent' => 'SmartMobility/1.0'])
                        ->connectTimeout(3)
                        ->timeout(10)
                        ->get($url);

                    if (! $response->successful()) {
                        return ['ok' => false, 'statuses' => []];
                    }

                    $statusMap = [];
                    foreach ($response->json('data.stations', []) as $station) {
                        if (! empty($station['station_id'])) {
                            $statusMap[$station['station_id']] = $station;
                        }
                    }

                    return ['ok' => true, 'statuses' => $statusMap];
                } catch (\Throwable $exception) {
                    return ['ok' => false, 'statuses' => []];
                }
            }
        );

        if (! ($result['ok'] ?? false)) {
            $this->warn("Failed to fetch {$network} status feed; preserving stored status.");

            return null;
        }

        $this->info('Fetched '.count($result['statuses'])." live {$network} station statuses.");

        return $result['statuses'];
    }

    protected function importBikeStations(): void
    {
        $this->line('Importing Bike Stations (Optymo VLS)...');
        $rootUrl = config('mobility.gbfs.bike_gbfs_url');
        [$discoveredInfoUrl, $discoveredStatusUrl] = $rootUrl ? $this->resolveGbfsFeedUrls($rootUrl) : [null, null];

        $infoUrl = $discoveredInfoUrl ?: config('mobility.gbfs.bike_station_information_url');
        $statusUrl = $discoveredStatusUrl ?: config('mobility.gbfs.bike_station_status_url');

        $stations = [];
        $statusMap = [];

        if ($infoUrl) {
            try {
                $resp = Http::withHeaders(['User-Agent' => 'SmartMobility/1.0'])
                    ->timeout(10)
                    ->get($infoUrl);

                if ($resp->successful()) {
                    $json = $resp->json();
                    $stations = $json['data']['stations'] ?? [];
                    $this->info('Fetched '.count($stations).' bike stations from Optymo info feed.');
                }
            } catch (\Throwable $e) {
                $this->warn("Failed to fetch bike info feed: {$e->getMessage()}");
            }
        }

        $statusMap = $this->fetchCachedStatuses($statusUrl, 'bike') ?? [];

        // Fallback to snapshot file if empty
        if (empty($stations)) {
            $filePath = config('mobility.data_path').'/bike_stations.json';
            if (file_exists($filePath)) {
                $stations = json_decode(file_get_contents($filePath), true) ?? [];
                $this->info('Loaded '.count($stations).' bike stations from local snapshot.');
            }
        }

        foreach ($stations as $item) {
            $stationId = $item['station_id'] ?? null;
            if (! $stationId) {
                continue;
            }

            $liveStatus = $statusMap[$stationId] ?? [];
            $existing = BikeStation::query()->where('station_id', $stationId)->first();

            $numBikes = (int) ($liveStatus['num_bikes_available'] ?? $item['num_bikes_available'] ?? $existing?->num_bikes_available ?? 5);
            $numDocks = (int) ($liveStatus['num_docks_available'] ?? $item['num_docks_available'] ?? $existing?->num_docks_available ?? 5);
            $capacity = (int) ($item['capacity'] ?? ($numBikes + $numDocks));

            BikeStation::updateOrCreate(
                ['station_id' => $stationId],
                [
                    'name' => trim($item['name'] ?? 'Station '.$stationId),
                    'lat' => (float) ($item['lat'] ?? $item['latitude'] ?? 0.0),
                    'lon' => (float) ($item['lon'] ?? $item['longitude'] ?? 0.0),
                    'capacity' => $capacity > 0 ? $capacity : 15,
                    'num_bikes_available' => $numBikes,
                    'num_docks_available' => $numDocks,
                    'is_installed' => (bool) ($liveStatus['is_installed'] ?? $item['is_installed'] ?? $existing?->is_installed ?? true),
                    'is_renting' => (bool) ($liveStatus['is_renting'] ?? $item['is_renting'] ?? $existing?->is_renting ?? true),
                    'is_returning' => (bool) ($liveStatus['is_returning'] ?? $item['is_returning'] ?? $existing?->is_returning ?? true),
                ]
            );
        }

        $this->info('Bike stations count in DB: '.BikeStation::count());
    }

    protected function importCarShareStations(): void
    {
        $this->line('Importing Car-Share (Optymo ALS / Autopartage) Stations...');
        $rootUrl = config('mobility.gbfs.car_gbfs_url');
        [$discoveredInfoUrl, $discoveredStatusUrl] = $rootUrl ? $this->resolveGbfsFeedUrls($rootUrl) : [null, null];

        $infoUrl = $discoveredInfoUrl ?: config('mobility.gbfs.car_station_information_url');
        $statusUrl = $discoveredStatusUrl ?: config('mobility.gbfs.car_station_status_url');

        $stations = [];
        $statusMap = [];

        if ($infoUrl) {
            try {
                $resp = Http::withHeaders(['User-Agent' => 'SmartMobility/1.0'])
                    ->timeout(10)
                    ->get($infoUrl);

                if ($resp->successful()) {
                    $json = $resp->json();
                    $stations = $json['data']['stations'] ?? [];
                    $this->info('Fetched '.count($stations).' car-share stations from Optymo info feed.');
                }
            } catch (\Throwable $e) {
                $this->warn("Failed to fetch car-share info feed: {$e->getMessage()}");
            }
        }

        $statusMap = $this->fetchCachedStatuses($statusUrl, 'car') ?? [];

        // Fallback to snapshot file if empty
        if (empty($stations)) {
            $filePath = config('mobility.data_path').'/car_share_stations.json';
            if (file_exists($filePath)) {
                $stations = json_decode(file_get_contents($filePath), true) ?? [];
                $this->info('Loaded '.count($stations).' car-share stations from local snapshot.');
            }
        }

        foreach ($stations as $item) {
            $stationId = $item['station_id'] ?? null;
            if (! $stationId) {
                continue;
            }

            $liveStatus = $statusMap[$stationId] ?? [];
            $existing = CarShareStation::query()->where('station_id', $stationId)->first();
            $numCars = (int) ($liveStatus['num_cars_available'] ?? $liveStatus['num_vehicles_available'] ?? $liveStatus['num_bikes_available'] ?? $item['num_cars_available'] ?? $existing?->num_cars_available ?? 2);

            CarShareStation::updateOrCreate(
                ['station_id' => $stationId],
                [
                    'name' => trim($item['name'] ?? $item['StationName'] ?? 'Autopartage '.$stationId),
                    'lat' => (float) ($item['lat'] ?? $item['latitude'] ?? 0.0),
                    'lon' => (float) ($item['lon'] ?? $item['longitude'] ?? 0.0),
                    'num_cars_available' => $numCars,
                    'car_types' => $item['car_types'] ?? ['Citadine Électrique'],
                ]
            );
        }

        $this->info('Car-share stations count in DB: '.CarShareStation::count());
    }
}
