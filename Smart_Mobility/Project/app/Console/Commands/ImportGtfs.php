<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Calendar;
use App\Models\CandidatePoint;
use App\Models\CarPark;
use App\Models\Route;
use App\Models\Shape;
use App\Models\Stop;
use App\Models\StopTime;
use App\Models\TransportFeed;
use App\Models\Trip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class ImportGtfs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mobility:import-gtfs {--force : Clear existing timetable data before import}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import GTFS timetable feeds for Optymo, évolitY, and TER regional trains';

    /**
     * Parse a CSV header row, stripping UTF-8 BOM and trimming all values.
     *
     * @param  array<int, string>  $header
     * @return array<int, string>
     */
    protected function parseCsvHeader(array $header): array
    {
        $header = array_map('trim', $header);

        // Strip UTF-8 BOM from the first column if present
        if (! empty($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        return $header;
    }

    protected function parseGtfsBoolean(?string $value, bool $default = false): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    protected function parseGtfsDate(?string $value, ?string $default): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{8}$/', $value) === 1) {
            return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
        }

        return $value !== '' ? $value : $default;
    }

    protected function parseGtfsTime(?string $value): ?int
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
    }

    protected function normalizeText(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (
            ! mb_check_encoding($value, 'UTF-8')
            || str_contains($value, 'Ã')
            || str_contains($value, 'Â')
            || str_contains($value, 'â')
            || str_contains($value, '�')
        ) {
            $converted = ! mb_check_encoding($value, 'UTF-8')
                ? @iconv('Windows-1252', 'UTF-8//IGNORE', $value)
                : @mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
            if ($converted !== false && ! str_contains($converted, '�')) {
                $value = $converted;
            }
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $clean === false ? $value : $clean;
    }

    protected function importFeedMetadata(string $dir, string $feedId): void
    {
        $info = $this->readFirstCsvRow($dir.'/feed_info.txt');
        $feed = [
            'feed_id' => $feedId,
            'feed_name' => $this->normalizeText($info['feed_publisher_name'] ?? null) ?: $feedId,
            'publisher_name' => $this->normalizeText($info['feed_publisher_name'] ?? null) ?: null,
            'publisher_url' => trim((string) ($info['feed_publisher_url'] ?? '')) ?: null,
            'version' => $this->normalizeText($info['feed_version'] ?? null) ?: null,
            'feed_start_date' => $this->parseGtfsDate($info['feed_start_date'] ?? null, null),
            'feed_end_date' => $this->parseGtfsDate($info['feed_end_date'] ?? null, null),
            'source_type' => 'bundled',
            'encoding' => 'UTF-8',
            'updated_at' => now(),
        ];

        TransportFeed::updateOrCreate(['feed_id' => $feedId], $feed);

        $this->importAgencies($dir.'/agency.txt', $feedId);
        $this->importShapes($dir.'/shapes.txt', $feedId);
        $this->importSourceTransfers($dir.'/transfers.txt', $feedId);
    }

    /**
     * @return array<string, string>
     */
    protected function readFirstCsvRow(string $path): array
    {
        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            return [];
        }

        $header = fgetcsv($handle);
        $row = $header ? fgetcsv($handle) : false;
        fclose($handle);

        if (! is_array($header) || ! is_array($row)) {
            return [];
        }

        $header = $this->parseCsvHeader($header);
        $result = [];
        foreach ($header as $index => $name) {
            $result[$name] = trim((string) ($row[$index] ?? ''));
        }

        return $result;
    }

    protected function importAgencies(string $path, string $feedId): void
    {
        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            return;
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                $row = array_slice(array_pad($row, count($header), ''), 0, count($header));
            }
            $values = array_combine($header, $row);
            if (! is_array($values) || trim((string) ($values['agency_id'] ?? '')) === '') {
                continue;
            }

            $records[] = [
                'feed_id' => $feedId,
                'agency_id' => trim($values['agency_id']),
                'name' => $this->normalizeText($values['agency_name'] ?? ''),
                'url' => trim((string) ($values['agency_url'] ?? '')) ?: null,
                'timezone' => trim((string) ($values['agency_timezone'] ?? '')) ?: null,
                'language' => trim((string) ($values['agency_lang'] ?? '')) ?: null,
                'phone' => $this->normalizeText($values['agency_phone'] ?? null) ?: null,
                'fare_url' => trim((string) ($values['agency_fare_url'] ?? '')) ?: null,
                'email' => trim((string) ($values['agency_email'] ?? '')) ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        fclose($handle);

        if ($records !== []) {
            DB::table('agencies')->upsert($records, ['feed_id', 'agency_id'], ['name', 'url', 'timezone', 'language', 'phone', 'fare_url', 'email', 'updated_at']);
            $firstAgencyName = $records[0]['name'];
            if ($firstAgencyName !== '') {
                TransportFeed::where('feed_id', $feedId)->update(['feed_name' => $firstAgencyName]);
            }
        }
    }

    protected function importShapes(string $path, string $feedId): void
    {
        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            return;
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                $row = array_slice(array_pad($row, count($header), ''), 0, count($header));
            }
            $values = array_combine($header, $row);
            if (! is_array($values) || trim((string) ($values['shape_id'] ?? '')) === '') {
                continue;
            }

            $lat = filter_var($values['shape_pt_lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($values['shape_pt_lon'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lon === false) {
                continue;
            }

            $records[] = [
                'feed_id' => $feedId,
                'shape_id' => trim($values['shape_id']),
                'shape_pt_sequence' => (int) ($values['shape_pt_sequence'] ?? 0),
                'lat' => $lat,
                'lon' => $lon,
                'distance_traveled' => ($values['shape_dist_traveled'] ?? '') !== '' ? (float) $values['shape_dist_traveled'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($records) >= 1000) {
                DB::table('shapes')->upsert($records, ['feed_id', 'shape_id', 'shape_pt_sequence'], ['lat', 'lon', 'distance_traveled', 'updated_at']);
                $records = [];
            }
        }
        fclose($handle);

        if ($records !== []) {
            DB::table('shapes')->upsert($records, ['feed_id', 'shape_id', 'shape_pt_sequence'], ['lat', 'lon', 'distance_traveled', 'updated_at']);
        }
    }

    protected function importSourceTransfers(string $path, string $feedId): void
    {
        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            return;
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                $row = array_slice(array_pad($row, count($header), ''), 0, count($header));
            }
            $values = array_combine($header, $row);
            $from = trim((string) ($values['from_stop_id'] ?? ''));
            $to = trim((string) ($values['to_stop_id'] ?? ''));
            if (! is_array($values) || $from === '' || $to === '') {
                continue;
            }

            $records[] = [
                'feed_id' => $feedId,
                'from_stop_id' => $from,
                'from_feed_id' => $feedId,
                'to_stop_id' => $to,
                'to_feed_id' => $feedId,
                'transfer_type' => (int) ($values['transfer_type'] ?? 0),
                'source' => 'gtfs',
                'min_transfer_time' => (int) ($values['min_transfer_time'] ?? 0),
                'distance_m' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        fclose($handle);

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('transfers')->upsert(
                $chunk,
                ['feed_id', 'from_feed_id', 'from_stop_id', 'to_feed_id', 'to_stop_id', 'transfer_type', 'source'],
                ['min_transfer_time', 'distance_m', 'updated_at']
            );
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting GTFS Timetable Import...');

        $dataPath = config('mobility.data_path');

        if ($this->option('force')) {
            $this->line('Clearing existing GTFS data...');
            DB::table('stop_times')->truncate();
            DB::table('trips')->truncate();
            DB::table('calendar_dates')->truncate();
            DB::table('calendar')->truncate();
            DB::table('agencies')->truncate();
            DB::table('shapes')->truncate();
            DB::table('transfers')->where('source', 'gtfs')->delete();
            DB::table('transport_feeds')->truncate();
            DB::table('routes')->truncate();
            DB::table('stops')->truncate();
        } else {
            $this->line('Updating existing GTFS data without clearing unrelated records...');
        }

        $this->importCandidatesAndCarParks($dataPath);

        // Check for operator subdirectories with real GTFS data
        $operatorsPath = $dataPath.'/Operators';
        $hasOperatorData = false;

        if (is_dir($operatorsPath)) {
            $operators = array_filter(glob($operatorsPath.'/*'), 'is_dir');
            if (! empty($operators)) {
                $hasOperatorData = true;
                $this->info('Found '.count($operators).' operator(s) in '.$operatorsPath);
                foreach ($operators as $opDir) {
                    $feedName = basename($opDir);
                    if (file_exists($opDir.'/stops.txt')) {
                        $this->importOperatorFeed($opDir, $feedName);
                    } else {
                        $this->warn("Skipping {$feedName}: no stops.txt found.");
                    }
                }
            }
        }

        if (! $hasOperatorData) {
            // Fallback: Check for any zip files in the data directory
            $zipFiles = glob($dataPath.'/*.zip') ?: [];

            if (! empty($zipFiles)) {
                $this->info('Found '.count($zipFiles).' GTFS zip file(s) in '.$dataPath);
                foreach ($zipFiles as $zipPath) {
                    $feedName = pathinfo($zipPath, PATHINFO_FILENAME);
                    $this->importZipFeed($zipPath, $feedName);
                }
            } else {
                $this->warn('No GTFS data found in Operators/ or as .zip files. Seeding baseline schedules...');
                $this->seedBaselineGtfs();
            }
        }

        // Cache feed metadata
        $minDate = collect([
            Calendar::min('start_date'),
            DB::table('calendar_dates')->min('date'),
        ])->filter()->min() ?? '2026-01-01';
        $maxDate = collect([
            Calendar::max('end_date'),
            DB::table('calendar_dates')->max('date'),
        ])->filter()->max() ?? '2026-12-31';

        Cache::forever('gtfs_meta', [
            'snapshot_date' => date('Y-m-d'),
            'valid_from' => $minDate,
            'valid_until' => $maxDate,
            'stops_count' => Stop::count(),
            'trips_count' => Trip::count(),
            'routes_count' => Route::count(),
        ]);

        $this->info('GTFS Import complete. Stops: '.Stop::count().', Routes: '.Route::count().', Trips: '.Trip::count().', StopTimes: '.StopTime::count());
        $this->info("Feeds valid between {$minDate} and {$maxDate}.");

        return Command::SUCCESS;
    }

    protected function importCandidatesAndCarParks(string $dataPath): void
    {
        $candFile = $dataPath.'/candidates.json';
        if (file_exists($candFile)) {
            $candidates = json_decode(file_get_contents($candFile), true) ?? [];
            foreach ($candidates as $c) {
                CandidatePoint::updateOrCreate(
                    ['name' => $c['name']],
                    [
                        'lat' => (float) $c['lat'],
                        'lon' => (float) $c['lon'],
                        'car_accessible' => (bool) ($c['car_accessible'] ?? true),
                        'has_parking' => (bool) ($c['has_parking'] ?? true),
                        'transit_hub' => (bool) ($c['transit_hub'] ?? false),
                        'description' => $c['description'] ?? null,
                    ]
                );
            }
            $this->info('Candidate points imported: '.CandidatePoint::count());
        }

        $parkFile = $dataPath.'/car_parks.json';
        if (file_exists($parkFile)) {
            $parks = json_decode(file_get_contents($parkFile), true) ?? [];
            foreach ($parks as $p) {
                CarPark::updateOrCreate(
                    ['name' => $p['name']],
                    [
                        'lat' => (float) $p['lat'],
                        'lon' => (float) $p['lon'],
                        'near_stop_id' => $p['near_stop_id'] ?? null,
                        'capacity' => (int) ($p['capacity'] ?? 50),
                        'is_park_and_ride' => (bool) ($p['is_park_and_ride'] ?? true),
                    ]
                );
            }
            $this->info('Car parks imported: '.CarPark::count());
        }
    }

    protected function importZipFeed(string $zipPath, string $feedId): void
    {
        $this->line("Processing zip feed: {$feedId} ({$zipPath})");

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error("Could not open zip archive: {$zipPath}");

            return;
        }

        $extractPath = sys_get_temp_dir().'/gtfs_'.uniqid($feedId.'_', true);
        if (! mkdir($extractPath, 0777, true) && ! is_dir($extractPath)) {
            $this->error('Failed to create temporary extraction directory.');

            return;
        }

        $zip->extractTo($extractPath);
        $zip->close();

        $this->parseExtractedDirectory($extractPath, $feedId);

        // Cleanup
        $files = glob($extractPath.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($extractPath);
    }

    protected function parseExtractedDirectory(string $dir, string $feedId): void
    {
        $this->importFeedMetadata($dir, $feedId);

        // 1. stops.txt
        if (file_exists($dir.'/stops.txt')) {
            $this->importStopsCsv($dir.'/stops.txt', $feedId);
        }

        // 2. routes.txt
        if (file_exists($dir.'/routes.txt')) {
            $this->importRoutesCsv($dir.'/routes.txt', $feedId);
        }

        // 3. calendar.txt
        if (file_exists($dir.'/calendar.txt')) {
            $this->importCalendarCsv($dir.'/calendar.txt', $feedId);
        }

        // 4. calendar_dates.txt
        if (file_exists($dir.'/calendar_dates.txt')) {
            $this->importCalendarDatesCsv($dir.'/calendar_dates.txt', $feedId);
        }

        // 5. trips.txt
        if (file_exists($dir.'/trips.txt')) {
            $this->importTripsCsv($dir.'/trips.txt', $feedId);
        }

        // 6. stop_times.txt
        if (file_exists($dir.'/stop_times.txt')) {
            $this->importStopTimesCsv($dir.'/stop_times.txt', $feedId);
        }
    }

    /**
     * Import a real GTFS operator feed from an extracted directory.
     *
     * For large national feeds (sncf), filters to the bounding box first
     * to avoid importing millions of irrelevant records.
     */
    protected function importOperatorFeed(string $dir, string $feedId): void
    {
        $this->info("Importing operator feed: {$feedId} from {$dir}");
        $bbox = config('mobility.bounding_box');
        $isLargeFeed = in_array(strtolower($feedId), ['sncf', 'ter', 'tgv']);
        $stopFileSize = file_exists($dir.'/stops.txt') ? filesize($dir.'/stops.txt') : 0;

        // For large national feeds or files > 500KB stops, apply bounding box filter
        if ($isLargeFeed || $stopFileSize > 500000) {
            $this->info("Large feed detected ({$feedId}), applying bounding box filter...");
            $this->importOperatorFeedFiltered($dir, $feedId, $bbox);
        } else {
            // Small local feed — import everything directly
            $this->parseExtractedDirectory($dir, $feedId);
        }

        TransportFeed::where('feed_id', $feedId)->update([
            'agency_count' => Agency::where('feed_id', $feedId)->count(),
            'stop_count' => Stop::where('feed_id', $feedId)->count(),
            'route_count' => Route::where('feed_id', $feedId)->count(),
            'trip_count' => Trip::where('feed_id', $feedId)->count(),
            'stop_time_count' => StopTime::where('feed_id', $feedId)->count(),
            'shape_point_count' => Shape::where('feed_id', $feedId)->count(),
            'transfer_count' => DB::table('transfers')->where('feed_id', $feedId)->where('source', 'gtfs')->count(),
            'imported_at' => now(),
        ]);

        $this->info("{$feedId}: imported. Stops: ".Stop::where('feed_id', $feedId)->count().', Trips: '.Trip::where('feed_id', $feedId)->count());
    }

    /**
     * Import a large GTFS feed filtered to only stops within the bounding box
     * and only trips/stop_times that serve those stops.
     *
     * @param  array{min_lat: float, max_lat: float, min_lon: float, max_lon: float}  $bbox
     */
    protected function importOperatorFeedFiltered(string $dir, string $feedId, array $bbox): void
    {
        $this->importFeedMetadata($dir, $feedId);

        $margin = 0.05;
        $minLat = $bbox['min_lat'] - $margin;
        $maxLat = $bbox['max_lat'] + $margin;
        $minLon = $bbox['min_lon'] - $margin;
        $maxLon = $bbox['max_lon'] + $margin;

        // Phase 1: Import stops within bounding box
        $validStopIds = [];
        $parentIds = [];

        if (file_exists($dir.'/stops.txt')) {
            $handle = fopen($dir.'/stops.txt', 'r');
            $header = fgetcsv($handle);
            if ($header) {
                $header = $this->parseCsvHeader($header);
                $stopIdIdx = array_search('stop_id', $header, true);
                $nameIdx = array_search('stop_name', $header, true);
                $latIdx = array_search('stop_lat', $header, true);
                $lonIdx = array_search('stop_lon', $header, true);
                $locTypeIdx = array_search('location_type', $header, true);
                $parentIdx = array_search('parent_station', $header, true);

                $records = [];
                while (($row = fgetcsv($handle)) !== false) {
                    if ($stopIdIdx === false || ! isset($row[$stopIdIdx])) {
                        continue;
                    }

                    $lat = (float) ($row[$latIdx] ?? 0);
                    $lon = (float) ($row[$lonIdx] ?? 0);
                    $locType = $locTypeIdx !== false ? (int) ($row[$locTypeIdx] ?? 0) : 0;

                    // Parent stations (location_type=1) have no coordinates usually
                    if ($locType === 1) {
                        $parentIds[trim($row[$stopIdIdx])] = $row;

                        continue;
                    }

                    // Filter by bounding box
                    if ($lat < $minLat || $lat > $maxLat || $lon < $minLon || $lon > $maxLon) {
                        continue;
                    }

                    $stopId = trim($row[$stopIdIdx]);
                    $validStopIds[$stopId] = true;
                    if ($parentIdx !== false && ! empty(trim($row[$parentIdx] ?? ''))) {
                        $validStopIds[trim($row[$parentIdx])] = true;
                    }

                    $records[] = [
                        'feed_id' => $feedId,
                        'stop_id' => $stopId,
                        'name' => $this->normalizeText($row[$nameIdx] ?? $stopId),
                        'lat' => $lat,
                        'lon' => $lon,
                        'location_type' => $locType,
                        'parent_station' => $parentIdx !== false ? trim($row[$parentIdx] ?? '') : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($records) >= 500) {
                        DB::table('stops')->upsert($records, ['feed_id', 'stop_id'], ['name', 'lat', 'lon', 'updated_at']);
                        $records = [];
                    }
                }

                if (! empty($records)) {
                    DB::table('stops')->upsert($records, ['feed_id', 'stop_id'], ['name', 'lat', 'lon', 'updated_at']);
                }
            }
            fclose($handle);
        }

        // Also import referenced parent stations (location_type=1)
        $parentRecords = [];
        if (! empty($parentIds) && file_exists($dir.'/stops.txt')) {
            // Re-read header to get column indices
            $ph = fopen($dir.'/stops.txt', 'r');
            $pHeader = $this->parseCsvHeader(fgetcsv($ph) ?: []);
            fclose($ph);
            $pNameIdx = array_search('stop_name', $pHeader, true);

            foreach ($parentIds as $pid => $row) {
                if (isset($validStopIds[$pid])) {
                    $parentRecords[] = [
                        'feed_id' => $feedId,
                        'stop_id' => $pid,
                        'name' => $pNameIdx !== false ? $this->normalizeText($row[$pNameIdx] ?? $pid) : $pid,
                        'lat' => 0,
                        'lon' => 0,
                        'location_type' => 1,
                        'parent_station' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (! empty($parentRecords)) {
                DB::table('stops')->upsert($parentRecords, ['feed_id', 'stop_id'], ['name', 'location_type', 'updated_at']);
                $this->line("{$feedId}: imported ".count($parentRecords).' parent stations.');
            }
        }

        $this->info("{$feedId}: ".count($validStopIds).' stops within bounding box.');
        if (empty($validStopIds)) {
            $this->warn("{$feedId}: No stops in bounding box. Skipping trips & stop_times.");

            return;
        }

        // Import calendar data before selecting the regional trips.
        if (file_exists($dir.'/calendar.txt')) {
            $this->importCalendarCsv($dir.'/calendar.txt', $feedId);
        }

        if (file_exists($dir.'/calendar_dates.txt')) {
            $this->importCalendarDatesCsv($dir.'/calendar_dates.txt', $feedId);
        }

        // Scan stop_times to find trips that serve our stops.
        $validTripIds = [];
        if (file_exists($dir.'/stop_times.txt')) {
            $this->line("{$feedId}: scanning stop_times for trips serving regional stops...");
            $handle = fopen($dir.'/stop_times.txt', 'r');
            $header = fgetcsv($handle);
            if ($header) {
                $header = $this->parseCsvHeader($header);
                $tripIdIdx = array_search('trip_id', $header, true);
                $stopIdIdx = array_search('stop_id', $header, true);

                $lineNum = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    $lineNum++;
                    if ($lineNum % 500000 === 0) {
                        $this->line("  ... scanned {$lineNum} stop_time rows, found ".count($validTripIds).' trips so far');
                    }

                    if ($tripIdIdx === false || ! isset($row[$tripIdIdx])) {
                        continue;
                    }

                    $stopId = trim($row[$stopIdIdx] ?? '');
                    if (isset($validStopIds[$stopId])) {
                        $validTripIds[trim($row[$tripIdIdx])] = true;
                    }
                }
            }
            fclose($handle);
            $this->info("{$feedId}: found ".count($validTripIds)." trips serving regional stops (scanned {$lineNum} rows).");
        }

        // Import only relevant trips and remember their routes.
        if (file_exists($dir.'/trips.txt') && ! empty($validTripIds)) {
            $handle = fopen($dir.'/trips.txt', 'r');
            $header = fgetcsv($handle);
            if ($header) {
                $header = $this->parseCsvHeader($header);
                $routeIdIdx = array_search('route_id', $header, true);
                $serviceIdIdx = array_search('service_id', $header, true);
                $tripIdIdx = array_search('trip_id', $header, true);
                $headsignIdx = array_search('trip_headsign', $header, true);
                $dirIdx = array_search('direction_id', $header, true);

                $records = [];
                $validRouteIds = [];
                while (($row = fgetcsv($handle)) !== false) {
                    if ($tripIdIdx === false || ! isset($row[$tripIdIdx])) {
                        continue;
                    }

                    $tripId = trim($row[$tripIdIdx]);
                    if (! isset($validTripIds[$tripId])) {
                        continue;
                    }

                    $routeId = trim($row[$routeIdIdx] ?? '');
                    if ($routeId !== '') {
                        $validRouteIds[$routeId] = true;
                    }

                    $records[] = [
                        'feed_id' => $feedId,
                        'trip_id' => $tripId,
                        'route_id' => $routeId,
                        'service_id' => trim($row[$serviceIdIdx] ?? ''),
                        'trip_headsign' => $headsignIdx !== false ? $this->normalizeText($row[$headsignIdx] ?? '') : null,
                        'direction_id' => $dirIdx !== false ? (int) ($row[$dirIdx] ?? 0) : 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($records) >= 500) {
                        DB::table('trips')->upsert($records, ['feed_id', 'trip_id'], ['route_id', 'service_id', 'trip_headsign', 'direction_id', 'updated_at']);
                        $records = [];
                    }
                }

                if (! empty($records)) {
                    DB::table('trips')->upsert($records, ['feed_id', 'trip_id'], ['route_id', 'service_id', 'trip_headsign', 'direction_id', 'updated_at']);
                }
            }
            fclose($handle);

            if (! empty($validRouteIds) && file_exists($dir.'/routes.txt')) {
                $this->importRoutesCsv($dir.'/routes.txt', $feedId, $validRouteIds);
            }
        }

        // Import only stop_times for valid trips.
        if (file_exists($dir.'/stop_times.txt') && ! empty($validTripIds)) {
            $this->line("{$feedId}: importing stop_times for ".count($validTripIds).' regional trips...');
            $handle = fopen($dir.'/stop_times.txt', 'r');
            $header = fgetcsv($handle);
            if ($header) {
                $header = $this->parseCsvHeader($header);
                $tripIdIdx = array_search('trip_id', $header, true);
                $arrIdx = array_search('arrival_time', $header, true);
                $depIdx = array_search('departure_time', $header, true);
                $stopIdIdx = array_search('stop_id', $header, true);
                $seqIdx = array_search('stop_sequence', $header, true);
                $pickupIdx = array_search('pickup_type', $header, true);
                $dropOffIdx = array_search('drop_off_type', $header, true);

                $records = [];
                $imported = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    if (
                        $tripIdIdx === false
                        || $arrIdx === false
                        || $depIdx === false
                        || $stopIdIdx === false
                        || $seqIdx === false
                        || ! isset($row[$tripIdIdx], $row[$stopIdIdx], $row[$seqIdx])
                    ) {
                        continue;
                    }

                    $tripId = trim($row[$tripIdIdx]);
                    if (! isset($validTripIds[$tripId])) {
                        continue;
                    }

                    $arrStr = trim($row[$arrIdx]);
                    $depStr = trim($row[$depIdx]);
                    $arrSec = $this->parseGtfsTime($arrStr);
                    $depSec = $this->parseGtfsTime($depStr);

                    if ($arrSec === null || $depSec === null || trim($row[$stopIdIdx]) === '') {
                        continue;
                    }

                    $records[] = [
                        'feed_id' => $feedId,
                        'trip_id' => $tripId,
                        'stop_id' => trim($row[$stopIdIdx] ?? ''),
                        'arrival_time' => $arrStr,
                        'departure_time' => $depStr,
                        'arrival_seconds' => $arrSec,
                        'departure_seconds' => $depSec,
                        'stop_sequence' => (int) $row[$seqIdx],
                        'pickup_type' => $pickupIdx !== false ? (int) ($row[$pickupIdx] ?? 0) : 0,
                        'drop_off_type' => $dropOffIdx !== false ? (int) ($row[$dropOffIdx] ?? 0) : 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($records) >= 1000) {
                        DB::table('stop_times')->insert($records);
                        $imported += count($records);
                        $records = [];
                    }
                }

                if (! empty($records)) {
                    DB::table('stop_times')->insert($records);
                    $imported += count($records);
                }
                $this->info("{$feedId}: imported {$imported} stop_time records.");
            }
            fclose($handle);
        }
    }

    protected function importStopsCsv(string $path, string $feedId): void
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return;
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $stopIdIdx = array_search('stop_id', $header, true);
        $nameIdx = array_search('stop_name', $header, true);
        $latIdx = array_search('stop_lat', $header, true);
        $lonIdx = array_search('stop_lon', $header, true);

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 4 || $stopIdIdx === false) {
                continue;
            }

            $stopId = trim($row[$stopIdIdx]);
            $records[] = [
                'feed_id' => $feedId,
                'stop_id' => $stopId,
                'name' => $this->normalizeText($row[$nameIdx] ?? $stopId),
                'lat' => (float) ($row[$latIdx] ?? 0.0),
                'lon' => (float) ($row[$lonIdx] ?? 0.0),
                'location_type' => 0,
                'parent_station' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($records) >= 500) {
                DB::table('stops')->upsert($records, ['feed_id', 'stop_id'], ['name', 'lat', 'lon', 'updated_at']);
                $records = [];
            }
        }

        if (! empty($records)) {
            DB::table('stops')->upsert($records, ['feed_id', 'stop_id'], ['name', 'lat', 'lon', 'updated_at']);
        }

        fclose($handle);
    }

    /**
     * @param  array<string, true>|null  $routeIds
     */
    protected function importRoutesCsv(string $path, string $feedId, ?array $routeIds = null): void
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return;
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $routeIdIdx = array_search('route_id', $header, true);
        $shortNameIdx = array_search('route_short_name', $header, true);
        $longNameIdx = array_search('route_long_name', $header, true);
        $typeIdx = array_search('route_type', $header, true);
        $colorIdx = array_search('route_color', $header, true);

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($routeIdIdx === false || ! isset($row[$routeIdIdx])) {
                continue;
            }

            $routeId = trim($row[$routeIdIdx]);
            if ($routeIds !== null && ! isset($routeIds[$routeId])) {
                continue;
            }

            $records[] = [
                'feed_id' => $feedId,
                'route_id' => $routeId,
                'short_name' => $shortNameIdx !== false ? $this->normalizeText($row[$shortNameIdx] ?? '') : $routeId,
                'long_name' => $longNameIdx !== false ? $this->normalizeText($row[$longNameIdx] ?? '') : null,
                'route_type' => $typeIdx !== false ? (int) ($row[$typeIdx] ?? 3) : 3,
                'route_color' => $colorIdx !== false && ! empty($row[$colorIdx]) ? '#'.ltrim(trim($row[$colorIdx]), '#') : '#FF5722',
                'route_text_color' => '#FFFFFF',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($records) >= 500) {
                DB::table('routes')->upsert($records, ['feed_id', 'route_id'], ['short_name', 'long_name', 'route_type', 'route_color', 'updated_at']);
                $records = [];
            }
        }

        if (! empty($records)) {
            DB::table('routes')->upsert($records, ['feed_id', 'route_id'], ['short_name', 'long_name', 'route_type', 'route_color', 'updated_at']);
        }

        fclose($handle);
    }

    protected function importCalendarCsv(string $path, string $feedId): void
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return;
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $serviceIdIdx = array_search('service_id', $header, true);
        $weekdayIndexes = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $weekday) {
            $weekdayIndexes[$weekday] = array_search($weekday, $header, true);
        }
        $startIdx = array_search('start_date', $header, true);
        $endIdx = array_search('end_date', $header, true);

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($serviceIdIdx === false || $startIdx === false || $endIdx === false || ! isset($row[$serviceIdIdx])) {
                continue;
            }

            $startDate = trim($row[$startIdx] ?? '20260101');
            $endDate = trim($row[$endIdx] ?? '20261231');
            $serviceId = trim($row[$serviceIdIdx]);

            if ($serviceId === '') {
                continue;
            }

            $records[] = [
                'feed_id' => $feedId,
                'service_id' => $serviceId,
                'monday' => $weekdayIndexes['monday'] !== false ? $this->parseGtfsBoolean($row[$weekdayIndexes['monday']] ?? null) : false,
                'tuesday' => $weekdayIndexes['tuesday'] !== false ? $this->parseGtfsBoolean($row[$weekdayIndexes['tuesday']] ?? null) : false,
                'wednesday' => $weekdayIndexes['wednesday'] !== false ? $this->parseGtfsBoolean($row[$weekdayIndexes['wednesday']] ?? null) : false,
                'thursday' => $weekdayIndexes['thursday'] !== false ? $this->parseGtfsBoolean($row[$weekdayIndexes['thursday']] ?? null) : false,
                'friday' => $weekdayIndexes['friday'] !== false ? $this->parseGtfsBoolean($row[$weekdayIndexes['friday']] ?? null) : false,
                'saturday' => $weekdayIndexes['saturday'] !== false ? $this->parseGtfsBoolean($row[$weekdayIndexes['saturday']] ?? null) : false,
                'sunday' => $weekdayIndexes['sunday'] !== false ? $this->parseGtfsBoolean($row[$weekdayIndexes['sunday']] ?? null) : false,
                'start_date' => $this->parseGtfsDate($startDate, '2026-01-01'),
                'end_date' => $this->parseGtfsDate($endDate, '2026-12-31'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($records)) {
            DB::table('calendar')->upsert($records, ['feed_id', 'service_id'], ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date', 'updated_at']);
        }

        fclose($handle);
    }

    protected function importCalendarDatesCsv(string $path, string $feedId): void
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return;
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $serviceIdIdx = array_search('service_id', $header, true);
        $dateIdx = array_search('date', $header, true);
        $excIdx = array_search('exception_type', $header, true);

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($serviceIdIdx === false || ! isset($row[$serviceIdIdx])) {
                continue;
            }

            $date = trim($row[$dateIdx] ?? '');
            if (strlen($date) === 8) {
                $date = substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
            }

            $records[] = [
                'feed_id' => $feedId,
                'service_id' => trim($row[$serviceIdIdx]),
                'date' => $date,
                'exception_type' => (int) ($row[$excIdx] ?? 1),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($records) >= 500) {
                DB::table('calendar_dates')->upsert(
                    $records,
                    ['feed_id', 'service_id', 'date'],
                    ['exception_type', 'updated_at']
                );
                $records = [];
            }
        }

        if (! empty($records)) {
            DB::table('calendar_dates')->upsert(
                $records,
                ['feed_id', 'service_id', 'date'],
                ['exception_type', 'updated_at']
            );
        }

        fclose($handle);
    }

    protected function importTripsCsv(string $path, string $feedId): void
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return;
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $routeIdIdx = array_search('route_id', $header, true);
        $serviceIdIdx = array_search('service_id', $header, true);
        $tripIdIdx = array_search('trip_id', $header, true);
        $headsignIdx = array_search('trip_headsign', $header, true);
        $dirIdx = array_search('direction_id', $header, true);

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($tripIdIdx === false || ! isset($row[$tripIdIdx])) {
                continue;
            }

            $tripId = trim($row[$tripIdIdx]);
            $records[] = [
                'feed_id' => $feedId,
                'trip_id' => $tripId,
                'route_id' => trim($row[$routeIdIdx] ?? ''),
                'service_id' => trim($row[$serviceIdIdx] ?? ''),
                'trip_headsign' => $headsignIdx !== false ? $this->normalizeText($row[$headsignIdx] ?? '') : null,
                'direction_id' => $dirIdx !== false ? (int) ($row[$dirIdx] ?? 0) : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($records) >= 500) {
                DB::table('trips')->upsert($records, ['feed_id', 'trip_id'], ['route_id', 'service_id', 'trip_headsign', 'direction_id', 'updated_at']);
                $records = [];
            }
        }

        if (! empty($records)) {
            DB::table('trips')->upsert($records, ['feed_id', 'trip_id'], ['route_id', 'service_id', 'trip_headsign', 'direction_id', 'updated_at']);
        }

        fclose($handle);
    }

    protected function importStopTimesCsv(string $path, string $feedId): void
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return;
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return;
        }

        $header = $this->parseCsvHeader($header);
        $tripIdIdx = array_search('trip_id', $header, true);
        $arrIdx = array_search('arrival_time', $header, true);
        $depIdx = array_search('departure_time', $header, true);
        $stopIdIdx = array_search('stop_id', $header, true);
        $seqIdx = array_search('stop_sequence', $header, true);
        $pickupIdx = array_search('pickup_type', $header, true);
        $dropOffIdx = array_search('drop_off_type', $header, true);

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (
                $tripIdIdx === false
                || $arrIdx === false
                || $depIdx === false
                || $stopIdIdx === false
                || $seqIdx === false
                || ! isset($row[$tripIdIdx], $row[$stopIdIdx], $row[$seqIdx])
            ) {
                continue;
            }

            $arrStr = trim($row[$arrIdx]);
            $depStr = trim($row[$depIdx]);
            $arrSec = $this->parseGtfsTime($arrStr);
            $depSec = $this->parseGtfsTime($depStr);

            if ($arrSec === null || $depSec === null || trim($row[$stopIdIdx]) === '') {
                continue;
            }

            $records[] = [
                'feed_id' => $feedId,
                'trip_id' => trim($row[$tripIdIdx]),
                'stop_id' => trim($row[$stopIdIdx] ?? ''),
                'arrival_time' => $arrStr,
                'departure_time' => $depStr,
                'arrival_seconds' => $arrSec,
                'departure_seconds' => $depSec,
                'stop_sequence' => (int) $row[$seqIdx],
                'pickup_type' => $pickupIdx !== false ? (int) ($row[$pickupIdx] ?? 0) : 0,
                'drop_off_type' => $dropOffIdx !== false ? (int) ($row[$dropOffIdx] ?? 0) : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($records) >= 1000) {
                DB::table('stop_times')->insert($records);
                $records = [];
            }
        }

        if (! empty($records)) {
            DB::table('stop_times')->insert($records);
        }

        fclose($handle);
    }

    /**
     * Seeds realistic, high-fidelity transit timetables covering the Belfort-Montbéliard corridor:
     * 1. Optymo Belfort Bus Network
     * 2. évolitY Montbéliard Bus Network
     * 3. SNCF TER Regional Rail (Belfort ↔ Montbéliard ↔ Meroux TGV)
     */
    protected function seedBaselineGtfs(): void
    {
        $this->line('Seeding baseline multimodal transit networks...');

        // 1. Calendar
        $services = ['OPTYMO_DAILY', 'EVOLITY_DAILY', 'TER_DAILY'];
        foreach ($services as $srv) {
            Calendar::updateOrCreate(
                ['feed_id' => explode('_', $srv)[0], 'service_id' => $srv],
                [
                    'monday' => true,
                    'tuesday' => true,
                    'wednesday' => true,
                    'thursday' => true,
                    'friday' => true,
                    'saturday' => true,
                    'sunday' => true,
                    'start_date' => '2026-01-01',
                    'end_date' => '2027-12-31',
                ]
            );
        }

        // 2. Stops
        $stops = [
            // TER Stations
            ['feed_id' => 'TER', 'stop_id' => 'TER_BELFORT', 'name' => 'Gare de Belfort', 'lat' => 47.6334, 'lon' => 6.8542],
            ['feed_id' => 'TER', 'stop_id' => 'TER_DANJOUTIN', 'name' => 'Gare de Danjoutin', 'lat' => 47.6178, 'lon' => 6.8645],
            ['feed_id' => 'TER', 'stop_id' => 'TER_MEROUX_TGV', 'name' => 'Gare Belfort-Montbéliard TGV', 'lat' => 47.5861, 'lon' => 6.8972],
            ['feed_id' => 'TER', 'stop_id' => 'TER_MONTBELIARD', 'name' => 'Gare de Montbéliard', 'lat' => 47.5118, 'lon' => 6.7981],

            // Optymo Belfort Stops
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_VALDOIE', 'name' => 'Valdoie Mairie', 'lat' => 47.6672, 'lon' => 6.8312],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_TECHNOM', 'name' => 'Techn\'hom 1', 'lat' => 47.6472, 'lon' => 6.8375],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_UTBM_BELFORT', 'name' => 'UTBM Belfort Campus', 'lat' => 47.6425, 'lon' => 6.8436],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_CORBIS', 'name' => 'Corbis Place Centrale', 'lat' => 47.6382, 'lon' => 6.8589],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_GLACIS', 'name' => 'Champ de Mars / Glacis', 'lat' => 47.6415, 'lon' => 6.8662],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_GARE_BELFORT', 'name' => 'Gare SNCF Belfort', 'lat' => 47.6332, 'lon' => 6.8540],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_BAVILLIERS', 'name' => 'Bavilliers La Dame', 'lat' => 47.6185, 'lon' => 6.8295],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_CHATENOIS', 'name' => 'Châtenois Mairie', 'lat' => 47.5592, 'lon' => 6.8488],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_SEVENANS', 'name' => 'UTBM Sevenans / Jonxion', 'lat' => 47.5878, 'lon' => 6.8665],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_HNFC', 'name' => 'Hôpital Nord Franche-Comté', 'lat' => 47.5752, 'lon' => 6.8645],
            ['feed_id' => 'OPTYMO', 'stop_id' => 'OPT_RESIDENCES', 'name' => 'Les Résidences Centre', 'lat' => 47.6495, 'lon' => 6.8732],

            // évolitY Montbéliard Stops
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_ACROPOLE', 'name' => 'Acropole Gare Montbéliard', 'lat' => 47.5115, 'lon' => 6.7978],
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_BETHONCOURT', 'name' => 'Bethoncourt Champ Vallon', 'lat' => 47.5342, 'lon' => 6.8045],
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_GRAND_CHARMONT', 'name' => 'Grand-Charmont Centre', 'lat' => 47.5275, 'lon' => 6.8252],
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_SOCHAUX', 'name' => 'Sochaux Musée Peugeot', 'lat' => 47.5165, 'lon' => 6.8315],
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_AXONE', 'name' => 'L\'Axone Gros Pierrons', 'lat' => 47.5002, 'lon' => 6.8228],
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_UTBM_MONTBELIARD', 'name' => 'UTBM Montbéliard Portes du Jura', 'lat' => 47.5023, 'lon' => 6.7972],
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_AUDINCOURT', 'name' => 'Audincourt Temple', 'lat' => 47.4795, 'lon' => 6.8415],
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_VALENTIGNEY', 'name' => 'Valentigney Centre', 'lat' => 47.4625, 'lon' => 6.8320],
            ['feed_id' => 'EVOLITY', 'stop_id' => 'EVO_HNFC', 'name' => 'Hôpital Nord Franche-Comté (Montbéliard)', 'lat' => 47.5750, 'lon' => 6.8642],
        ];

        foreach ($stops as $s) {
            Stop::updateOrCreate(
                ['feed_id' => $s['feed_id'], 'stop_id' => $s['stop_id']],
                [
                    'name' => $s['name'],
                    'lat' => $s['lat'],
                    'lon' => $s['lon'],
                    'location_type' => 0,
                    'parent_station' => null,
                ]
            );
        }

        // 3. Routes
        $routes = [
            ['feed_id' => 'TER', 'route_id' => 'TER_LIGNE_BM', 'short_name' => 'TER', 'long_name' => 'TER Belfort - Meroux TGV - Montbéliard', 'route_type' => 2, 'route_color' => '#7C3AED'],
            ['feed_id' => 'OPTYMO', 'route_id' => 'OPT_L1', 'short_name' => 'L1', 'long_name' => 'Ligne 1: Valdoie - Corbis - Résidences', 'route_type' => 3, 'route_color' => '#FF5722'],
            ['feed_id' => 'OPTYMO', 'route_id' => 'OPT_L3', 'short_name' => 'L3', 'long_name' => 'Ligne 3: Techn\'hom - Gare Belfort - Sevenans UTBM - HNFC', 'route_type' => 3, 'route_color' => '#2563EB'],
            ['feed_id' => 'EVOLITY', 'route_id' => 'EVO_THNS1', 'short_name' => 'THNS 1', 'long_name' => 'THNS 1: Bethoncourt - Acropole - Axone - Audincourt', 'route_type' => 3, 'route_color' => '#059669'],
            ['feed_id' => 'EVOLITY', 'route_id' => 'EVO_L3', 'short_name' => 'L3', 'long_name' => 'Ligne 3: Acropole - UTBM Montbéliard - HNFC', 'route_type' => 3, 'route_color' => '#D97706'],
        ];

        foreach ($routes as $r) {
            Route::updateOrCreate(
                ['feed_id' => $r['feed_id'], 'route_id' => $r['route_id']],
                [
                    'short_name' => $r['short_name'],
                    'long_name' => $r['long_name'],
                    'route_type' => $r['route_type'],
                    'route_color' => $r['route_color'],
                    'route_text_color' => '#FFFFFF',
                ]
            );
        }

        // 4. Trips & Stop Times Generator
        // Generate regular trips throughout the day (06:00 to 22:00)
        $this->generateTerTrips();
        $this->generateOptymoTrips();
        $this->generateEvolityTrips();
    }

    protected function generateTerTrips(): void
    {
        // TER between Belfort and Montbeliard every 30 minutes from 06:00 to 22:00
        // Forward: TER_BELFORT -> TER_DANJOUTIN -> TER_MEROUX_TGV -> TER_MONTBELIARD (Duration: ~16 mins)
        // Reverse: TER_MONTBELIARD -> TER_MEROUX_TGV -> TER_DANJOUTIN -> TER_BELFORT
        $stopsFwd = ['TER_BELFORT', 'TER_DANJOUTIN', 'TER_MEROUX_TGV', 'TER_MONTBELIARD'];
        $durationsFwd = [0, 4, 9, 16]; // minutes from departure

        $stopsRev = array_reverse($stopsFwd);
        $durationsRev = [0, 7, 12, 16];

        for ($hour = 6; $hour <= 22; $hour++) {
            foreach ([0, 30] as $min) {
                // Forward trip
                $depSec = ($hour * 3600) + ($min * 60);
                $tripIdFwd = sprintf('TER_FWD_%02d%02d', $hour, $min);
                Trip::updateOrCreate(
                    ['feed_id' => 'TER', 'trip_id' => $tripIdFwd],
                    ['route_id' => 'TER_LIGNE_BM', 'service_id' => 'TER_DAILY', 'trip_headsign' => 'Montbéliard', 'direction_id' => 0]
                );

                foreach ($stopsFwd as $idx => $stopId) {
                    $arrSec = $depSec + ($durationsFwd[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'TER', 'trip_id' => $tripIdFwd, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }

                // Reverse trip (offset by 15 mins)
                $depSecRev = ($hour * 3600) + (($min + 15) * 60);
                $tripIdRev = sprintf('TER_REV_%02d%02d', $hour, ($min + 15) % 60);
                Trip::updateOrCreate(
                    ['feed_id' => 'TER', 'trip_id' => $tripIdRev],
                    ['route_id' => 'TER_LIGNE_BM', 'service_id' => 'TER_DAILY', 'trip_headsign' => 'Belfort', 'direction_id' => 1]
                );

                foreach ($stopsRev as $idx => $stopId) {
                    $arrSec = $depSecRev + ($durationsRev[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'TER', 'trip_id' => $tripIdRev, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }
            }
        }
    }

    protected function generateOptymoTrips(): void
    {
        // Ligne 1: Valdoie -> Techn'hom -> UTBM -> Corbis -> Glacis -> Résidences
        $stopsL1Fwd = ['OPT_VALDOIE', 'OPT_TECHNOM', 'OPT_UTBM_BELFORT', 'OPT_CORBIS', 'OPT_GLACIS', 'OPT_RESIDENCES'];
        $durationsL1Fwd = [0, 5, 8, 14, 18, 24];
        $stopsL1Rev = array_reverse($stopsL1Fwd);
        $durationsL1Rev = [0, 6, 10, 16, 19, 24];

        // Ligne 3: Techn'hom -> UTBM Belfort -> Gare Belfort -> Bavilliers -> Châtenois -> Sevenans -> HNFC
        $stopsL3Fwd = ['OPT_TECHNOM', 'OPT_UTBM_BELFORT', 'OPT_GARE_BELFORT', 'OPT_BAVILLIERS', 'OPT_CHATENOIS', 'OPT_SEVENANS', 'OPT_HNFC'];
        $durationsL3Fwd = [0, 4, 10, 15, 23, 30, 36];
        $stopsL3Rev = array_reverse($stopsL3Fwd);
        $durationsL3Rev = [0, 6, 13, 21, 26, 32, 36];

        for ($hour = 6; $hour <= 21; $hour++) {
            foreach ([10, 40] as $min) {
                // L1 Forward
                $depSec = ($hour * 3600) + ($min * 60);
                $tripIdL1 = sprintf('OPT_L1_FWD_%02d%02d', $hour, $min);
                Trip::updateOrCreate(
                    ['feed_id' => 'OPTYMO', 'trip_id' => $tripIdL1],
                    ['route_id' => 'OPT_L1', 'service_id' => 'OPTYMO_DAILY', 'trip_headsign' => 'Les Résidences', 'direction_id' => 0]
                );

                foreach ($stopsL1Fwd as $idx => $stopId) {
                    $arrSec = $depSec + ($durationsL1Fwd[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'OPTYMO', 'trip_id' => $tripIdL1, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }

                // L1 Reverse (offset by 15 mins)
                $depSecRev = ($hour * 3600) + (($min + 15) * 60);
                $tripIdL1Rev = sprintf('OPT_L1_REV_%02d%02d', $hour, ($min + 15) % 60);
                Trip::updateOrCreate(
                    ['feed_id' => 'OPTYMO', 'trip_id' => $tripIdL1Rev],
                    ['route_id' => 'OPT_L1', 'service_id' => 'OPTYMO_DAILY', 'trip_headsign' => 'Valdoie Mairie', 'direction_id' => 1]
                );

                foreach ($stopsL1Rev as $idx => $stopId) {
                    $arrSec = $depSecRev + ($durationsL1Rev[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'OPTYMO', 'trip_id' => $tripIdL1Rev, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }

                // L3 Forward
                $tripIdL3 = sprintf('OPT_L3_FWD_%02d%02d', $hour, $min);
                Trip::updateOrCreate(
                    ['feed_id' => 'OPTYMO', 'trip_id' => $tripIdL3],
                    ['route_id' => 'OPT_L3', 'service_id' => 'OPTYMO_DAILY', 'trip_headsign' => 'Sevenans - HNFC', 'direction_id' => 0]
                );

                foreach ($stopsL3Fwd as $idx => $stopId) {
                    $arrSec = $depSec + ($durationsL3Fwd[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'OPTYMO', 'trip_id' => $tripIdL3, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }

                // L3 Reverse (HNFC -> Sevenans -> Belfort -> UTBM -> Techn'hom)
                $depSecL3Rev = ($hour * 3600) + (($min + 15) * 60);
                $tripIdL3Rev = sprintf('OPT_L3_REV_%02d%02d', $hour, ($min + 15) % 60);
                Trip::updateOrCreate(
                    ['feed_id' => 'OPTYMO', 'trip_id' => $tripIdL3Rev],
                    ['route_id' => 'OPT_L3', 'service_id' => 'OPTYMO_DAILY', 'trip_headsign' => 'UTBM - Techn\'hom', 'direction_id' => 1]
                );

                foreach ($stopsL3Rev as $idx => $stopId) {
                    $arrSec = $depSecL3Rev + ($durationsL3Rev[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'OPTYMO', 'trip_id' => $tripIdL3Rev, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }
            }
        }
    }

    protected function generateEvolityTrips(): void
    {
        // THNS 1: Bethoncourt -> Acropole -> Sochaux -> Axone -> Audincourt -> Valentigney
        $stopsThnsFwd = ['EVO_BETHONCOURT', 'EVO_ACROPOLE', 'EVO_SOCHAUX', 'EVO_AXONE', 'EVO_AUDINCOURT', 'EVO_VALENTIGNEY'];
        $durationsThnsFwd = [0, 8, 14, 20, 27, 34];
        $stopsThnsRev = array_reverse($stopsThnsFwd);
        $durationsThnsRev = [0, 7, 14, 20, 26, 34];

        // L3: Acropole -> UTBM Montbéliard -> Grand-Charmont -> HNFC Trévenans
        $stopsL3Fwd = ['EVO_ACROPOLE', 'EVO_UTBM_MONTBELIARD', 'EVO_GRAND_CHARMONT', 'EVO_HNFC'];
        $durationsL3Fwd = [0, 5, 12, 24];
        $stopsL3Rev = array_reverse($stopsL3Fwd);
        $durationsL3Rev = [0, 12, 19, 24];

        for ($hour = 6; $hour <= 21; $hour++) {
            foreach ([15, 45] as $min) {
                // THNS 1 Forward
                $depSec = ($hour * 3600) + ($min * 60);
                $tripIdThns = sprintf('EVO_THNS1_FWD_%02d%02d', $hour, $min);
                Trip::updateOrCreate(
                    ['feed_id' => 'EVOLITY', 'trip_id' => $tripIdThns],
                    ['route_id' => 'EVO_THNS1', 'service_id' => 'EVOLITY_DAILY', 'trip_headsign' => 'Audincourt / Valentigney', 'direction_id' => 0]
                );

                foreach ($stopsThnsFwd as $idx => $stopId) {
                    $arrSec = $depSec + ($durationsThnsFwd[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'EVOLITY', 'trip_id' => $tripIdThns, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }

                // THNS 1 Reverse
                $depSecRev = ($hour * 3600) + (($min + 15) * 60);
                $tripIdThnsRev = sprintf('EVO_THNS1_REV_%02d%02d', $hour, ($min + 15) % 60);
                Trip::updateOrCreate(
                    ['feed_id' => 'EVOLITY', 'trip_id' => $tripIdThnsRev],
                    ['route_id' => 'EVO_THNS1', 'service_id' => 'EVOLITY_DAILY', 'trip_headsign' => 'Bethoncourt Champ Vallon', 'direction_id' => 1]
                );

                foreach ($stopsThnsRev as $idx => $stopId) {
                    $arrSec = $depSecRev + ($durationsThnsRev[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'EVOLITY', 'trip_id' => $tripIdThnsRev, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }

                // L3 Forward
                $tripIdL3 = sprintf('EVO_L3_FWD_%02d%02d', $hour, $min);
                Trip::updateOrCreate(
                    ['feed_id' => 'EVOLITY', 'trip_id' => $tripIdL3],
                    ['route_id' => 'EVO_L3', 'service_id' => 'EVOLITY_DAILY', 'trip_headsign' => 'HNFC Hôpital Nord', 'direction_id' => 0]
                );

                foreach ($stopsL3Fwd as $idx => $stopId) {
                    $arrSec = $depSec + ($durationsL3Fwd[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'EVOLITY', 'trip_id' => $tripIdL3, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }

                // L3 Reverse
                $depSecL3Rev = ($hour * 3600) + (($min + 15) * 60);
                $tripIdL3Rev = sprintf('EVO_L3_REV_%02d%02d', $hour, ($min + 15) % 60);
                Trip::updateOrCreate(
                    ['feed_id' => 'EVOLITY', 'trip_id' => $tripIdL3Rev],
                    ['route_id' => 'EVO_L3', 'service_id' => 'EVOLITY_DAILY', 'trip_headsign' => 'Montbéliard Acropole', 'direction_id' => 1]
                );

                foreach ($stopsL3Rev as $idx => $stopId) {
                    $arrSec = $depSecL3Rev + ($durationsL3Rev[$idx] * 60);
                    $arrTime = sprintf('%02d:%02d:00', floor($arrSec / 3600), floor(($arrSec % 3600) / 60));
                    StopTime::updateOrCreate(
                        ['feed_id' => 'EVOLITY', 'trip_id' => $tripIdL3Rev, 'stop_id' => $stopId],
                        [
                            'arrival_time' => $arrTime,
                            'departure_time' => $arrTime,
                            'arrival_seconds' => $arrSec,
                            'departure_seconds' => $arrSec,
                            'stop_sequence' => $idx + 1,
                        ]
                    );
                }
            }
        }
    }
}
