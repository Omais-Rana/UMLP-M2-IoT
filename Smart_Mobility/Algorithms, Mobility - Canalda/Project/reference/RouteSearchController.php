<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GbfsStation;
use App\Models\ParkingSpot;
use App\Models\RouteSearch;
use App\Models\Stop;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RouteSearchController extends Controller
{
    /** @var array<string, list<array{float, float}>> Shape point cache keyed by shape_id, reset per-request */
    private array $shapeCache = [];

    /**
     * Search for stops by name, description, or code
     * Groups by stop_name and coordinates to show unique locations
     */
    public function searchStops(Request $request)
    {
        $query = $request->input('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        // Normalize query - remove accents for searching (é->e, à->a, etc.)
        $normalizedQuery = $this->removeAccents(strtolower($query));

        // Search stops - accent-insensitive and case-insensitive search
        // Prioritize stops that start with the query, then those that contain it
        // Use withoutGlobalScopes() to show stops from ALL operators (not just logged-in user's)
        $stops = Stop::withoutGlobalScopes()->where(function ($q) use ($normalizedQuery) {
            // Use LOWER and accent-insensitive comparison
            $q->whereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(stop_name, "é", "e"), "è", "e"), "ê", "e"), "à", "a"), "ô", "o"), "ù", "u"), "ç", "c")) LIKE ?', [$normalizedQuery.'%'])
                ->orWhereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(stop_name, "é", "e"), "è", "e"), "ê", "e"), "à", "a"), "ô", "o"), "ù", "u"), "ç", "c")) LIKE ?', ['% '.$normalizedQuery.'%']);
        })
            ->whereNotNull('stop_lat')
            ->whereNotNull('stop_lon')
            ->select(
                'stop_name',
                DB::raw('ROUND(AVG(stop_lat), 5) as stop_lat'),
                DB::raw('ROUND(AVG(stop_lon), 5) as stop_lon'),
                DB::raw('GROUP_CONCAT(DISTINCT stop_id) as stop_ids'),
                DB::raw('MAX(stop_desc) as stop_desc'),
                DB::raw('MAX(stop_code) as stop_code'),
                DB::raw('MAX(city) as city'),
                DB::raw("CASE 
                    WHEN LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(stop_name, 'é', 'e'), 'è', 'e'), 'ê', 'e'), 'à', 'a'), 'ô', 'o'), 'ù', 'u'), 'ç', 'c')) LIKE '".$normalizedQuery."%' THEN 1 
                    ELSE 2 
                END as priority")
            )
            ->groupBy('stop_name')
            ->orderBy('priority')
            ->orderBy('stop_name')
            ->limit(20)
            ->get();

        return response()->json($stops);
    }

    /**
     * Search for routes - simplified approach showing all routes passing through selected stops
     */
    public function searchRoutes(Request $request)
    {
        $request->validate([
            'origin_name' => 'required|string',
            'destination_name' => 'required|string',
            'date' => 'required|date',
            'time' => 'required',
        ]);

        $originName = $request->input('origin_name');
        $destinationName = $request->input('destination_name');
        $date = $request->input('date');
        $time = $request->input('time');

        // Read GPS coordinates sent by the frontend autocomplete (always present when a stop is selected)
        $originLat = $request->input('origin_lat') ? (float) $request->input('origin_lat') : null;
        $originLon = $request->input('origin_lon') ? (float) $request->input('origin_lon') : null;
        $destLat = $request->input('dest_lat') ? (float) $request->input('dest_lat') : null;
        $destLon = $request->input('dest_lon') ? (float) $request->input('dest_lon') : null;

        // Log search history for customers only
        if (Auth::check() && Auth::user()->role === 'customer') {
            $user = Auth::user();
            try {
                $searchData = [
                    'user_id' => $user->id,
                    'origin_name' => $originName,
                    'destination_name' => $destinationName,
                    'search_time' => $time,
                    'search_date' => $date,
                ];

                RouteSearch::create($searchData);
            } catch (\Exception $e) {
                // Logging failure must never break the search
            }
        }

        $minimumDepartureTime = $time;
        $dayOfWeek = date('w', strtotime($date));
        $dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

        // FIX 5: Resolve stop IDs using GPS coordinates (sent by the frontend autocomplete).
        // This is far more precise than LIKE '%name%' which matched unrelated stops sharing the same name.
        $originStopIds = $this->resolveStopIds($originName, $originLat, $originLon);
        $destStopIds = $this->resolveStopIds($destinationName, $destLat, $destLon);

        if (empty($originStopIds) || empty($destStopIds)) {
            return response()->json([
                'routes' => [],
                'message' => 'Stops not found.',
            ]);
        }

        // If coordinates were not passed from autocomplete, resolve them from the matched stops
        if (($originLat === null || $originLon === null) && ! empty($originStopIds)) {
            $firstOriginStop = DB::table('stops')->whereIn('id', $originStopIds)->whereNotNull('stop_lat')->first();
            if ($firstOriginStop) {
                $originLat = (float) $firstOriginStop->stop_lat;
                $originLon = (float) $firstOriginStop->stop_lon;
            }
        }
        if (($destLat === null || $destLon === null) && ! empty($destStopIds)) {
            $firstDestStop = DB::table('stops')->whereIn('id', $destStopIds)->whereNotNull('stop_lat')->first();
            if ($firstDestStop) {
                $destLat = (float) $firstDestStop->stop_lat;
                $destLon = (float) $firstDestStop->stop_lon;
            }
        }

        // Build active service IDs for the given date.
        // Priority: (1) calendars table entries for this date/day
        //           (2) calendar_dates exceptions (type=1 add, type=2 remove)
        //           (3) if neither has data, fall back to all services (bare GTFS with no schedule)
        $calendarServices = DB::table('calendars')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where($dayNames[$dayOfWeek], 1)
            ->pluck('service_id')
            ->toArray();

        $exceptions = DB::table('calendar_dates')->where('date', $date)->get();
        $addedServices = $exceptions->where('exception_type', 1)->pluck('service_id')->toArray();

        if (! empty($calendarServices)) {
            // Standard GTFS: calendar drives the base, exceptions modify it
            $activeServiceIds = $calendarServices;
        } elseif (! empty($addedServices)) {
            // GTFS with calendar_dates-only schedule (no calendars table):
            // only the explicitly added services run on this date
            $activeServiceIds = $addedServices;
        } else {
            // No schedule data at all — fall back to all services
            $activeServiceIds = DB::table('trips')->distinct()->pluck('service_id')->toArray();
        }

        // Apply type=2 (removed) exceptions on top of whatever base we have
        $removedServices = $exceptions->where('exception_type', 2)->pluck('service_id')->toArray();
        if (! empty($removedServices)) {
            $activeServiceIds = array_values(array_filter($activeServiceIds, fn ($id) => ! in_array($id, $removedServices)));
        }

        // Also add any type=1 services not yet in the list (e.g. when using calendar base)
        foreach ($addedServices as $svcId) {
            if (! in_array($svcId, $activeServiceIds)) {
                $activeServiceIds[] = $svcId;
            }
        }

        // Find ALL routes that connect these stops (any combination)
        // NOTE: stop_times.trip_id references trips.id, and trips.route_id references routes.id
        $routes = DB::table('stop_times as st1')
            ->join('stop_times as st2', function ($join) {
                $join->on('st1.trip_id', '=', 'st2.trip_id')
                    ->whereRaw('st2.stop_sequence > st1.stop_sequence');
            })
            ->join('trips', 'st1.trip_id', '=', 'trips.id')
            ->join('routes', 'trips.route_id', '=', 'routes.id')
            ->join('users', 'routes.tenant_id', '=', 'users.id')
            ->whereIn('trips.service_id', $activeServiceIds)
            ->whereIn('st1.stop_id', $originStopIds)
            ->whereIn('st2.stop_id', $destStopIds)
            ->select(
                'routes.route_short_name',
                'routes.route_long_name',
                'routes.route_type',
                'routes.route_desc',
                'routes.route_color',
                'routes.route_text_color',
                'trips.trip_headsign',
                'trips.service_id',
                'trips.id as trip_id',
                'trips.shape_id',
                'users.name as operator_name',
                'st1.departure_time',
                'st2.arrival_time',
                'st1.stop_id as origin_stop_id',
                'st2.stop_id as dest_stop_id',
                'st1.stop_sequence as origin_stop_sequence',
                'st2.stop_sequence as dest_stop_sequence',
                DB::raw('TIME_TO_SEC(TIMEDIFF(st2.arrival_time, st1.departure_time)) / 60 as duration_minutes'),
                DB::raw('st2.stop_sequence - st1.stop_sequence + 1 as stops_count')
            )
            ->where('st1.departure_time', '>=', $time)
            ->orderBy('st1.departure_time')
            ->limit(20)
            ->get();

        // Keep only the first (nearest) route for each transport line
        $uniqueRoutes = [];
        $seenRoutes = [];

        foreach ($routes as $route) {
            $routeKey = $route->route_short_name;
            if (! isset($seenRoutes[$routeKey])) {
                $seenRoutes[$routeKey] = true;
                $uniqueRoutes[] = $route;
            }
        }

        $routes = collect($uniqueRoutes);

        $includeCar = $request->boolean('include_carsharing', true);
        $gbfsRoutes = $this->getGbfsRoutes($originLat, $originLon, $destLat, $destLon, $time, $originName, $destinationName, $includeCar);

        // If direct routes found, return them
        if ($routes->isNotEmpty()) {
            $selectedTime = substr($time, 0, 5);
            $formattedRoutes = $routes->map(function ($route) use ($selectedTime) {
                $departureTime = substr($route->departure_time, 0, 5);
                $isExactMatch = $departureTime === $selectedTime;

                // Get all stops on this trip between origin and destination
                $stopsOnRoute = DB::table('stop_times')
                    ->join('stops', 'stop_times.stop_id', '=', 'stops.id')
                    ->where('stop_times.trip_id', $route->trip_id)
                    ->where('stop_times.stop_sequence', '>=', $route->origin_stop_sequence)
                    ->where('stop_times.stop_sequence', '<=', $route->dest_stop_sequence)
                    ->orderBy('stop_times.stop_sequence')
                    ->select(
                        'stops.stop_name',
                        'stops.stop_lat',
                        'stops.stop_lon',
                        'stop_times.arrival_time',
                        'stop_times.departure_time',
                        'stop_times.stop_sequence'
                    )
                    ->get()
                    ->values()
                    ->map(function ($stop, $index) use (&$stopsTotal) {
                        return [
                            'name' => $stop->stop_name,
                            'lat' => $stop->stop_lat,
                            'lon' => $stop->stop_lon,
                            'arrival' => substr($stop->arrival_time, 0, 5),
                            'departure' => substr($stop->departure_time, 0, 5),
                            'is_intermediate' => false, // will be set below
                        ];
                    })
                    ->toArray();

                // Mark intermediate stops
                foreach ($stopsOnRoute as $i => &$stop) {
                    $stop['is_intermediate'] = ($i > 0 && $i < count($stopsOnRoute) - 1);
                }
                unset($stop);

                $shapePoints = [];
                if (! empty($route->shape_id) && ! empty($stopsOnRoute)) {
                    $first = $stopsOnRoute[0];
                    $last = $stopsOnRoute[count($stopsOnRoute) - 1];
                    $shapePoints = $this->getClippedShapePoints(
                        $route->shape_id,
                        (float) $first['lat'],
                        (float) $first['lon'],
                        (float) $last['lat'],
                        (float) $last['lon']
                    );
                }

                return [
                    'route_name' => $route->route_short_name ?? $route->route_long_name ?? 'Route',
                    'route_long_name' => $route->route_long_name,
                    'route_type' => $route->route_type,
                    'trip_headsign' => $route->trip_headsign,
                    'route_color' => $route->route_color,
                    'route_text_color' => $route->route_text_color,
                    'agency_name' => $route->operator_name ?? 'Transit Service',
                    'departure_time' => $departureTime,
                    'arrival_time' => substr($route->arrival_time, 0, 5),
                    'duration' => round($route->duration_minutes).' min',
                    'stops_count' => $route->stops_count,
                    'stops' => $stopsOnRoute,
                    'shape_pts' => $shapePoints,
                    'description' => $route->route_desc,
                    'is_exact_match' => $isExactMatch,
                    'connection_type' => 'direct',
                ];
            })
                ->take(10)
                ->values()
                ->toArray();

            $finalRoutes = collect($formattedRoutes)->merge($gbfsRoutes)->sortBy(function ($r) {
                return (int) str_replace(' min', '', $r['duration']);
            })->values()->toArray();

            return response()->json([
                'routes' => $finalRoutes,
                'total' => count($finalRoutes),
            ]);
        }

        // No direct routes found - search for routes with transfers
        $transferRoutes = $this->findTransferRoutes(
            $originStopIds, $destStopIds, $originName, $destinationName, $time, $activeServiceIds, $minimumDepartureTime, $date, $originLat, $originLon, $destLat, $destLon
        );

        $trunkRoutes = $this->findTrunkRoutes(
            $originStopIds, $destStopIds, $originName, $destinationName, $time, $activeServiceIds, $minimumDepartureTime, $date, $originLat, $originLon, $destLat, $destLon
        );

        $finalTransferRoutes = collect($transferRoutes)->merge($trunkRoutes)->merge($gbfsRoutes)->sortBy(function ($r) {
            return (int) str_replace(' min', '', $r['duration']);
        })->take(10)->values()->toArray();

        return response()->json([
            'routes' => $finalTransferRoutes,
            'total' => count($finalTransferRoutes),
            'message' => count($finalTransferRoutes) > 0
                ? 'Showing routes with transfers. Walking may be required between stops.'
                : 'No routes found. Try adjusting your search time or date.',
        ]);
    }

    /**
     * Find routes with transfers and walking connections.
     */
    private function findTransferRoutes(
        $originStopIds,
        $destStopIds,
        $originName,
        $destinationName,
        $time,
        $activeServiceIds,
        $minimumDepartureTime = null,
        $date = null,
        ?float $originLat = null,
        ?float $originLon = null,
        ?float $destLat = null,
        ?float $destLon = null
    ) {
        $maxWalkingDistance = 1.5;  // km
        $walkingSpeedKmh = 6.5;
        $minTransferTime = 5;    // minutes
        $maxTransferWaitTime = 120;   // minutes

        // FIX 1: Pre-load ALL stops into a PHP array (1 query replaces ~300 per-row DB calls).
        $stopCache = $this->preloadStopCache();

        $originStops = collect(array_intersect_key($stopCache, array_flip($originStopIds)));
        $destStops = collect(array_intersect_key($stopCache, array_flip($destStopIds)));

        if ($originStops->isEmpty() || $destStops->isEmpty()) {
            return [];
        }

        $journeys = [];
        $sqlMinTime = $time.':00';

        // STRATEGY 1: Direct routes from EXACT stops (NO walking, NO transfers) - HIGHEST PRIORITY
        // For exact origin stops, no walking buffer needed - use minimum time directly
        $directRoutes = DB::table('stop_times as st_origin')
            ->join('trips as t', 'st_origin.trip_id', '=', 't.id')
            ->join('routes as r', 't.route_id', '=', 'r.id')
            ->join('users as u', 'r.tenant_id', '=', 'u.id')
            ->join('stop_times as st_dest', function ($join) use ($destStopIds) {
                $join->on('st_origin.trip_id', '=', 'st_dest.trip_id')
                    ->whereIn('st_dest.stop_id', $destStopIds) // EXACT destination stops only
                    ->whereRaw('st_dest.stop_sequence > st_origin.stop_sequence');
            })
            ->join('stops as dest_stop', 'st_dest.stop_id', '=', 'dest_stop.id')
            ->whereIn('st_origin.stop_id', $originStopIds) // EXACT origin stops only
            ->whereIn('t.service_id', $activeServiceIds)
            ->where('st_origin.departure_time', '>=', $sqlMinTime)
            ->where('st_origin.departure_time', '<=', date('H:i:s', strtotime($sqlMinTime) + 10800))
            ->select(
                'st_origin.stop_id as origin_stop_id',
                'st_origin.departure_time',
                'st_dest.stop_id as dest_stop_id',
                'st_dest.arrival_time',
                'dest_stop.stop_name as dest_stop_name',
                'r.route_short_name',
                'r.route_long_name',
                'r.route_type',
                'r.route_color',
                'r.route_text_color',
                't.trip_headsign',
                't.id as trip_id',
                't.shape_id',
                'st_origin.stop_sequence as origin_sequence',
                'st_dest.stop_sequence as dest_sequence',
                'u.name as operator_name'
            )
            ->orderBy('st_origin.departure_time')
            ->limit(50) // Get more options to find best walking distance
            ->get();

        $foundJourneys = [];
        $routeOptions = [];

        $firstOrigin = $this->pickReferenceStop($originStops, $originLat, $originLon) ?? $originStops->first();
        $firstDest = $this->pickReferenceStop($destStops, $destLat, $destLon) ?? $destStops->first();

        $originRefLat = $originLat ?? (float) $firstOrigin->stop_lat;
        $originRefLon = $originLon ?? (float) $firstOrigin->stop_lon;
        $destRefLat = $destLat ?? (float) $firstDest->stop_lat;
        $destRefLon = $destLon ?? (float) $firstDest->stop_lon;

        // Group direct routes by route name — keep best (least total walk) per route
        foreach ($directRoutes as $route) {
            $routeKey = $route->route_short_name.'_'.$route->operator_name;
            $originWalkDist = $this->distancePointToStopFromCache($stopCache, $originRefLat, $originRefLon, $route->origin_stop_id);
            $destWalkDist = $this->distanceStopToPointFromCache($stopCache, $route->dest_stop_id, $destRefLat, $destRefLon);
            $totalWalkDist = $originWalkDist + $destWalkDist;

            if (! isset($routeOptions[$routeKey]) || $totalWalkDist < $routeOptions[$routeKey]['walk_dist']) {
                $routeOptions[$routeKey] = [
                    'route' => $route,
                    'walk_dist' => $totalWalkDist,
                    'origin_walk' => $originWalkDist,
                    'dest_walk' => $destWalkDist,
                ];
            }
        }

        // Build journeys from the best direct route option per route line
        foreach ($routeOptions as $option) {
            $route = $option['route'];
            $originWalkDist = $option['origin_walk'];
            $destWalkDist = $option['dest_walk'];
            $totalWalkDist = $option['walk_dist'];

            $legs = [];
            $busDepartureTime = substr($route->departure_time, 0, 5);
            $busArrivalTime = substr($route->arrival_time, 0, 5);

            if ($originWalkDist > 0.05) {
                $walkDuration = $this->calculateWalkingMinutes($originWalkDist, $walkingSpeedKmh);
                $walkEndTime = date('H:i', strtotime($busDepartureTime) - (2 * 60));
                $walkStartTime = date('H:i', strtotime($walkEndTime) - ($walkDuration * 60));

                if (strtotime($walkEndTime) >= strtotime($busDepartureTime)) {
                    continue;
                }

                $toCoords = $this->getStopCoordsFromCache($stopCache, $route->origin_stop_id);
                $legs[] = [
                    'type' => 'walk',
                    'from' => $originName,
                    'to' => $this->getStopNameFromCache($stopCache, $route->origin_stop_id),
                    'from_lat' => $originRefLat,
                    'from_lon' => $originRefLon,
                    'to_lat' => $toCoords['lat'] ?? null,
                    'to_lon' => $toCoords['lon'] ?? null,
                    'distance' => $originWalkDist,
                    'duration' => $walkDuration,
                    'start_time' => $walkStartTime,
                    'end_time' => $walkEndTime,
                ];
            }

            $transitStops = $this->fetchTripStops($route->trip_id, $route->origin_sequence, $route->dest_sequence);

            $transitShapePoints = [];
            if (! empty($route->shape_id) && ! empty($transitStops)) {
                $tFirst = $transitStops[0];
                $tLast = $transitStops[count($transitStops) - 1];
                $transitShapePoints = $this->getClippedShapePoints(
                    $route->shape_id,
                    (float) $tFirst['lat'],
                    (float) $tFirst['lon'],
                    (float) $tLast['lat'],
                    (float) $tLast['lon']
                );
            }

            $fromCoords = $this->getStopCoordsFromCache($stopCache, $route->origin_stop_id);
            $toCoords = $this->getStopCoordsFromCache($stopCache, $route->dest_stop_id);

            $legs[] = [
                'type' => 'transit',
                'route_name' => $route->route_short_name,
                'route_long_name' => $route->route_long_name,
                'route_type' => $route->route_type,
                'route_color' => $route->route_color,
                'route_text_color' => $route->route_text_color,
                'operator_name' => $route->operator_name,
                'trip_headsign' => $route->trip_headsign,
                'from' => $this->getStopNameFromCache($stopCache, $route->origin_stop_id),
                'to' => $route->dest_stop_name,
                'from_lat' => $fromCoords['lat'] ?? null,
                'from_lon' => $fromCoords['lon'] ?? null,
                'to_lat' => $toCoords['lat'] ?? null,
                'to_lon' => $toCoords['lon'] ?? null,
                'departure_time' => $busDepartureTime,
                'arrival_time' => $busArrivalTime,
                // FIX 8: midnight-safe duration
                'duration' => $this->timeDiffMinutes($busDepartureTime, $busArrivalTime),
                'stops' => $transitStops,
                'shape_pts' => $transitShapePoints,
            ];

            if ($destWalkDist > 0.05) {
                $walkDuration = $this->calculateWalkingMinutes($destWalkDist, $walkingSpeedKmh);
                $walkStartTime = $busArrivalTime;
                $fromCoords = $this->getStopCoordsFromCache($stopCache, $route->dest_stop_id);

                $legs[] = [
                    'type' => 'walk',
                    'from' => $this->getStopNameFromCache($stopCache, $route->dest_stop_id),
                    'to' => $destinationName,
                    'from_lat' => $fromCoords['lat'] ?? null,
                    'from_lon' => $fromCoords['lon'] ?? null,
                    'to_lat' => $destRefLat,
                    'to_lon' => $destRefLon,
                    'distance' => $destWalkDist,
                    'duration' => $walkDuration,
                    'start_time' => $walkStartTime,
                    'end_time' => date('H:i', strtotime($walkStartTime) + ($walkDuration * 60)),
                ];
            }

            $journey = $this->buildJourney($legs, $time);

            // FIX 8: midnight-safe scoring
            $totalTimeMinutes = $this->timeDiffMinutes($journey['leave_at'], $journey['arrival_time']);
            $journey['score'] = 10000 - ($totalTimeMinutes * 50) - ($journey['transfers'] * 200) - ($totalWalkDist * 10);

            if ($minimumDepartureTime && $date) {
                $leaveAtDateTime = Carbon::parse($date.' '.$journey['leave_at']);
                $minimumDateTime = Carbon::parse($date.' '.$minimumDepartureTime);
                if ($leaveAtDateTime->lt($minimumDateTime)) {
                    continue;
                }
            }

            $foundJourneys[] = $journey;
        }

        // FIX 4: Early exit — skip Strategy 1.5 and 2 if we already have enough results
        if (count($foundJourneys) >= 5) {
            return $this->sortAndLimitJourneys($foundJourneys, 5);
        }

        // STRATEGY 1.5: Direct routes including nearby origin/destination stops
        // FIX 1: Use PHP cache for nearby stop lookup — zero DB Haversine queries
        $nearbyOriginStops = $this->findNearbyStopIdsFromCache($stopCache, $originRefLat, $originRefLon, $maxWalkingDistance, $originStopIds);
        $nearbyDestStops = $this->findNearbyStopIdsFromCache($stopCache, $destRefLat, $destRefLon, $maxWalkingDistance, $destStopIds);

        $allOriginStopIds = array_unique(array_merge($originStopIds, array_column($nearbyOriginStops, 'id')));
        $allDestStopIds = array_unique(array_merge($destStopIds, array_column($nearbyDestStops, 'id')));

        // PRIORITY QUERY: exact origin stops → nearby dest stops
        // This runs first so exact-origin results are never pushed out by limit.
        $exactOriginNearbyDestRoutes = DB::table('stop_times as st_origin')
            ->join('trips as t', 'st_origin.trip_id', '=', 't.id')
            ->join('routes as r', 't.route_id', '=', 'r.id')
            ->join('users as u', 'r.tenant_id', '=', 'u.id')
            ->join('stop_times as st_dest', function ($join) use ($allDestStopIds) {
                $join->on('st_origin.trip_id', '=', 'st_dest.trip_id')
                    ->whereIn('st_dest.stop_id', $allDestStopIds)
                    ->whereRaw('st_dest.stop_sequence > st_origin.stop_sequence');
            })
            ->join('stops as dest_stop', 'st_dest.stop_id', '=', 'dest_stop.id')
            ->whereIn('st_origin.stop_id', $originStopIds)  // EXACT origin only
            ->whereIn('t.service_id', $activeServiceIds)
            ->where('st_origin.departure_time', '>=', $sqlMinTime)
            ->where('st_origin.departure_time', '<=', date('H:i:s', strtotime($sqlMinTime) + 10800))
            ->select(
                'st_origin.stop_id as origin_stop_id',
                'st_origin.departure_time',
                'st_dest.stop_id as dest_stop_id',
                'st_dest.arrival_time',
                'dest_stop.stop_name as dest_stop_name',
                'r.route_short_name',
                'r.route_long_name',
                'r.route_type',
                'r.route_color',
                'r.route_text_color',
                't.trip_headsign',
                't.id as trip_id',
                't.shape_id',
                'st_origin.stop_sequence as origin_sequence',
                'st_dest.stop_sequence as dest_sequence',
                'u.name as operator_name'
            )
            ->orderBy('st_origin.departure_time')
            ->limit(100)
            ->get();

        // SECONDARY QUERY: nearby origin stops → nearby dest stops (deprioritised)
        $nearbyDirectRoutes = DB::table('stop_times as st_origin')
            ->join('trips as t', 'st_origin.trip_id', '=', 't.id')
            ->join('routes as r', 't.route_id', '=', 'r.id')
            ->join('users as u', 'r.tenant_id', '=', 'u.id')
            ->join('stop_times as st_dest', function ($join) use ($allDestStopIds) {
                $join->on('st_origin.trip_id', '=', 'st_dest.trip_id')
                    ->whereIn('st_dest.stop_id', $allDestStopIds)
                    ->whereRaw('st_dest.stop_sequence > st_origin.stop_sequence');
            })
            ->join('stops as dest_stop', 'st_dest.stop_id', '=', 'dest_stop.id')
            ->whereIn('st_origin.stop_id', $allOriginStopIds)
            ->whereNotIn('st_origin.stop_id', $originStopIds)  // exclude exact (already handled)
            ->whereIn('t.service_id', $activeServiceIds)
            ->where('st_origin.departure_time', '>=', $sqlMinTime)
            ->where('st_origin.departure_time', '<=', date('H:i:s', strtotime($sqlMinTime) + 10800))
            ->select(
                'st_origin.stop_id as origin_stop_id',
                'st_origin.departure_time',
                'st_dest.stop_id as dest_stop_id',
                'st_dest.arrival_time',
                'dest_stop.stop_name as dest_stop_name',
                'r.route_short_name',
                'r.route_long_name',
                'r.route_type',
                'r.route_color',
                'r.route_text_color',
                't.trip_headsign',
                't.id as trip_id',
                't.shape_id',
                'st_origin.stop_sequence as origin_sequence',
                'st_dest.stop_sequence as dest_sequence',
                'u.name as operator_name'
            )
            ->orderBy('st_origin.departure_time')
            ->limit(100)
            ->get();

        // Merge: exact-origin results take priority (processed first)
        $nearbyDirectRoutes = $exactOriginNearbyDestRoutes->merge($nearbyDirectRoutes);

        // Process nearby routes — keep best (least walk) per route line
        $nearbyRouteOptions = [];
        foreach ($nearbyDirectRoutes as $route) {
            $routeKey = $route->route_short_name.'_'.$route->operator_name;
            $originWalkDist = $this->distancePointToStopFromCache($stopCache, $originRefLat, $originRefLon, $route->origin_stop_id);
            $destWalkDist = $this->distanceStopToPointFromCache($stopCache, $route->dest_stop_id, $destRefLat, $destRefLon);
            $totalWalkDist = $originWalkDist + $destWalkDist;

            $isBetter = ! isset($nearbyRouteOptions[$routeKey]);
            if (! $isBetter) {
                $currentBest = $nearbyRouteOptions[$routeKey];
                // Better if strictly less walk
                if ($totalWalkDist < $currentBest['walk_dist'] - 0.1) {
                    $isBetter = true;
                }
                // Or if walk is comparable (within 100m) but we get off LATER (higher dest_sequence)
                elseif ($totalWalkDist <= $currentBest['walk_dist'] + 0.1 && $route->dest_sequence > $currentBest['route']->dest_sequence) {
                    $isBetter = true;
                }
            }

            if ($isBetter) {
                $nearbyRouteOptions[$routeKey] = [
                    'route' => $route,
                    'walk_dist' => $totalWalkDist,
                    'origin_walk' => $originWalkDist,
                    'dest_walk' => $destWalkDist,
                ];
            }
        }

        foreach ($nearbyRouteOptions as $option) {
            $route = $option['route'];
            $originWalkDist = $option['origin_walk'];
            $destWalkDist = $option['dest_walk'];
            $totalWalkDist = $option['walk_dist'];

            $legs = [];
            $busDepartureTime = substr($route->departure_time, 0, 5);
            $busArrivalTime = substr($route->arrival_time, 0, 5);

            if ($originWalkDist > 0.05) {
                $walkDuration = $this->calculateWalkingMinutes($originWalkDist, $walkingSpeedKmh);
                $walkEndTime = date('H:i', strtotime($busDepartureTime) - (2 * 60));
                $walkStartTime = date('H:i', strtotime($walkEndTime) - ($walkDuration * 60));

                if (strtotime($walkEndTime) >= strtotime($busDepartureTime)) {
                    continue;
                }

                $toCoords = $this->getStopCoordsFromCache($stopCache, $route->origin_stop_id);
                $legs[] = [
                    'type' => 'walk',
                    'from' => $originName,
                    'to' => $this->getStopNameFromCache($stopCache, $route->origin_stop_id),
                    'from_lat' => $originRefLat,
                    'from_lon' => $originRefLon,
                    'to_lat' => $toCoords['lat'] ?? null,
                    'to_lon' => $toCoords['lon'] ?? null,
                    'distance' => $originWalkDist,
                    'duration' => $walkDuration,
                    'start_time' => $walkStartTime,
                    'end_time' => $walkEndTime,
                ];
            }

            $transitStops = $this->fetchTripStops($route->trip_id, $route->origin_sequence, $route->dest_sequence);
            $transitShapePoints = [];
            if (! empty($route->shape_id) && ! empty($transitStops)) {
                $tFirst = $transitStops[0];
                $tLast = $transitStops[count($transitStops) - 1];
                $transitShapePoints = $this->getClippedShapePoints(
                    $route->shape_id,
                    (float) $tFirst['lat'],
                    (float) $tFirst['lon'],
                    (float) $tLast['lat'],
                    (float) $tLast['lon']
                );
            }

            $fromCoords = $this->getStopCoordsFromCache($stopCache, $route->origin_stop_id);
            $toCoords = $this->getStopCoordsFromCache($stopCache, $route->dest_stop_id);

            $legs[] = [
                'type' => 'transit',
                'route_name' => $route->route_short_name,
                'route_long_name' => $route->route_long_name,
                'route_type' => $route->route_type,
                'route_color' => $route->route_color,
                'route_text_color' => $route->route_text_color,
                'operator_name' => $route->operator_name,
                'trip_headsign' => $route->trip_headsign,
                'from' => $this->getStopNameFromCache($stopCache, $route->origin_stop_id),
                'to' => $route->dest_stop_name,
                'from_lat' => $fromCoords['lat'] ?? null,
                'from_lon' => $fromCoords['lon'] ?? null,
                'to_lat' => $toCoords['lat'] ?? null,
                'to_lon' => $toCoords['lon'] ?? null,
                'departure_time' => $busDepartureTime,
                'arrival_time' => $busArrivalTime,
                'duration' => $this->timeDiffMinutes($busDepartureTime, $busArrivalTime),
                'stops' => $transitStops,
                'shape_pts' => $transitShapePoints,
            ];

            if ($destWalkDist > 0.05) {
                $walkDuration = $this->calculateWalkingMinutes($destWalkDist, $walkingSpeedKmh);
                $walkStartTime = $busArrivalTime;
                $fromCoords = $this->getStopCoordsFromCache($stopCache, $route->dest_stop_id);

                $legs[] = [
                    'type' => 'walk',
                    'from' => $route->dest_stop_name,
                    'to' => $destinationName,
                    'from_lat' => $fromCoords['lat'] ?? null,
                    'from_lon' => $fromCoords['lon'] ?? null,
                    'to_lat' => $destRefLat,
                    'to_lon' => $destRefLon,
                    'distance' => $destWalkDist,
                    'duration' => $walkDuration,
                    'start_time' => $walkStartTime,
                    'end_time' => date('H:i', strtotime($walkStartTime) + ($walkDuration * 60)),
                ];
            }

            $journey = $this->buildJourney($legs, $time);

            $totalTimeMinutes = $this->timeDiffMinutes($journey['leave_at'], $journey['arrival_time']);
            $journey['score'] = 10000 - ($totalTimeMinutes * 50) - ($journey['transfers'] * 200) - ($totalWalkDist * 10);

            if ($minimumDepartureTime && $date) {
                $leaveAtDateTime = Carbon::parse($date.' '.$journey['leave_at']);
                $minimumDateTime = Carbon::parse($date.' '.$minimumDepartureTime);
                if ($leaveAtDateTime->lt($minimumDateTime)) {
                    continue;
                }
            }

            $foundJourneys[] = $journey;
        }

        // FIX 4: Early exit — skip Strategy 2 if we already have enough results
        if (count($foundJourneys) >= 5) {
            return $this->sortAndLimitJourneys($foundJourneys, 5);
        }

        // STRATEGY 2: One-transfer routes — FIX 2: BATCHED inner loop (100 queries → 2)
        $expandedDestStopIds = array_unique(array_merge($destStopIds, array_column($nearbyDestStops, 'id')));

        $seenRoutes = [];
        $transferRoutes = DB::table('stop_times as st_origin')
            ->join('trips as t1', 'st_origin.trip_id', '=', 't1.id')
            ->join('routes as r1', 't1.route_id', '=', 'r1.id')
            ->join('users as u1', 'r1.tenant_id', '=', 'u1.id')
            ->join('stop_times as st_transfer', function ($join) {
                $join->on('st_origin.trip_id', '=', 'st_transfer.trip_id')
                    ->whereRaw('st_transfer.stop_sequence > st_origin.stop_sequence');
            })
            ->whereIn('st_origin.stop_id', $allOriginStopIds)
            ->whereIn('t1.service_id', $activeServiceIds)
            ->where('st_origin.departure_time', '>=', $sqlMinTime)
            ->where('st_origin.departure_time', '<=', date('H:i:s', strtotime($sqlMinTime) + 10800))
            ->select(
                'st_origin.stop_id as origin_stop_id',
                'st_origin.departure_time',
                'st_transfer.stop_id as transfer_stop_id',
                'st_transfer.arrival_time as transfer_arrival',
                'r1.route_short_name as first_route',
                'r1.route_long_name as first_route_long',
                'r1.route_type as first_route_type',
                'r1.route_color as first_color',
                'r1.route_text_color as first_text_color',
                't1.trip_headsign as first_headsign',
                't1.id as trip_id',
                't1.shape_id as first_shape_id',
                'st_origin.stop_sequence as origin_sequence',
                'st_transfer.stop_sequence as transfer_sequence',
                'u1.name as first_operator'
            )
            ->orderByRaw('CASE WHEN st_origin.stop_id IN ('.implode(',', array_map(fn ($id) => "'".addslashes($id)."'", $originStopIds)).') THEN 0 ELSE 1 END')
            ->orderBy('st_origin.departure_time')
            ->limit(300)
            ->get();

        // FIX 2: Pre-compute all nearby-transfer stop sets in PHP using the stop cache.
        // Previously: 1 Haversine DB query per first-leg candidate (up to 100 queries).
        // Now: 0 DB queries — all computed from the pre-loaded stop cache.
        $allTransferStopIds = $transferRoutes->pluck('transfer_stop_id')->unique()->values()->toArray();
        $nearbyByTransfer = []; // transfer_stop_id => [stop_id, ...]
        $allNearbyTransferStopIds = [];

        foreach ($allTransferStopIds as $transferStopId) {
            $transferStop = $stopCache[$transferStopId] ?? null;
            if (! $transferStop) {
                $nearbyByTransfer[$transferStopId] = [$transferStopId];
                $allNearbyTransferStopIds[] = $transferStopId;

                continue;
            }
            $nearbyStops = $this->findNearbyStopIdsFromCache($stopCache, (float) $transferStop->stop_lat, (float) $transferStop->stop_lon, $maxWalkingDistance);
            $nearbyIds = array_unique(array_merge([$transferStopId], array_column($nearbyStops, 'id')));
            $nearbyByTransfer[$transferStopId] = $nearbyIds;
            $allNearbyTransferStopIds = array_merge($allNearbyTransferStopIds, $nearbyIds);
        }
        $allNearbyTransferStopIds = array_unique($allNearbyTransferStopIds);

        // ONE batched second-leg query for ALL transfer points.
        // Previously: 1 query per first-leg candidate (up to 100 queries).
        // Now: 1 query for all candidates combined, then grouped for PHP lookup.
        $allSecondLegs = DB::table('stop_times as st2_start')
            ->join('trips as t2', 'st2_start.trip_id', '=', 't2.id')
            ->join('routes as r2', 't2.route_id', '=', 'r2.id')
            ->join('users as u2', 'r2.tenant_id', '=', 'u2.id')
            ->join('stop_times as st2_end', function ($join) use ($expandedDestStopIds) {
                $join->on('st2_start.trip_id', '=', 'st2_end.trip_id')
                    ->whereIn('st2_end.stop_id', $expandedDestStopIds)
                    ->whereRaw('st2_end.stop_sequence > st2_start.stop_sequence');
            })
            ->whereIn('t2.service_id', $activeServiceIds)
            ->whereIn('st2_start.stop_id', $allNearbyTransferStopIds)
            ->where('st2_start.departure_time', '>=', $sqlMinTime)
            ->where('st2_start.departure_time', '<=', date('H:i:s', strtotime($sqlMinTime) + 10800))
            ->select(
                't2.id as second_trip_id',
                'st2_start.stop_id as second_origin_stop_id',
                'st2_start.stop_sequence as second_origin_sequence',
                'st2_start.departure_time as second_departure',
                'st2_end.stop_id as final_stop_id',
                'st2_end.stop_sequence as final_stop_sequence',
                'st2_end.arrival_time as final_arrival',
                'r2.route_short_name as second_route_name',
                'r2.route_long_name as second_route_long_name',
                'r2.route_type as second_route_type',
                'r2.route_color as second_route_color',
                'r2.route_text_color as second_text_color',
                't2.trip_headsign as second_trip_headsign',
                't2.shape_id as second_shape_id',
                'u2.name as second_operator_name'
            )
            ->orderBy('st2_start.departure_time')
            ->get();

        $secondLegsGrouped = [];
        foreach ($allSecondLegs as $leg) {
            $secondLegsGrouped[$leg->second_origin_stop_id][] = $leg;
        }

        // Match first legs to pre-fetched second legs in PHP
        foreach ($transferRoutes as $firstLeg) {
            // FIX: Filter absurd 1st legs early (massive performance boost)
            $walkToTransferDist = $this->distancePointToStopFromCache($stopCache, $originRefLat, $originRefLon, $firstLeg->transfer_stop_id);
            $walkToTransferDuration = $this->calculateWalkingMinutes($walkToTransferDist, $walkingSpeedKmh);
            $bus1Duration = $this->timeDiffMinutes(substr($time, 0, 5), substr($firstLeg->transfer_arrival, 0, 5));

            if ($walkToTransferDuration <= $bus1Duration + 3) {
                continue; // Walking directly to transfer point is faster/same
            }

            $nearbyTransferIds = $nearbyByTransfer[$firstLeg->transfer_stop_id] ?? [$firstLeg->transfer_stop_id];
            $minDepartureTime = date('H:i:s', strtotime($firstLeg->transfer_arrival) + ($minTransferTime * 60));
            $maxDepartureTime = date('H:i:s', strtotime($firstLeg->transfer_arrival) + ($maxTransferWaitTime * 60));

            // Collect matching second legs from pre-fetched data (0 DB queries) using raw arrays
            $secondLegOptions = [];
            foreach ($nearbyTransferIds as $nearbyStopId) {
                if (! isset($secondLegsGrouped[$nearbyStopId])) {
                    continue;
                }
                foreach ($secondLegsGrouped[$nearbyStopId] as $leg) {
                    if ($leg->second_departure >= $minDepartureTime && $leg->second_departure <= $maxDepartureTime) {
                        $secondLegOptions[] = $leg;
                    }
                }
            }

            // Pick the best second leg: minimise (arrival at dest + final walk)
            $secondLeg = null;
            $bestArrivalAtDest = PHP_INT_MAX;
            $bestTransferWalk = PHP_INT_MAX;
            foreach ($secondLegOptions as $option) {
                $optWalkDist = $this->distanceStopToPointFromCache($stopCache, $option->final_stop_id, $destRefLat, $destRefLon);
                $optWalkMin = (int) round($optWalkDist / $walkingSpeedKmh * 60);
                $optArrival = strtotime('1970-01-01 '.$option->final_arrival) + ($optWalkMin * 60);
                $optTransferWalk = $this->getWalkingDistanceFromCache($stopCache, $firstLeg->transfer_stop_id, $option->second_origin_stop_id);
                if ($optArrival < $bestArrivalAtDest || ($optArrival === $bestArrivalAtDest && $optTransferWalk < $bestTransferWalk)) {
                    $bestArrivalAtDest = $optArrival;
                    $bestTransferWalk = $optTransferWalk;
                    $secondLeg = $option;
                }
            }

            if ($secondLeg) {
                // Deduplicate by (first_trip_id + transfer_stop + second_trip_id) so different
                // transfer points on the same pair of trips are all evaluated independently.
                // sortAndLimitJourneys will then pick the best-scoring one per departure hour.
                $transferRouteKey = $firstLeg->trip_id.'_'.$firstLeg->transfer_stop_id.'_to_'.$secondLeg->second_trip_id;
                if (isset($seenRoutes[$transferRouteKey])) {
                    continue;
                }
                $seenRoutes[$transferRouteKey] = true;

                // Calculate actual wait time at transfer
                $waitTime = (strtotime($secondLeg->second_departure) - strtotime($firstLeg->transfer_arrival)) / 60;

                // Skip if wait time is too long (should be caught by query, but double-check)
                if ($waitTime > $maxTransferWaitTime) {
                    continue;
                }

                $transferWalkDist = $this->getWalkingDistanceFromCache($stopCache, $firstLeg->transfer_stop_id, $secondLeg->second_origin_stop_id);
                $transferWalkDuration = $this->calculateWalkingMinutes($transferWalkDist, $walkingSpeedKmh);

                // FIX: Filter out garbage routes where walking is faster than taking the bus leg
                // Check Leg 1 (is Bus 1 useless?)
                $walkToSecondOriginDist = $this->distancePointToStopFromCache($stopCache, $originRefLat, $originRefLon, $secondLeg->second_origin_stop_id);
                $walkToSecondOriginDuration = $this->calculateWalkingMinutes($walkToSecondOriginDist, $walkingSpeedKmh);

                $bus1Duration = $this->timeDiffMinutes(substr($time, 0, 5), substr($firstLeg->transfer_arrival, 0, 5)) + $transferWalkDuration;
                if ($walkToSecondOriginDuration <= $bus1Duration + 3) {
                    continue; // Walking directly is faster/same as Bus 1
                }

                // Check Leg 2 (is Bus 2 useless?)
                $walkToDestDist = $this->distanceStopToPointFromCache($stopCache, $firstLeg->transfer_stop_id, $destRefLat, $destRefLon);
                $walkToDestDuration = $this->calculateWalkingMinutes($walkToDestDist, $walkingSpeedKmh);

                $bus2Duration = $this->timeDiffMinutes(substr($firstLeg->transfer_arrival, 0, 5), substr($secondLeg->final_arrival, 0, 5));
                $finalWalkDist = $this->distanceStopToPointFromCache($stopCache, $secondLeg->final_stop_id, $destRefLat, $destRefLon);
                $finalWalkDuration = $this->calculateWalkingMinutes($finalWalkDist, $walkingSpeedKmh);

                if ($walkToDestDuration <= $bus2Duration + $finalWalkDuration + 3) {
                    continue; // Walking directly is faster/same as Bus 2
                }

                $firstLegStops = $this->fetchTripStops($firstLeg->trip_id, $firstLeg->origin_sequence, $firstLeg->transfer_sequence);
                $firstShapePoints = [];
                if (! empty($firstLeg->first_shape_id) && ! empty($firstLegStops)) {
                    $fFirst = $firstLegStops[0];
                    $fLast = $firstLegStops[count($firstLegStops) - 1];
                    $firstShapePoints = $this->getClippedShapePoints(
                        $firstLeg->first_shape_id,
                        (float) $fFirst['lat'],
                        (float) $fFirst['lon'],
                        (float) $fLast['lat'],
                        (float) $fLast['lon']
                    );
                }

                $firstFromCoords = $this->getStopCoordsFromCache($stopCache, $firstLeg->origin_stop_id);
                $firstToCoords = $this->getStopCoordsFromCache($stopCache, $firstLeg->transfer_stop_id);

                $legs = [];

                $originWalkDist = $this->distancePointToStopFromCache($stopCache, $originRefLat, $originRefLon, $firstLeg->origin_stop_id);
                if ($originWalkDist > 0.05) {
                    $originWalkDuration = $this->calculateWalkingMinutes($originWalkDist, $walkingSpeedKmh);
                    $firstDepartureTime = substr($firstLeg->departure_time, 0, 5);
                    $originWalkEnd = date('H:i', strtotime($firstDepartureTime) - (2 * 60));
                    $originWalkStart = date('H:i', strtotime($originWalkEnd) - ($originWalkDuration * 60));

                    if (strtotime($originWalkEnd) >= strtotime($firstDepartureTime)) {
                        continue;
                    }

                    $legs[] = [
                        'type' => 'walk',
                        'from' => $originName,
                        'to' => $this->getStopNameFromCache($stopCache, $firstLeg->origin_stop_id),
                        'from_lat' => $originRefLat,
                        'from_lon' => $originRefLon,
                        'to_lat' => $firstFromCoords['lat'] ?? null,
                        'to_lon' => $firstFromCoords['lon'] ?? null,
                        'distance' => $originWalkDist,
                        'duration' => $originWalkDuration,
                        'start_time' => $originWalkStart,
                        'end_time' => $originWalkEnd,
                    ];
                }

                $legs[] = [
                    'type' => 'transit',
                    'route_name' => $firstLeg->first_route,
                    'route_long_name' => $firstLeg->first_route_long,
                    'route_type' => $firstLeg->first_route_type,
                    'route_color' => $firstLeg->first_color,
                    'route_text_color' => $firstLeg->first_text_color,
                    'operator_name' => $firstLeg->first_operator,
                    'trip_headsign' => $firstLeg->first_headsign,
                    'from' => $this->getStopNameFromCache($stopCache, $firstLeg->origin_stop_id),
                    'to' => $this->getStopNameFromCache($stopCache, $firstLeg->transfer_stop_id),
                    'from_lat' => $firstFromCoords['lat'] ?? null,
                    'from_lon' => $firstFromCoords['lon'] ?? null,
                    'to_lat' => $firstToCoords['lat'] ?? null,
                    'to_lon' => $firstToCoords['lon'] ?? null,
                    'departure_time' => substr($firstLeg->departure_time, 0, 5),
                    'arrival_time' => substr($firstLeg->transfer_arrival, 0, 5),
                    'duration' => $this->timeDiffMinutes(substr($firstLeg->departure_time, 0, 5), substr($firstLeg->transfer_arrival, 0, 5)),
                    'stops' => $firstLegStops,
                    'shape_pts' => $firstShapePoints,
                ];

                // Only add walking between stops if there's real distance
                if ($transferWalkDist > 0.05) {
                    $walkDuration = $this->calculateWalkingTimeFromCache($stopCache, $firstLeg->transfer_stop_id, $secondLeg->second_origin_stop_id, $walkingSpeedKmh);
                    $walkStartTime = substr($firstLeg->transfer_arrival, 0, 5);
                    $walkEndTime = date('H:i', strtotime($walkStartTime) + ($walkDuration * 60));
                    $secondBusDeparture = substr($secondLeg->second_departure, 0, 5);

                    if (strtotime($secondBusDeparture) < strtotime($walkEndTime)) {
                        continue; // walk ends after bus departs — impossible
                    }

                    $fromCoords = $this->getStopCoordsFromCache($stopCache, $firstLeg->transfer_stop_id);
                    $toCoords = $this->getStopCoordsFromCache($stopCache, $secondLeg->second_origin_stop_id);

                    $legs[] = [
                        'type' => 'walk',
                        'from' => $this->getStopNameFromCache($stopCache, $firstLeg->transfer_stop_id),
                        'to' => $this->getStopNameFromCache($stopCache, $secondLeg->second_origin_stop_id),
                        'from_lat' => $fromCoords['lat'] ?? null,
                        'from_lon' => $fromCoords['lon'] ?? null,
                        'to_lat' => $toCoords['lat'] ?? null,
                        'to_lon' => $toCoords['lon'] ?? null,
                        'distance' => $transferWalkDist,
                        'duration' => $walkDuration,
                        'start_time' => $walkStartTime,
                        'end_time' => $walkEndTime,
                    ];
                }

                $secondLegStops = $this->fetchTripStops($secondLeg->second_trip_id, $secondLeg->second_origin_sequence, $secondLeg->final_stop_sequence);
                $secondShapePoints = [];
                if (! empty($secondLeg->second_shape_id) && ! empty($secondLegStops)) {
                    $sFirst = $secondLegStops[0];
                    $sLast = $secondLegStops[count($secondLegStops) - 1];
                    $secondShapePoints = $this->getClippedShapePoints(
                        $secondLeg->second_shape_id,
                        (float) $sFirst['lat'],
                        (float) $sFirst['lon'],
                        (float) $sLast['lat'],
                        (float) $sLast['lon']
                    );
                }

                $secondFromCoords = $this->getStopCoordsFromCache($stopCache, $secondLeg->second_origin_stop_id);
                $secondToCoords = $this->getStopCoordsFromCache($stopCache, $secondLeg->final_stop_id);

                $legs[] = [
                    'type' => 'transit',
                    'route_name' => $secondLeg->second_route_name,
                    'route_long_name' => $secondLeg->second_route_long_name,
                    'route_type' => $secondLeg->second_route_type,
                    'route_color' => $secondLeg->second_route_color,
                    'route_text_color' => $secondLeg->second_text_color,
                    'operator_name' => $secondLeg->second_operator_name,
                    'trip_headsign' => $secondLeg->second_trip_headsign,
                    'from' => $this->getStopNameFromCache($stopCache, $secondLeg->second_origin_stop_id),
                    'to' => $this->getStopNameFromCache($stopCache, $secondLeg->final_stop_id),
                    'from_lat' => $secondFromCoords['lat'] ?? null,
                    'from_lon' => $secondFromCoords['lon'] ?? null,
                    'to_lat' => $secondToCoords['lat'] ?? null,
                    'to_lon' => $secondToCoords['lon'] ?? null,
                    'departure_time' => substr($secondLeg->second_departure, 0, 5),
                    'arrival_time' => substr($secondLeg->final_arrival, 0, 5),
                    'duration' => $this->timeDiffMinutes(substr($secondLeg->second_departure, 0, 5), substr($secondLeg->final_arrival, 0, 5)),
                    'stops' => $secondLegStops,
                    'shape_pts' => $secondShapePoints,
                ];

                // Add final walk leg if the second leg alights at a nearby (non-exact) destination stop
                $finalWalkDist = 0.0;
                if (! in_array($secondLeg->final_stop_id, $destStopIds)) {
                    $finalWalkDist = $this->getWalkingDistanceFromCache($stopCache, $secondLeg->final_stop_id, $firstDest->id);
                    if ($finalWalkDist > 0.05) {
                        $finalWalkDuration = $this->calculateWalkingTimeFromCache($stopCache, $secondLeg->final_stop_id, $firstDest->id, $walkingSpeedKmh);
                        $finalWalkStart = substr($secondLeg->final_arrival, 0, 5);
                        $finalWalkEnd = date('H:i', strtotime($finalWalkStart) + ($finalWalkDuration * 60));
                        $finalFromCoords = $this->getStopCoordsFromCache($stopCache, $secondLeg->final_stop_id);

                        $legs[] = [
                            'type' => 'walk',
                            'from' => $this->getStopNameFromCache($stopCache, $secondLeg->final_stop_id),
                            'to' => $destinationName,
                            'from_lat' => $finalFromCoords['lat'] ?? null,
                            'from_lon' => $finalFromCoords['lon'] ?? null,
                            'to_lat' => (float) $firstDest->stop_lat,
                            'to_lon' => (float) $firstDest->stop_lon,
                            'distance' => $finalWalkDist,
                            'duration' => $finalWalkDuration,
                            'start_time' => $finalWalkStart,
                            'end_time' => $finalWalkEnd,
                        ];
                    }
                }

                $journey = $this->buildJourney($legs, $time);

                // FIX 8: midnight-safe scoring
                $totalTimeMinutes = $this->timeDiffMinutes($journey['leave_at'], $journey['arrival_time']);
                $journey['score'] = 10000 - ($totalTimeMinutes * 50) - ($journey['transfers'] * 200) - (($originWalkDist + $transferWalkDist + $finalWalkDist) * 10);

                if ($minimumDepartureTime && $date) {
                    $leaveAtDateTime = Carbon::parse($date.' '.$journey['leave_at']);
                    $minimumDateTime = Carbon::parse($date.' '.$minimumDepartureTime);
                    if ($leaveAtDateTime->lt($minimumDateTime)) {
                        continue;
                    }
                }

                $foundJourneys[] = $journey;

                if (count($foundJourneys) >= 20) {
                    break;
                }
            }
        }

        // If still nothing found, try slightly wider destination radius using PHP cache (no extra DB call)
        if (empty($foundJourneys)) {
            $widerDestStops = $this->findNearbyStopIdsFromCache($stopCache, (float) $firstDest->stop_lat, (float) $firstDest->stop_lon, 2.0, $expandedDestStopIds);
            foreach ($widerDestStops as $nearby) {
                if (! in_array($nearby->id, $expandedDestStopIds)) {
                    $expandedDestStopIds[] = $nearby->id;
                }
            }
        }
        // FIX: Filter absurd transit routes
        $directWalkDistanceKm = ($originLat && $destLat) ? $this->haversineDistance($originLat, $originLon, $destLat, $destLon) * 1.3 : 0;
        $directWalkDuration = $directWalkDistanceKm > 0 ? $this->calculateWalkingMinutes($directWalkDistanceKm, 4.0) : 0;

        if ($directWalkDuration > 0) {
            $filteredJourneys = [];
            foreach ($foundJourneys as $journey) {
                // Allow GBFS (bike/car) routes to pass through
                if (! empty($journey['is_gbfs'])) {
                    $filteredJourneys[] = $journey;

                    continue;
                }

                $totalTimeMinutes = $this->timeDiffMinutes($journey['leave_at'], $journey['arrival_time']);
                $totalWalkDist = 0;
                $isBacktracking = false;
                $seenTransitRouteNames = [];
                $path = [];

                foreach ($journey['legs'] as $leg) {
                    if ($leg['type'] === 'walk') {
                        $totalWalkDist += $leg['distance'] ?? 0;
                    }

                    if (isset($leg['from']) && (empty($path) || end($path) !== $leg['from'])) {
                        $path[] = $leg['from'];
                    }
                    if (isset($leg['to']) && (empty($path) || end($path) !== $leg['to'])) {
                        $path[] = $leg['to'];
                    }

                    if (isset($leg['route_name']) && $leg['type'] === 'transit') {
                        // Check if we take the exact same bus/train line multiple times in one journey (yo-yo route)
                        if (in_array($leg['route_name'], $seenTransitRouteNames)) {
                            $isBacktracking = true;
                            break;
                        }
                        $seenTransitRouteNames[] = $leg['route_name'];
                    }
                }

                if (count(array_unique($path)) < count($path)) {
                    $isBacktracking = true;
                }

                if ($isBacktracking) {
                    continue;
                }

                // If the transit journey takes significantly longer than simply walking directly, drop it!
                if ($totalTimeMinutes > ($directWalkDuration + 10)) {
                    continue;
                }

                // If you're walking almost as much as the direct route, the transit route is absurd
                if ($totalWalkDist >= ($directWalkDistanceKm * 0.8)) {
                    continue;
                }

                $filteredJourneys[] = $journey;
            }
            $foundJourneys = $filteredJourneys;
        }

        return $this->sortAndLimitJourneys($foundJourneys, 5);
    }

    /**
     * FIX 6: Sort and deduplicate journeys by route identity instead of departure hour.
     *
     * Old behaviour: one result per hour → discards valid options that depart the same hour.
     * New behaviour: one best-scored journey per (first_route_name + transfer_count),
     * returning up to $limit sorted by score descending.
     *
     * @param  array<int, array<string, mixed>>  $journeys
     * @return array<int, array<string, mixed>>
     */
    private function sortAndLimitJourneys(array $journeys, int $limit): array
    {
        usort($journeys, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $seen = [];
        $result = [];

        foreach ($journeys as $journey) {
            $firstTransit = collect($journey['legs'])->firstWhere('type', 'transit');
            $routeKey = ($firstTransit['route_name'] ?? 'unknown').'_'.($journey['transfers'] ?? 0);

            if (! isset($seen[$routeKey])) {
                $seen[$routeKey] = true;
                $result[] = $journey;
            }

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    private function findTrunkRoutes(
        $originStopIds,
        $destStopIds,
        $originName,
        $destinationName,
        $time,
        $activeServiceIds,
        $minimumDepartureTime = null,
        $date = null,
        ?float $originLat = null,
        ?float $originLon = null,
        ?float $destLat = null,
        ?float $destLon = null
    ) {
        $foundJourneys = [];
        if (! $originLat || ! $destLat) {
            return [];
        }

        $stopCache = $this->preloadStopCache();
        $maxWalkingDistance = 1.5;  // km for local feeders
        $trunkSearchRadius = 25.0;  // km for trunk stations

        $nearbyOriginStops = $this->findNearbyStopIdsFromCache($stopCache, $originLat, $originLon, $trunkSearchRadius);
        $nearbyDestStops = $this->findNearbyStopIdsFromCache($stopCache, $destLat, $destLon, $trunkSearchRadius);

        $originNearbyIds = array_column($nearbyOriginStops, 'id');
        $destNearbyIds = array_column($nearbyDestStops, 'id');

        if (empty($originNearbyIds) || empty($destNearbyIds)) {
            return [];
        }

        $sqlMinTime = date('H:i:s', strtotime($time) - (60 * 60)); // 1 hour before
        $sqlMaxTime = date('H:i:s', strtotime($time) + (180 * 60)); // 3 hours after

        // 1. Find Trunk Trips (Trains)
        $trunkTrips = DB::table('stop_times as st_origin')
            ->join('trips as t', 'st_origin.trip_id', '=', 't.id')
            ->join('routes as r', 't.route_id', '=', 'r.id')
            ->join('users as u', 'r.tenant_id', '=', 'u.id')
            ->join('stop_times as st_dest', function ($join) use ($destNearbyIds) {
                $join->on('st_origin.trip_id', '=', 'st_dest.trip_id')
                    ->whereIn('st_dest.stop_id', $destNearbyIds)
                    ->whereRaw('st_dest.stop_sequence > st_origin.stop_sequence');
            })
            ->where('r.route_type', 2)
            ->whereIn('st_origin.stop_id', $originNearbyIds)
            ->whereIn('t.service_id', $activeServiceIds)
            ->whereBetween('st_origin.departure_time', [$sqlMinTime, $sqlMaxTime])
            ->select(
                't.id as trunk_trip_id',
                'st_origin.stop_id as trunk_origin_stop',
                'st_origin.departure_time as trunk_departure',
                'st_dest.stop_id as trunk_dest_stop',
                'st_dest.arrival_time as trunk_arrival',
                'st_origin.stop_sequence as origin_sequence',
                'st_dest.stop_sequence as dest_sequence',
                'r.route_short_name as trunk_route_name',
                'r.route_long_name as trunk_route_long',
                'r.route_type as trunk_route_type',
                'r.route_color as trunk_color',
                'r.route_text_color as trunk_text_color',
                'u.name as trunk_operator',
                't.shape_id as trunk_shape_id'
            )
            ->get();

        if ($trunkTrips->isEmpty()) {
            return [];
        }

        $trunkOriginStops = $trunkTrips->pluck('trunk_origin_stop')->unique()->toArray();
        $trunkDestStops = $trunkTrips->pluck('trunk_dest_stop')->unique()->toArray();

        // Expand to include nearby local stops for the trunk stations (for local buses to drop off)
        $expandedTrunkOrigins = [];
        foreach ($trunkOriginStops as $ts) {
            $s = $stopCache[$ts] ?? null;
            if ($s) {
                $near = $this->findNearbyStopIdsFromCache($stopCache, (float) $s->stop_lat, (float) $s->stop_lon, $maxWalkingDistance);
                $expandedTrunkOrigins = array_merge($expandedTrunkOrigins, array_column($near, 'id'));
            }
            $expandedTrunkOrigins[] = $ts;
        }
        $expandedTrunkOrigins = array_unique($expandedTrunkOrigins);

        $expandedTrunkDests = [];
        foreach ($trunkDestStops as $ts) {
            $s = $stopCache[$ts] ?? null;
            if ($s) {
                $near = $this->findNearbyStopIdsFromCache($stopCache, (float) $s->stop_lat, (float) $s->stop_lon, $maxWalkingDistance);
                $expandedTrunkDests = array_merge($expandedTrunkDests, array_column($near, 'id'));
            }
            $expandedTrunkDests[] = $ts;
        }
        $expandedTrunkDests = array_unique($expandedTrunkDests);

        // Origin local stops
        $localOriginStops = $this->findNearbyStopIdsFromCache($stopCache, $originLat, $originLon, $maxWalkingDistance);
        $localOriginIds = array_unique(array_merge($originStopIds, array_column($localOriginStops, 'id')));

        // Dest local stops
        $localDestStops = $this->findNearbyStopIdsFromCache($stopCache, $destLat, $destLon, $maxWalkingDistance);
        $localDestIds = array_unique(array_merge($destStopIds, array_column($localDestStops, 'id')));

        // 2. Fetch Feeder 1 (Origin to Trunk Origin)
        $feeder1Trips = DB::table('stop_times as st_start')
            ->join('trips as t', 'st_start.trip_id', '=', 't.id')
            ->join('routes as r', 't.route_id', '=', 'r.id')
            ->join('users as u', 'r.tenant_id', '=', 'u.id')
            ->join('stop_times as st_end', function ($join) use ($expandedTrunkOrigins) {
                $join->on('st_start.trip_id', '=', 'st_end.trip_id')
                    ->whereIn('st_end.stop_id', $expandedTrunkOrigins)
                    ->whereRaw('st_end.stop_sequence > st_start.stop_sequence');
            })
            ->whereIn('st_start.stop_id', $localOriginIds)
            ->whereIn('t.service_id', $activeServiceIds)
            ->whereBetween('st_start.departure_time', [$sqlMinTime, $sqlMaxTime])
            ->select(
                't.id as f1_trip_id',
                'st_start.stop_id as f1_origin_stop',
                'st_start.departure_time as f1_departure',
                'st_end.stop_id as f1_dest_stop',
                'st_end.arrival_time as f1_arrival',
                'st_start.stop_sequence as f1_origin_sequence',
                'st_end.stop_sequence as f1_dest_sequence',
                'r.route_short_name as f1_route_name',
                'r.route_long_name as f1_route_long',
                'r.route_type as f1_route_type',
                'r.route_color as f1_color',
                'r.route_text_color as f1_text_color',
                'u.name as f1_operator',
                't.shape_id as f1_shape_id'
            )
            ->get();

        $feeder1Grouped = []; // Group by f1_dest_stop
        foreach ($feeder1Trips as $f1) {
            $feeder1Grouped[$f1->f1_dest_stop][] = $f1;
        }

        // 3. Fetch Feeder 2 (Trunk Dest to Destination)
        $feeder2Trips = DB::table('stop_times as st_start')
            ->join('trips as t', 'st_start.trip_id', '=', 't.id')
            ->join('routes as r', 't.route_id', '=', 'r.id')
            ->join('users as u', 'r.tenant_id', '=', 'u.id')
            ->join('stop_times as st_end', function ($join) use ($localDestIds) {
                $join->on('st_start.trip_id', '=', 'st_end.trip_id')
                    ->whereIn('st_end.stop_id', $localDestIds)
                    ->whereRaw('st_end.stop_sequence > st_start.stop_sequence');
            })
            ->whereIn('st_start.stop_id', $expandedTrunkDests)
            ->whereIn('t.service_id', $activeServiceIds)
            ->whereBetween('st_start.departure_time', [$sqlMinTime, date('H:i:s', strtotime($sqlMaxTime) + 7200)])
            ->select(
                't.id as f2_trip_id',
                'st_start.stop_id as f2_origin_stop',
                'st_start.departure_time as f2_departure',
                'st_end.stop_id as f2_dest_stop',
                'st_end.arrival_time as f2_arrival',
                'st_start.stop_sequence as f2_origin_sequence',
                'st_end.stop_sequence as f2_dest_sequence',
                'r.route_short_name as f2_route_name',
                'r.route_long_name as f2_route_long',
                'r.route_type as f2_route_type',
                'r.route_color as f2_color',
                'r.route_text_color as f2_text_color',
                'u.name as f2_operator',
                't.shape_id as f2_shape_id'
            )
            ->get();

        $feeder2Grouped = []; // Group by f2_origin_stop
        foreach ($feeder2Trips as $f2) {
            $feeder2Grouped[$f2->f2_origin_stop][] = $f2;
        }

        // 4. Assemble Journeys
        $minTransferTime = 5; // minutes
        $maxTransferTime = 120; // minutes
        $walkingSpeedKmh = 6.5;

        // Pre-fetch origin and destination GBFS bike stations
        $originGbfsBike = $this->getNearestGbfsStation($originLat, $originLon, true, 'cycle');
        $destGbfsBike = $this->getNearestGbfsStation($destLat, $destLon, false, 'cycle');

        $gbfsTrunkOriginCache = []; // trunk_origin_stop -> GBFS station with docks
        $gbfsTrunkDestCache = [];   // trunk_dest_stop -> GBFS station with bikes

        foreach ($trunkTrips as $trunk) {
            $trunkOriginStopObj = $stopCache[$trunk->trunk_origin_stop] ?? null;
            $trunkDestStopObj = $stopCache[$trunk->trunk_dest_stop] ?? null;
            if (! $trunkOriginStopObj || ! $trunkDestStopObj) {
                continue;
            }

            // Spatial Yo-yo Filter: Trunk leg shouldn't take us backwards!
            if ($originLat && $originLon && $destLat && $destLon) {
                $originToTrunkStart = $this->haversineDistance($originLat, $originLon, (float) $trunkOriginStopObj->stop_lat, (float) $trunkOriginStopObj->stop_lon);
                $originToTrunkEnd = $this->haversineDistance($originLat, $originLon, (float) $trunkDestStopObj->stop_lat, (float) $trunkDestStopObj->stop_lon);

                // If trunk takes us significantly closer to our origin than where we boarded it...
                if ($originToTrunkEnd < $originToTrunkStart - 2.0) {
                    continue;
                }

                // OR if trunk takes us significantly further from our destination...
                $destToTrunkStart = $this->haversineDistance($destLat, $destLon, (float) $trunkOriginStopObj->stop_lat, (float) $trunkOriginStopObj->stop_lon);
                $destToTrunkEnd = $this->haversineDistance($destLat, $destLon, (float) $trunkDestStopObj->stop_lat, (float) $trunkDestStopObj->stop_lon);
                if ($destToTrunkEnd > $destToTrunkStart + 2.0) {
                    continue;
                }
            }

            // Find acceptable Feeder 1
            $f1Options = [];

            // Direct walk to Trunk?
            $walkToTrunkDist = $this->distancePointToStopFromCache($stopCache, $originLat, $originLon, $trunk->trunk_origin_stop);
            $walkToTrunkDuration = $this->calculateWalkingMinutes($walkToTrunkDist, $walkingSpeedKmh);

            if ($walkToTrunkDist <= $maxWalkingDistance) {
                // Pseudo Feeder1 (Walk)
                $f1Options[] = ['type' => 'walk', 'distance' => $walkToTrunkDist, 'duration' => $walkToTrunkDuration];
            }

            // Pseudo Feeder1 (GBFS Cycle)
            if ($originGbfsBike) {
                if (! array_key_exists($trunk->trunk_origin_stop, $gbfsTrunkOriginCache)) {
                    $gbfsTrunkOriginCache[$trunk->trunk_origin_stop] = $this->getNearestGbfsStation((float) $trunkOriginStopObj->stop_lat, (float) $trunkOriginStopObj->stop_lon, false, 'cycle');
                }
                $trunkGbfsBike = $gbfsTrunkOriginCache[$trunk->trunk_origin_stop];

                if ($trunkGbfsBike && $originGbfsBike->id !== $trunkGbfsBike->id) {
                    $walkToBike = $this->haversineDistance($originLat, $originLon, $originGbfsBike->lat, $originGbfsBike->lon);
                    $bikeRide = $this->haversineDistance($originGbfsBike->lat, $originGbfsBike->lon, $trunkGbfsBike->lat, $trunkGbfsBike->lon);
                    $walkToTrunk = $this->haversineDistance($trunkGbfsBike->lat, $trunkGbfsBike->lon, (float) $trunkOriginStopObj->stop_lat, (float) $trunkOriginStopObj->stop_lon);

                    // Only add if it's reasonably close (walk < 1.5km, ride > 0.5km to make it worth it)
                    if ($walkToBike <= $maxWalkingDistance && $walkToTrunk <= $maxWalkingDistance && $bikeRide > 0.5) {
                        $walkToBikeDur = $this->calculateWalkingMinutes($walkToBike, $walkingSpeedKmh);
                        $bikeRideDur = (int) round($bikeRide * 4); // 15km/h = 4 min/km
                        $walkToTrunkDur = $this->calculateWalkingMinutes($walkToTrunk, $walkingSpeedKmh);

                        $totalGbfsFeederDur = $walkToBikeDur + $bikeRideDur + $walkToTrunkDur + 2; // +2 mins for unlock/lock

                        $f1Options[] = [
                            'type' => 'gbfs',
                            'sub_type' => 'cycle',
                            'duration' => $totalGbfsFeederDur,
                            'bikeOrigin' => $originGbfsBike,
                            'bikeDest' => $trunkGbfsBike,
                            'walk1_dist' => $walkToBike,
                            'ride_dist' => $bikeRide,
                            'walk2_dist' => $walkToTrunk,
                            'walk1_min' => $walkToBikeDur,
                            'ride_min' => $bikeRideDur,
                            'walk2_min' => $walkToTrunkDur,
                            'operator_name' => $originGbfsBike->source->tenant->name ?? 'Shared Bike',
                        ];
                    }
                }
            }

            // Transit Feeder1
            $nearbyTrunkOrigins = $this->findNearbyStopIdsFromCache($stopCache, (float) $trunkOriginStopObj->stop_lat, (float) $trunkOriginStopObj->stop_lon, $maxWalkingDistance);
            $nearbyTrunkOriginIds = array_merge([$trunk->trunk_origin_stop], array_column($nearbyTrunkOrigins, 'id'));

            foreach ($nearbyTrunkOriginIds as $nToId) {
                if (isset($feeder1Grouped[$nToId])) {
                    foreach ($feeder1Grouped[$nToId] as $f1) {
                        $transferWalk = $this->getWalkingDistanceFromCache($stopCache, $f1->f1_dest_stop, $trunk->trunk_origin_stop);
                        $transferWalkMin = $this->calculateWalkingMinutes($transferWalk, $walkingSpeedKmh);

                        $f1Arrival = strtotime($f1->f1_arrival);
                        $trunkDeparture = strtotime($trunk->trunk_departure);

                        if ($f1Arrival + (($transferWalkMin + $minTransferTime) * 60) <= $trunkDeparture &&
                            $f1Arrival + ($maxTransferTime * 60) >= $trunkDeparture) {
                            $f1Options[] = ['type' => 'transit', 'leg' => $f1, 'transfer_walk' => $transferWalk, 'transfer_walk_min' => $transferWalkMin];
                        }
                    }
                }
            }

            if (empty($f1Options)) {
                continue;
            }

            // Find acceptable Feeder 2
            $f2Options = [];
            $trunkDestStopObj = $stopCache[$trunk->trunk_dest_stop] ?? null;
            if (! $trunkDestStopObj) {
                continue;
            }

            $walkFromTrunkDist = $this->distanceStopToPointFromCache($stopCache, $trunk->trunk_dest_stop, $destLat, $destLon);
            $walkFromTrunkDuration = $this->calculateWalkingMinutes($walkFromTrunkDist, $walkingSpeedKmh);

            if ($walkFromTrunkDist <= $maxWalkingDistance) {
                $f2Options[] = ['type' => 'walk', 'distance' => $walkFromTrunkDist, 'duration' => $walkFromTrunkDuration];
            }

            // Pseudo Feeder2 (GBFS Cycle)
            if ($destGbfsBike) {
                if (! array_key_exists($trunk->trunk_dest_stop, $gbfsTrunkDestCache)) {
                    $gbfsTrunkDestCache[$trunk->trunk_dest_stop] = $this->getNearestGbfsStation((float) $trunkDestStopObj->stop_lat, (float) $trunkDestStopObj->stop_lon, true, 'cycle');
                }
                $trunkGbfsBike = $gbfsTrunkDestCache[$trunk->trunk_dest_stop];

                if ($trunkGbfsBike && $destGbfsBike->id !== $trunkGbfsBike->id) {
                    $walkFromTrunk = $this->haversineDistance((float) $trunkDestStopObj->stop_lat, (float) $trunkDestStopObj->stop_lon, $trunkGbfsBike->lat, $trunkGbfsBike->lon);
                    $bikeRide = $this->haversineDistance($trunkGbfsBike->lat, $trunkGbfsBike->lon, $destGbfsBike->lat, $destGbfsBike->lon);
                    $walkToDest = $this->haversineDistance($destGbfsBike->lat, $destGbfsBike->lon, $destLat, $destLon);

                    if ($walkFromTrunk <= $maxWalkingDistance && $walkToDest <= $maxWalkingDistance && $bikeRide > 0.5) {
                        $walkFromTrunkDur = $this->calculateWalkingMinutes($walkFromTrunk, $walkingSpeedKmh);
                        $bikeRideDur = (int) round($bikeRide * 4); // 15km/h = 4 min/km
                        $walkToDestDur = $this->calculateWalkingMinutes($walkToDest, $walkingSpeedKmh);

                        $totalGbfsFeederDur = $walkFromTrunkDur + $bikeRideDur + $walkToDestDur + 2; // +2 mins

                        $f2Options[] = [
                            'type' => 'gbfs',
                            'sub_type' => 'cycle',
                            'duration' => $totalGbfsFeederDur,
                            'bikeOrigin' => $trunkGbfsBike,
                            'bikeDest' => $destGbfsBike,
                            'walk1_dist' => $walkFromTrunk,
                            'ride_dist' => $bikeRide,
                            'walk2_dist' => $walkToDest,
                            'walk1_min' => $walkFromTrunkDur,
                            'ride_min' => $bikeRideDur,
                            'walk2_min' => $walkToDestDur,
                            'operator_name' => $trunkGbfsBike->source->tenant->name ?? 'Shared Bike',
                        ];
                    }
                }
            }

            $nearbyTrunkDests = $this->findNearbyStopIdsFromCache($stopCache, (float) $trunkDestStopObj->stop_lat, (float) $trunkDestStopObj->stop_lon, $maxWalkingDistance);
            $nearbyTrunkDestIds = array_merge([$trunk->trunk_dest_stop], array_column($nearbyTrunkDests, 'id'));

            foreach ($nearbyTrunkDestIds as $nTdId) {
                if (isset($feeder2Grouped[$nTdId])) {
                    foreach ($feeder2Grouped[$nTdId] as $f2) {
                        $transferWalk = $this->getWalkingDistanceFromCache($stopCache, $trunk->trunk_dest_stop, $f2->f2_origin_stop);
                        $transferWalkMin = $this->calculateWalkingMinutes($transferWalk, $walkingSpeedKmh);

                        $trunkArrival = strtotime($trunk->trunk_arrival);
                        $f2Departure = strtotime($f2->f2_departure);

                        if ($trunkArrival + (($transferWalkMin + $minTransferTime) * 60) <= $f2Departure &&
                            $trunkArrival + ($maxTransferTime * 60) >= $f2Departure) {
                            $f2Options[] = ['type' => 'transit', 'leg' => $f2, 'transfer_walk' => $transferWalk, 'transfer_walk_min' => $transferWalkMin];
                        }
                    }
                }
            }

            if (empty($f2Options)) {
                continue;
            }

            // Take the best F1 and best F2 to form the journey
            // Best F1: latest departure from origin
            // Best F2: earliest arrival at dest
            $bestF1 = null;
            $bestF1Score = -1;
            foreach ($f1Options as $f1) {
                if ($f1['type'] === 'walk' || $f1['type'] === 'gbfs') {
                    // Score = pseudo departure time = trunk_departure - feeder duration
                    $score = strtotime($trunk->trunk_departure) - ($f1['duration'] * 60);
                } else {
                    $originWalkDist = $this->distancePointToStopFromCache($stopCache, $originLat, $originLon, $f1['leg']->f1_origin_stop);
                    $originWalkMin = $this->calculateWalkingMinutes($originWalkDist, $walkingSpeedKmh);
                    $score = strtotime($f1['leg']->f1_departure) - ($originWalkMin * 60);
                }
                if ($score > $bestF1Score) {
                    $bestF1Score = $score;
                    $bestF1 = $f1;
                }
            }

            $bestF2 = null;
            $bestF2Score = PHP_INT_MAX;
            foreach ($f2Options as $f2) {
                if ($f2['type'] === 'walk' || $f2['type'] === 'gbfs') {
                    $score = strtotime($trunk->trunk_arrival) + ($f2['duration'] * 60);
                } else {
                    // Arrival time of F2 + final walk to dest
                    $f2WalkToDestDist = $this->distanceStopToPointFromCache($stopCache, $f2['leg']->f2_dest_stop, $destLat, $destLon);
                    $f2WalkToDestMin = $this->calculateWalkingMinutes($f2WalkToDestDist, $walkingSpeedKmh);
                    $score = strtotime($f2['leg']->f2_arrival) + ($f2WalkToDestMin * 60);
                }
                if ($score < $bestF2Score) {
                    $bestF2Score = $score;
                    $bestF2 = $f2;
                }
            }

            // Build Journey Array
            $legs = [];
            // FEEDER 1
            if ($bestF1['type'] === 'walk') {
                $walkStart = date('H:i', strtotime($trunk->trunk_departure) - ($bestF1['duration'] * 60));
                $legs[] = [
                    'type' => 'walk',
                    'from' => $originName,
                    'to' => $this->getStopNameFromCache($stopCache, $trunk->trunk_origin_stop),
                    'from_lat' => $originLat, 'from_lon' => $originLon,
                    'to_lat' => (float) $trunkOriginStopObj->stop_lat, 'to_lon' => (float) $trunkOriginStopObj->stop_lon,
                    'distance' => $bestF1['distance'],
                    'duration' => $bestF1['duration'],
                    'start_time' => $walkStart,
                    'end_time' => substr($trunk->trunk_departure, 0, 5),
                ];
            } elseif ($bestF1['type'] === 'gbfs') {
                $walkStart = date('H:i', strtotime($trunk->trunk_departure) - ($bestF1['duration'] * 60));

                // 1. Walk to bike
                $walkToBikeEnd = date('H:i', strtotime($walkStart) + ($bestF1['walk1_min'] * 60));
                if ($bestF1['walk1_dist'] > 0.05) {
                    $legs[] = [
                        'type' => 'walk',
                        'from' => $originName,
                        'to' => $bestF1['bikeOrigin']->name,
                        'from_lat' => $originLat, 'from_lon' => $originLon,
                        'to_lat' => $bestF1['bikeOrigin']->lat, 'to_lon' => $bestF1['bikeOrigin']->lon,
                        'distance' => $bestF1['walk1_dist'],
                        'duration' => $bestF1['walk1_min'],
                        'start_time' => $walkStart,
                        'end_time' => $walkToBikeEnd,
                    ];
                }

                // 2. Bike ride
                $bikeRideEnd = date('H:i', strtotime($walkToBikeEnd) + ($bestF1['ride_min'] * 60));
                $legs[] = [
                    'type' => 'bike',
                    'route_name' => 'Bike',
                    'route_color' => '3b82f6', // Blue for bikes
                    'operator_name' => $bestF1['operator_name'],
                    'from' => $bestF1['bikeOrigin']->name,
                    'to' => $bestF1['bikeDest']->name,
                    'from_lat' => $bestF1['bikeOrigin']->lat,
                    'from_lon' => $bestF1['bikeOrigin']->lon,
                    'to_lat' => $bestF1['bikeDest']->lat,
                    'to_lon' => $bestF1['bikeDest']->lon,
                    'distance' => $bestF1['ride_dist'],
                    'duration' => $bestF1['ride_min'],
                    'start_time' => $walkToBikeEnd,
                    'end_time' => $bikeRideEnd,
                ];

                // 3. Walk to Trunk
                $walkToTrunkEnd = date('H:i', strtotime($bikeRideEnd) + ($bestF1['walk2_min'] * 60));
                if ($bestF1['walk2_dist'] > 0.05) {
                    $legs[] = [
                        'type' => 'walk',
                        'from' => $bestF1['bikeDest']->name,
                        'to' => $this->getStopNameFromCache($stopCache, $trunk->trunk_origin_stop),
                        'from_lat' => $bestF1['bikeDest']->lat, 'from_lon' => $bestF1['bikeDest']->lon,
                        'to_lat' => (float) $trunkOriginStopObj->stop_lat, 'to_lon' => (float) $trunkOriginStopObj->stop_lon,
                        'distance' => $bestF1['walk2_dist'],
                        'duration' => $bestF1['walk2_min'],
                        'start_time' => $bikeRideEnd,
                        'end_time' => $walkToTrunkEnd, // usually exactly equal to trunk_departure minus the 2 mins padding
                    ];
                }
            } else {
                $f1Leg = $bestF1['leg'];
                // Walk to F1 Origin
                $wDist = $this->distancePointToStopFromCache($stopCache, $originLat, $originLon, $f1Leg->f1_origin_stop);
                if ($wDist > 0.05) {
                    $wDur = $this->calculateWalkingMinutes($wDist, $walkingSpeedKmh);
                    $wStart = date('H:i', strtotime($f1Leg->f1_departure) - ($wDur * 60));
                    $f1OCoords = $this->getStopCoordsFromCache($stopCache, $f1Leg->f1_origin_stop);
                    $legs[] = [
                        'type' => 'walk', 'from' => $originName, 'to' => $this->getStopNameFromCache($stopCache, $f1Leg->f1_origin_stop),
                        'from_lat' => $originLat, 'from_lon' => $originLon,
                        'to_lat' => $f1OCoords['lat'] ?? null, 'to_lon' => $f1OCoords['lon'] ?? null,
                        'distance' => $wDist, 'duration' => $wDur,
                        'start_time' => $wStart, 'end_time' => substr($f1Leg->f1_departure, 0, 5),
                    ];
                }

                $f1Stops = $this->fetchTripStops($f1Leg->f1_trip_id, $f1Leg->f1_origin_sequence, $f1Leg->f1_dest_sequence);
                $f1Shape = [];
                if (! empty($f1Leg->f1_shape_id) && ! empty($f1Stops)) {
                    $f1Shape = $this->getClippedShapePoints($f1Leg->f1_shape_id, (float) $f1Stops[0]['lat'], (float) $f1Stops[0]['lon'], (float) end($f1Stops)['lat'], (float) end($f1Stops)['lon']);
                }
                $legs[] = [
                    'type' => 'transit', 'route_name' => $f1Leg->f1_route_name, 'route_long_name' => $f1Leg->f1_route_long,
                    'route_type' => $f1Leg->f1_route_type, 'route_color' => $f1Leg->f1_color, 'route_text_color' => $f1Leg->f1_text_color,
                    'operator_name' => $f1Leg->f1_operator, 'from' => $this->getStopNameFromCache($stopCache, $f1Leg->f1_origin_stop),
                    'to' => $this->getStopNameFromCache($stopCache, $f1Leg->f1_dest_stop),
                    'departure_time' => substr($f1Leg->f1_departure, 0, 5), 'arrival_time' => substr($f1Leg->f1_arrival, 0, 5),
                    'duration' => $this->timeDiffMinutes(substr($f1Leg->f1_departure, 0, 5), substr($f1Leg->f1_arrival, 0, 5)),
                    'stops' => $f1Stops, 'shape_pts' => $f1Shape,
                ];

                if ($bestF1['transfer_walk'] > 0.05) {
                    $wDur = $bestF1['transfer_walk_min'];
                    $legs[] = [
                        'type' => 'walk', 'from' => $this->getStopNameFromCache($stopCache, $f1Leg->f1_dest_stop),
                        'to' => $this->getStopNameFromCache($stopCache, $trunk->trunk_origin_stop),
                        'distance' => $bestF1['transfer_walk'], 'duration' => $wDur,
                        'start_time' => substr($f1Leg->f1_arrival, 0, 5),
                        'end_time' => date('H:i', strtotime($f1Leg->f1_arrival) + ($wDur * 60)),
                    ];
                }
            }

            // TRUNK
            $trunkStops = $this->fetchTripStops($trunk->trunk_trip_id, $trunk->origin_sequence, $trunk->dest_sequence);
            $trunkShape = [];
            if (! empty($trunk->trunk_shape_id) && ! empty($trunkStops)) {
                $trunkShape = $this->getClippedShapePoints($trunk->trunk_shape_id, (float) $trunkStops[0]['lat'], (float) $trunkStops[0]['lon'], (float) end($trunkStops)['lat'], (float) end($trunkStops)['lon']);
            }
            $legs[] = [
                'type' => 'transit', 'route_name' => $trunk->trunk_route_name, 'route_long_name' => $trunk->trunk_route_long,
                'route_type' => $trunk->trunk_route_type, 'route_color' => $trunk->trunk_color, 'route_text_color' => $trunk->trunk_text_color,
                'operator_name' => $trunk->trunk_operator, 'from' => $this->getStopNameFromCache($stopCache, $trunk->trunk_origin_stop),
                'to' => $this->getStopNameFromCache($stopCache, $trunk->trunk_dest_stop),
                'departure_time' => substr($trunk->trunk_departure, 0, 5), 'arrival_time' => substr($trunk->trunk_arrival, 0, 5),
                'duration' => $this->timeDiffMinutes(substr($trunk->trunk_departure, 0, 5), substr($trunk->trunk_arrival, 0, 5)),
                'stops' => $trunkStops, 'shape_pts' => $trunkShape,
            ];

            // FEEDER 2
            if ($bestF2['type'] === 'walk') {
                $walkEnd = date('H:i', strtotime($trunk->trunk_arrival) + ($bestF2['duration'] * 60));
                $legs[] = [
                    'type' => 'walk', 'from' => $this->getStopNameFromCache($stopCache, $trunk->trunk_dest_stop),
                    'to' => $destinationName, 'from_lat' => (float) $trunkDestStopObj->stop_lat, 'from_lon' => (float) $trunkDestStopObj->stop_lon,
                    'to_lat' => $destLat, 'to_lon' => $destLon, 'distance' => $bestF2['distance'], 'duration' => $bestF2['duration'],
                    'start_time' => substr($trunk->trunk_arrival, 0, 5), 'end_time' => $walkEnd,
                ];
            } elseif ($bestF2['type'] === 'gbfs') {
                $walkStart = substr($trunk->trunk_arrival, 0, 5);

                // 1. Walk from Trunk to Bike
                $walkToBikeEnd = date('H:i', strtotime($walkStart) + ($bestF2['walk1_min'] * 60));
                if ($bestF2['walk1_dist'] > 0.05) {
                    $legs[] = [
                        'type' => 'walk',
                        'from' => $this->getStopNameFromCache($stopCache, $trunk->trunk_dest_stop),
                        'to' => $bestF2['bikeOrigin']->name,
                        'from_lat' => (float) $trunkDestStopObj->stop_lat, 'from_lon' => (float) $trunkDestStopObj->stop_lon,
                        'to_lat' => $bestF2['bikeOrigin']->lat, 'to_lon' => $bestF2['bikeOrigin']->lon,
                        'distance' => $bestF2['walk1_dist'],
                        'duration' => $bestF2['walk1_min'],
                        'start_time' => $walkStart,
                        'end_time' => $walkToBikeEnd,
                    ];
                }

                // 2. Bike ride
                $bikeRideEnd = date('H:i', strtotime($walkToBikeEnd) + ($bestF2['ride_min'] * 60));
                $legs[] = [
                    'type' => 'bike',
                    'route_name' => 'Bike',
                    'route_color' => '3b82f6', // Blue for bikes
                    'operator_name' => $bestF2['operator_name'],
                    'from' => $bestF2['bikeOrigin']->name,
                    'to' => $bestF2['bikeDest']->name,
                    'from_lat' => $bestF2['bikeOrigin']->lat,
                    'from_lon' => $bestF2['bikeOrigin']->lon,
                    'to_lat' => $bestF2['bikeDest']->lat,
                    'to_lon' => $bestF2['bikeDest']->lon,
                    'distance' => $bestF2['ride_dist'],
                    'duration' => $bestF2['ride_min'],
                    'start_time' => $walkToBikeEnd,
                    'end_time' => $bikeRideEnd,
                ];

                // 3. Walk to Dest
                $walkToDestEnd = date('H:i', strtotime($bikeRideEnd) + ($bestF2['walk2_min'] * 60));
                if ($bestF2['walk2_dist'] > 0.05) {
                    $legs[] = [
                        'type' => 'walk',
                        'from' => $bestF2['bikeDest']->name,
                        'to' => $destinationName,
                        'from_lat' => $bestF2['bikeDest']->lat, 'from_lon' => $bestF2['bikeDest']->lon,
                        'to_lat' => $destLat, 'to_lon' => $destLon,
                        'distance' => $bestF2['walk2_dist'],
                        'duration' => $bestF2['walk2_min'],
                        'start_time' => $bikeRideEnd,
                        'end_time' => $walkToDestEnd,
                    ];
                }
            } else {
                $f2Leg = $bestF2['leg'];
                if ($bestF2['transfer_walk'] > 0.05) {
                    $wDur = $bestF2['transfer_walk_min'];
                    $legs[] = [
                        'type' => 'walk', 'from' => $this->getStopNameFromCache($stopCache, $trunk->trunk_dest_stop),
                        'to' => $this->getStopNameFromCache($stopCache, $f2Leg->f2_origin_stop),
                        'distance' => $bestF2['transfer_walk'], 'duration' => $wDur,
                        'start_time' => substr($trunk->trunk_arrival, 0, 5),
                        'end_time' => date('H:i', strtotime($trunk->trunk_arrival) + ($wDur * 60)),
                    ];
                }

                $f2Stops = $this->fetchTripStops($f2Leg->f2_trip_id, $f2Leg->f2_origin_sequence, $f2Leg->f2_dest_sequence);
                $f2Shape = [];
                if (! empty($f2Leg->f2_shape_id) && ! empty($f2Stops)) {
                    $f2Shape = $this->getClippedShapePoints($f2Leg->f2_shape_id, (float) $f2Stops[0]['lat'], (float) $f2Stops[0]['lon'], (float) end($f2Stops)['lat'], (float) end($f2Stops)['lon']);
                }
                $legs[] = [
                    'type' => 'transit', 'route_name' => $f2Leg->f2_route_name, 'route_long_name' => $f2Leg->f2_route_long,
                    'route_type' => $f2Leg->f2_route_type, 'route_color' => $f2Leg->f2_color, 'route_text_color' => $f2Leg->f2_text_color,
                    'operator_name' => $f2Leg->f2_operator, 'from' => $this->getStopNameFromCache($stopCache, $f2Leg->f2_origin_stop),
                    'to' => $this->getStopNameFromCache($stopCache, $f2Leg->f2_dest_stop),
                    'departure_time' => substr($f2Leg->f2_departure, 0, 5), 'arrival_time' => substr($f2Leg->f2_arrival, 0, 5),
                    'duration' => $this->timeDiffMinutes(substr($f2Leg->f2_departure, 0, 5), substr($f2Leg->f2_arrival, 0, 5)),
                    'stops' => $f2Stops, 'shape_pts' => $f2Shape,
                ];

                $wDist = $this->distanceStopToPointFromCache($stopCache, $f2Leg->f2_dest_stop, $destLat, $destLon);
                if ($wDist > 0.05) {
                    $wDur = $this->calculateWalkingMinutes($wDist, $walkingSpeedKmh);
                    $wStart = substr($f2Leg->f2_arrival, 0, 5);
                    $legs[] = [
                        'type' => 'walk', 'from' => $this->getStopNameFromCache($stopCache, $f2Leg->f2_dest_stop), 'to' => $destinationName,
                        'distance' => $wDist, 'duration' => $wDur,
                        'start_time' => $wStart, 'end_time' => date('H:i', strtotime($wStart) + ($wDur * 60)),
                    ];
                }
            }

            $journey = $this->buildJourney($legs, $time);

            // Score similar to findTransferRoutes
            $totalTimeMinutes = $this->timeDiffMinutes($journey['leave_at'], $journey['arrival_time']);
            // A small boost for trains to make them attractive
            $journey['score'] = 12000 - ($totalTimeMinutes * 50) - ($journey['transfers'] * 200);

            if ($minimumDepartureTime && $date) {
                $leaveAtDateTime = Carbon::parse($date.' '.$journey['leave_at']);
                $minDt = Carbon::parse($date.' '.$minimumDepartureTime);
                if ($leaveAtDateTime->lt($minDt)) {
                    continue;
                }
            }

            // Calculate direct walk time once to filter absurd transfer routes
            $directWalkDistanceKm = ($originLat && $destLat) ? $this->haversineDistance($originLat, $originLon, $destLat, $destLon) * 1.3 : 0;
            $directWalkDuration = $directWalkDistanceKm > 0 ? $this->calculateWalkingMinutes($directWalkDistanceKm, 4.0) : 0;

            $totalWalkDist = 0;
            $isBacktracking = false;
            $seenTransitRouteNames = [];
            $path = [];

            foreach ($journey['legs'] as $leg) {
                if ($leg['type'] === 'walk') {
                    $totalWalkDist += $leg['distance'] ?? 0;
                }

                if (isset($leg['from']) && (empty($path) || end($path) !== $leg['from'])) {
                    $path[] = $leg['from'];
                }
                if (isset($leg['to']) && (empty($path) || end($path) !== $leg['to'])) {
                    $path[] = $leg['to'];
                }

                if (isset($leg['route_name']) && $leg['type'] === 'transit') {
                    if (in_array($leg['route_name'], $seenTransitRouteNames)) {
                        $isBacktracking = true;
                        break;
                    }
                    $seenTransitRouteNames[] = $leg['route_name'];
                }
            }

            if (count(array_unique($path)) < count($path)) {
                $isBacktracking = true;
            }

            if ($isBacktracking) {
                continue;
            }

            if ($directWalkDuration > 0) {
                if ($totalTimeMinutes > ($directWalkDuration + 10)) {
                    continue;
                }
                if ($totalWalkDist >= ($directWalkDistanceKm * 0.8)) {
                    continue;
                }
            }

            $foundJourneys[] = $journey;
        }

        // Sort by closest departure time to the selected search time
        $searchTimeStr = strtotime($time);
        usort($foundJourneys, function ($a, $b) use ($searchTimeStr) {
            $diffA = abs(strtotime($a['leave_at']) - $searchTimeStr);
            $diffB = abs(strtotime($b['leave_at']) - $searchTimeStr);

            return $diffA <=> $diffB;
        });

        // Take only the 2 closest journeys
        $closestJourneys = array_slice($foundJourneys, 0, 2);

        // Score them and limit to 2
        return $this->sortAndLimitJourneys($closestJourneys, 2);
    }

    private function findNearbyStops($stops, $maxDistance)
    {
        $nearby = [];
        foreach ($stops as $stop) {
            $nearbyStops = DB::table('stops')
                ->select('id', 'stop_name', 'stop_lat', 'stop_lon')
                ->whereRaw('
                    (6371 * acos(cos(radians(?)) * cos(radians(stop_lat)) * 
                    cos(radians(stop_lon) - radians(?)) + sin(radians(?)) * 
                    sin(radians(stop_lat)))) <= ?
                ', [$stop->stop_lat, $stop->stop_lon, $stop->stop_lat, $maxDistance])
                ->where('id', '!=', $stop->id)
                ->get()
                ->toArray();

            $nearby = array_merge($nearby, $nearbyStops);
        }

        return $nearby;
    }

    // Cleaned up - removed duplicate broken code below

    private function findNearbyStopsFromCoords($lat, $lon, $maxDistance)
    {
        return DB::table('stops')
            ->select('id', 'stop_name', 'stop_lat', 'stop_lon')
            ->whereRaw('
                (6371 * acos(cos(radians(?)) * cos(radians(stop_lat)) * 
                cos(radians(stop_lon) - radians(?)) + sin(radians(?)) * 
                sin(radians(stop_lat)))) <= ?
            ', [$lat, $lon, $lat, $maxDistance])
            ->get()
            ->toArray();
    }

    // ── Stop Resolution ───────────────────────────────────────────────────────

    /**
     * Resolve stop DB IDs from a stop name, preferring GPS coordinates when available.
     *
     * Resolution order:
     *   1. Coordinate proximity (< ~220m) — most precise, eliminates duplicate-name confusion.
     *   2. Exact name match.
     *   3. Prefix name match (fallback).
     *
     * @return list<int>
     */
    private function resolveStopIds(string $stopName, ?float $lat, ?float $lon): array
    {
        if ($lat !== null && $lon !== null) {
            $nearbyStops = Stop::withoutGlobalScopes()
                ->select('id', 'stop_name', 'stop_lat', 'stop_lon')
                ->whereRaw('ABS(stop_lat - ?) < 0.003 AND ABS(stop_lon - ?) < 0.004', [$lat, $lon])
                ->get();

            if ($nearbyStops->isNotEmpty()) {
                $ranked = $nearbyStops->map(function ($stop) use ($lat, $lon) {
                    return [
                        'id' => (int) $stop->id,
                        'distance' => $this->haversineDistance($lat, $lon, (float) $stop->stop_lat, (float) $stop->stop_lon),
                    ];
                })->sortBy('distance')->values();

                $minDistance = (float) $ranked->first()['distance'];
                $clusterMaxDistance = max(0.06, $minDistance + 0.03);

                $ids = $ranked
                    ->filter(fn ($row) => $row['distance'] <= $clusterMaxDistance)
                    ->pluck('id')
                    ->all();

                // Include nearby sibling platforms with the same stop name.
                // This avoids missing valid journeys that arrive/depart on another quay of the same station.
                $sameNameNearby = $nearbyStops
                    ->filter(function ($stop) use ($stopName) {
                        return mb_strtolower((string) $stop->stop_name) === mb_strtolower($stopName);
                    })
                    ->map(function ($stop) use ($lat, $lon) {
                        return [
                            'id' => (int) $stop->id,
                            'distance' => $this->haversineDistance($lat, $lon, (float) $stop->stop_lat, (float) $stop->stop_lon),
                        ];
                    })
                    ->filter(fn ($row) => $row['distance'] <= 0.35)
                    ->pluck('id')
                    ->all();

                if (! empty($sameNameNearby)) {
                    $ids = array_values(array_unique(array_merge($ids, $sameNameNearby)));
                }

                if (! empty($ids)) {
                    return $ids;
                }
            }
        }

        $ids = Stop::withoutGlobalScopes()
            ->where('stop_name', $stopName)
            ->pluck('id')
            ->toArray();

        if (! empty($ids)) {
            return $ids;
        }

        return Stop::withoutGlobalScopes()
            ->where('stop_name', 'LIKE', $stopName.'%')
            ->pluck('id')
            ->toArray();
    }

    // ── Stop Cache Helpers (FIX 1) ────────────────────────────────────────────

    /**
     * Pre-load all stops into a PHP array keyed by stop.id.
     * One call replaces ~300 individual DB queries per transfer search.
     *
     * @return array<int, object{id: int, stop_name: string, stop_lat: string, stop_lon: string}>
     */
    private function preloadStopCache(): array
    {
        return Cache::remember('route_search.stop_cache.v1', now()->addHours(24), function (): array {
            $cache = [];
            foreach (DB::table('stops')->get(['id', 'stop_name', 'stop_lat', 'stop_lon']) as $stop) {
                $cache[$stop->id] = $stop;
            }

            return $cache;
        });
    }

    /**
     * @param  array<int, object>  $cache
     */
    private function getStopNameFromCache(array $cache, int $stopId): string
    {
        return isset($cache[$stopId]) ? $cache[$stopId]->stop_name : 'Unknown';
    }

    /**
     * @param  array<int, object>  $cache
     * @return array{lat: float, lon: float}|null
     */
    private function getStopCoordsFromCache(array $cache, int $stopId): ?array
    {
        if (! isset($cache[$stopId])) {
            return null;
        }

        return ['lat' => (float) $cache[$stopId]->stop_lat, 'lon' => (float) $cache[$stopId]->stop_lon];
    }

    /**
     * @param  array<int, object>  $cache
     */
    private function getWalkingDistanceFromCache(array $cache, int $fromId, int $toId): float
    {
        if ($fromId === $toId) {
            return 0.0;
        }

        $from = $cache[$fromId] ?? null;
        $to = $cache[$toId] ?? null;

        if (! $from || ! $to) {
            return 0.0;
        }

        return $this->haversineDistance((float) $from->stop_lat, (float) $from->stop_lon, (float) $to->stop_lat, (float) $to->stop_lon);
    }

    /**
     * @param  array<int, object>  $cache
     */
    private function distancePointToStopFromCache(array $cache, float $lat, float $lon, int $toStopId): float
    {
        $to = $cache[$toStopId] ?? null;
        if (! $to) {
            return 0.0;
        }

        return $this->haversineDistance($lat, $lon, (float) $to->stop_lat, (float) $to->stop_lon);
    }

    /**
     * @param  array<int, object>  $cache
     */
    private function distanceStopToPointFromCache(array $cache, int $fromStopId, float $lat, float $lon): float
    {
        $from = $cache[$fromStopId] ?? null;
        if (! $from) {
            return 0.0;
        }

        return $this->haversineDistance((float) $from->stop_lat, (float) $from->stop_lon, $lat, $lon);
    }

    private function calculateWalkingMinutes(float $distanceKm, float $speedKmh): int
    {
        if ($distanceKm <= 0 || $speedKmh <= 0) {
            return 0;
        }

        return max(1, (int) round(($distanceKm / $speedKmh) * 60));
    }

    /**
     * @param  Collection<int, object>  $stops
     */
    private function pickReferenceStop($stops, ?float $lat, ?float $lon): ?object
    {
        if ($stops->isEmpty()) {
            return null;
        }

        if ($lat === null || $lon === null) {
            return $stops->first();
        }

        return $stops->sortBy(function ($stop) use ($lat, $lon) {
            return $this->haversineDistance($lat, $lon, (float) $stop->stop_lat, (float) $stop->stop_lon);
        })->first();
    }

    /**
     * @param  array<int, object>  $cache
     */
    private function calculateWalkingTimeFromCache(array $cache, int $fromId, int $toId, float $speedKmh): int
    {
        $distance = $this->getWalkingDistanceFromCache($cache, $fromId, $toId);

        return $distance > 0 ? max(1, (int) round(($distance / $speedKmh) * 60)) : 0;
    }

    /**
     * Find stops near a coordinate using the pre-loaded cache — zero DB queries.
     * Replaces findNearbyStops() and findNearbyStopsFromCoords() inside loops.
     *
     * @param  array<int, object>  $cache
     * @param  list<int>  $excludeIds
     * @return list<object>
     */
    private function findNearbyStopIdsFromCache(array $cache, float $lat, float $lon, float $maxKm, array $excludeIds = []): array
    {
        $excludeSet = array_flip($excludeIds);
        $results = [];

        foreach ($cache as $id => $stop) {
            if (isset($excludeSet[$id])) {
                continue;
            }

            $dist = $this->haversineDistance($lat, $lon, (float) $stop->stop_lat, (float) $stop->stop_lon);
            if ($dist <= $maxKm) {
                $s = clone $stop;
                $s->distance = $dist;
                $results[] = $s;
            }
        }

        return $results;
    }

    /**
     * Fetch stop_times for a trip segment as a formatted array.
     *
     * @return array<int, array{name: string, lat: float, lon: float, arrival: string, departure: string, is_intermediate: bool}>
     */
    private function fetchTripStops(int $tripId, int $fromSequence, int $toSequence): array
    {
        try {
            $stops = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.id')
                ->where('stop_times.trip_id', $tripId)
                ->where('stop_times.stop_sequence', '>=', $fromSequence)
                ->where('stop_times.stop_sequence', '<=', $toSequence)
                ->orderBy('stop_times.stop_sequence')
                ->select('stops.stop_name', 'stops.stop_lat', 'stops.stop_lon', 'stop_times.arrival_time', 'stop_times.departure_time')
                ->get()
                ->values()
                ->map(fn ($s) => [
                    'name' => $s->stop_name,
                    'lat' => $s->stop_lat,
                    'lon' => $s->stop_lon,
                    'arrival' => substr($s->arrival_time, 0, 5),
                    'departure' => substr($s->departure_time, 0, 5),
                    'is_intermediate' => false,
                ])
                ->toArray();

            foreach ($stops as $i => &$s) {
                $s['is_intermediate'] = ($i > 0 && $i < count($stops) - 1);
            }
            unset($s);

            return $stops;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Midnight-safe time difference in minutes.
     * Handles GTFS times that cross midnight (e.g. 23:50 → 00:10 = 20 min, not negative).
     */
    private function timeDiffMinutes(string $from, string $to): int
    {
        $f = strtotime('1970-01-01 '.$from);
        $t = strtotime('1970-01-01 '.$to);

        if ($t < $f) {
            $t += 86400;
        }

        return max(0, (int) round(($t - $f) / 60));
    }

    // ── Legacy helpers kept for any external callers ──────────────────────────

    private function getStopNameById($stopId): string
    {
        $stop = DB::table('stops')->where('id', $stopId)->first();

        return $stop ? $stop->stop_name : 'Unknown';
    }

    private function getStopCoordsById($stopId): ?array
    {
        $stop = DB::table('stops')->where('id', $stopId)->select('stop_lat', 'stop_lon')->first();

        return $stop ? ['lat' => $stop->stop_lat, 'lon' => $stop->stop_lon] : null;
    }

    private function getWalkingDistanceById($fromStopId, $toStopId): float
    {
        if ($fromStopId == $toStopId) {
            return 0.0;
        }

        $stops = DB::table('stops')->whereIn('id', [$fromStopId, $toStopId])->get();
        if ($stops->count() < 2) {
            return 0.0;
        }

        $from = $stops->where('id', $fromStopId)->first();
        $to = $stops->where('id', $toStopId)->first();

        return $this->haversineDistance((float) $from->stop_lat, (float) $from->stop_lon, (float) $to->stop_lat, (float) $to->stop_lon);
    }

    private function buildJourney($legs, $startTime)
    {
        // Remove legs with 0 distance/duration walking
        $legs = array_filter($legs, function ($leg) {
            if ($leg['type'] === 'walk') {
                return $leg['distance'] > 0 || $leg['from'] !== $leg['to'];
            }

            return true;
        });

        $legs = array_values($legs);

        // Calculate total duration from first leg start to last leg end
        $firstLeg = $legs[0] ?? null;
        $lastLeg = end($legs);

        // Get journey start time (walk start or bus departure)
        $journeyStart = $firstLeg['type'] === 'walk' ? $firstLeg['start_time'] : $firstLeg['departure_time'] ?? $startTime;

        // Get journey end time (walk end or bus arrival)
        $journeyEnd = $lastLeg['type'] === 'walk' ? $lastLeg['end_time'] : $lastLeg['arrival_time'] ?? $startTime;

        $duration = (strtotime($journeyEnd) - strtotime($journeyStart)) / 60;

        // For display - first bus departure time
        $firstTransit = collect($legs)->firstWhere('type', 'transit');
        $departureTime = $firstTransit['departure_time'] ?? $startTime;

        // "Leave at" time is the journey start
        $leaveAt = $journeyStart;

        return [
            'connection_type' => 'transfer',
            'leave_at' => $leaveAt, // When user should leave their location
            'departure_time' => $departureTime, // When first bus departs
            'arrival_time' => $journeyEnd, // When journey actually ends (including final walk)
            'duration' => round($duration).' min',
            'transfers' => count(array_filter($legs, fn ($l) => $l['type'] === 'transit')) - 1,
            'legs' => $legs,
        ];
    }

    /**
     * Remove accents from string for accent-insensitive search
     * Handles French accented characters: é, è, ê, à, â, ô, ù, û, ï, ç, etc.
     */
    private function removeAccents($string)
    {
        $accents = [
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
            'ñ' => 'n',
            'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Ä' => 'A',
            'Å' => 'A',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ç' => 'C',
            'Ñ' => 'N',
        ];

        return strtr($string, $accents);
    }

    /**
     * Clips a shape to only the portion between two stop coordinates.
     * Finds the nearest shape point to each stop and returns only those points in between.
     *
     * @param  array<array{shape_pt_lat: float, shape_pt_lon: float, shape_pt_sequence: int}>  $shapePoints
     * @return array<int, array{float, float}>
     */
    private function clipShapeToStops(array $shapePoints, float $originLat, float $originLon, float $destLat, float $destLon): array
    {
        if (empty($shapePoints)) {
            return [];
        }

        $originIdx = 0;
        $destIdx = count($shapePoints) - 1;
        $minOriginDist = PHP_FLOAT_MAX;
        $minDestDist = PHP_FLOAT_MAX;

        foreach ($shapePoints as $i => $pt) {
            $dOrigin = $this->haversineDistance($originLat, $originLon, $pt[0], $pt[1]);
            $dDest = $this->haversineDistance($destLat, $destLon, $pt[0], $pt[1]);

            if ($dOrigin < $minOriginDist) {
                $minOriginDist = $dOrigin;
                $originIdx = $i;
            }
            if ($dDest < $minDestDist) {
                $minDestDist = $dDest;
                $destIdx = $i;
            }
        }

        // Ensure we always go forward along the shape
        if ($originIdx > $destIdx) {
            [$originIdx, $destIdx] = [$destIdx, $originIdx];
        }

        return array_slice($shapePoints, $originIdx, $destIdx - $originIdx + 1);
    }

    /**
     * Haversine distance between two lat/lon pairs (in km).
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Loads all shape points for a shape_id and clips them to the stop segment.
     *
     * @return array<int, array{float, float}>
     */
    /**
     * FIX 3: Load shape points with a per-request cache.
     * The first call for a given shape_id fetches from DB and caches the result.
     * Subsequent calls for the same shape (common when multiple trips share a shape)
     * are served from memory — zero additional DB queries.
     *
     * @return list<array{float, float}>
     */
    private function getClippedShapePoints(string $shapeId, float $originLat, float $originLon, float $destLat, float $destLon): array
    {
        if (! isset($this->shapeCache[$shapeId])) {
            $this->shapeCache[$shapeId] = DB::table('shapes')
                ->where('shape_id', $shapeId)
                ->orderBy('shape_pt_sequence')
                ->get(['shape_pt_lat', 'shape_pt_lon'])
                ->map(fn ($pt) => [(float) $pt->shape_pt_lat, (float) $pt->shape_pt_lon])
                ->toArray();
        }

        return $this->clipShapeToStops($this->shapeCache[$shapeId], $originLat, $originLon, $destLat, $destLon);
    }

    public function getRouteStops(Request $request, $routeShortName)
    {
        // Find the canonical trip for this route — the one with the most stops
        $tripId = DB::table('stop_times')
            ->select('trip_id', DB::raw('count(*) as cnt'))
            ->whereIn('trip_id', function ($q) use ($routeShortName) {
                $q->select('t.id')->from('trips as t')
                    ->join('routes as r', 't.route_id', '=', 'r.id')
                    ->where('r.route_short_name', $routeShortName);
            })
            ->groupBy('trip_id')
            ->orderByDesc('cnt')
            ->value('trip_id');

        if (! $tripId) {
            return response()->json(['stops' => []]);
        }

        $stops = DB::table('stop_times as st')
            ->join('stops as s', 'st.stop_id', '=', 's.id')
            ->where('st.trip_id', $tripId)
            ->orderBy('st.stop_sequence')
            ->select('s.stop_name as name', 's.stop_lat as lat', 's.stop_lon as lon')
            ->get();

        return response()->json(['stops' => $stops]);
    }

    public function getAllRoutesWithShapes()
    {
        return response()->json([
            'routes' => $this->buildRouteShapePayload(),
        ]);
    }

    /**
     * Build map route polylines with bounded memory usage.
     *
     * We keep one representative shape per route and direction, then sample
     * shape points by sequence to prevent large-tenant OOM errors.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRouteShapePayload(?int $tenantId = null): array
    {
        $cacheKey = $tenantId === null
            ? 'route_shapes:all'
            : 'route_shapes:tenant:'.$tenantId;

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($tenantId): array {
            $routeShapesQuery = DB::table('routes as r')
                ->join('trips as t', 'r.id', '=', 't.route_id')
                ->leftJoin('users as u', 'r.tenant_id', '=', 'u.id')
                ->whereNotNull('t.shape_id');

            if ($tenantId !== null) {
                $routeShapesQuery->where('r.tenant_id', $tenantId);
            }

            $routeShapes = $routeShapesQuery
                ->groupBy('r.route_short_name', DB::raw('COALESCE(t.direction_id, 0)'))
                ->select(
                    'r.route_short_name',
                    DB::raw('MAX(r.route_long_name) as route_long_name'),
                    DB::raw('MAX(r.route_color) as route_color'),
                    DB::raw('MAX(r.route_text_color) as route_text_color'),
                    DB::raw('MAX(u.name) as agency_name'),
                    DB::raw('MIN(t.shape_id) as shape_id')
                )
                ->orderBy('r.route_short_name')
                ->get();

            $shapeIds = $routeShapes->pluck('shape_id')->filter()->unique()->values()->toArray();
            if (empty($shapeIds)) {
                return [];
            }

            $shapePoints = [];
            DB::table('shapes')
                ->whereIn('shape_id', $shapeIds)
                ->where(function ($query) {
                    $query->where('shape_pt_sequence', 1)
                        ->orWhereRaw('MOD(shape_pt_sequence, 4) = 0');
                })
                ->orderBy('shape_id')
                ->orderBy('shape_pt_sequence')
                ->select('shape_id', 'shape_pt_lat', 'shape_pt_lon')
                ->chunk(10000, function ($rows) use (&$shapePoints) {
                    foreach ($rows as $row) {
                        $shapePoints[$row->shape_id][] = [
                            (float) $row->shape_pt_lat,
                            (float) $row->shape_pt_lon,
                        ];
                    }
                });

            $routes = [];
            foreach ($routeShapes as $routeShape) {
                if (! isset($shapePoints[$routeShape->shape_id]) || count($shapePoints[$routeShape->shape_id]) < 2) {
                    continue;
                }

                $routes[] = [
                    'route_short_name' => $routeShape->route_short_name,
                    'route_long_name' => $routeShape->route_long_name,
                    'route_color' => $routeShape->route_color,
                    'route_text_color' => $routeShape->route_text_color,
                    'agency_name' => $routeShape->agency_name,
                    'shape_pts' => $shapePoints[$routeShape->shape_id],
                ];
            }

            return $routes;
        });
    }

    public function getTerritories()
    {
        $sorted = Cache::remember('territories:list', now()->addHours(12), function () {
            $rows = DB::table('stops as s')
                ->join('users as u', 's.tenant_id', '=', 'u.id')
                ->whereNotNull('s.tenant_id')
                ->whereNotNull('s.stop_lat')
                ->whereNotNull('s.stop_lon')
                ->select('s.tenant_id', 's.stop_lat', 's.stop_lon', 's.city', 'u.name as operator_name')
                ->orderBy('s.city')
                ->get();

            $gbfsRows = DB::table('gbfs_stations as gs')
                ->join('gbfs_sources as src', 'gs.gbfs_source_id', '=', 'src.id')
                ->join('users as u', 'src.tenant_id', '=', 'u.id')
                ->where('src.is_active', true)
                ->select('src.tenant_id', 'gs.lat as stop_lat', 'gs.lon as stop_lon', 'u.name as operator_name', 'src.type')
                ->get();

            $territories = collect();
            $gtfsGrouped = $rows->groupBy('tenant_id');
            $gbfsGrouped = $gbfsRows->groupBy('tenant_id');

            $allTenantIds = $gtfsGrouped->keys()->concat($gbfsGrouped->keys())->unique();

            foreach ($allTenantIds as $tenantId) {
                $tenantRows = $gtfsGrouped->get($tenantId, collect());
                $tenantGbfsRows = $gbfsGrouped->get($tenantId, collect());

                $operatorName = '';
                if ($tenantRows->isNotEmpty()) {
                    $operatorName = (string) $tenantRows->first()->operator_name;
                } elseif ($tenantGbfsRows->isNotEmpty()) {
                    $operatorName = (string) $tenantGbfsRows->first()->operator_name;
                }

                $services = collect();
                if ($tenantRows->isNotEmpty()) {
                    $tenantName = strtolower($tenantRows->first()->operator_name);
                    if ($tenantName === 'sncf') {
                        $services->push('train');
                    } else {
                        $services->push('bus');
                    }
                }
                if ($tenantGbfsRows->isNotEmpty()) {
                    $services = $services->concat($tenantGbfsRows->pluck('type'))->unique();
                }

                $stopPoints = $tenantRows->map(fn ($row) => [(float) $row->stop_lat, (float) $row->stop_lon])
                    ->concat($tenantGbfsRows->map(fn ($row) => [(float) $row->stop_lat, (float) $row->stop_lon]))
                    ->all();

                if (count($stopPoints) < 4) {
                    continue;
                }

                $shapePoints = $this->getShapePointsForTenantCluster((int) $tenantId, $stopPoints);
                $points = array_merge($stopPoints, $shapePoints);

                $polygon = $this->buildConcaveTerritoryPolygon($points);
                if (count($polygon) < 4) {
                    $polygon = $this->buildConvexHull($points);
                }

                if (count($polygon) < 4) {
                    $polygon = $this->buildBoundingPolygon($points);
                }

                $bbox = $this->calculateBbox($polygon);

                $dominantCity = $tenantRows
                    ->pluck('city')
                    ->filter(fn ($city) => filled($city))
                    ->countBy()
                    ->sortDesc()
                    ->keys()
                    ->first();

                $territoryName = $dominantCity ? $operatorName.' - '.$dominantCity : $operatorName.' network';

                if (stripos($operatorName, 'sncf') !== false) {
                    $dominantCity = 'Bourgogne-Franche-Comté';
                    $territoryName = 'SNCF - Bourgogne-Franche-Comté';
                }

                $territories->push([
                    'tenant_id' => (int) $tenantId,
                    'name' => $territoryName,
                    'city' => $dominantCity,
                    'operator_name' => $operatorName,
                    'stops_count' => $tenantRows->count() + $tenantGbfsRows->count(),
                    'bbox' => $bbox,
                    'polygon' => $polygon,
                    'services' => $services->values()->all(),
                ]);
            }

            return $territories
                ->sortByDesc('stops_count')
                ->values();
        });

        return response()->json(['territories' => $sorted]);
    }

    public function getRoutesWithShapesByTerritory(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer|exists:users,id',
        ]);

        $tenantId = (int) $validated['tenant_id'];

        return response()->json([
            'routes' => $this->buildRouteShapePayload($tenantId),
        ]);
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    private function buildConvexHull(array $points): array
    {
        $uniquePoints = [];
        foreach ($points as [$lat, $lon]) {
            $key = round($lat, 6).'|'.round($lon, 6);
            $uniquePoints[$key] = [$lat, $lon];
        }

        $points = array_values($uniquePoints);
        if (count($points) <= 2) {
            return $this->buildBoundingPolygon($points);
        }

        usort($points, function (array $a, array $b): int {
            return $a[1] <=> $b[1] ?: ($a[0] <=> $b[0]);
        });

        $lower = [];
        foreach ($points as $point) {
            while (count($lower) >= 2 && $this->cross($lower[count($lower) - 2], $lower[count($lower) - 1], $point) <= 0) {
                array_pop($lower);
            }

            $lower[] = $point;
        }

        $upper = [];
        for ($i = count($points) - 1; $i >= 0; $i--) {
            $point = $points[$i];
            while (count($upper) >= 2 && $this->cross($upper[count($upper) - 2], $upper[count($upper) - 1], $point) <= 0) {
                array_pop($upper);
            }

            $upper[] = $point;
        }

        array_pop($lower);
        array_pop($upper);
        $hull = array_merge($lower, $upper);

        if (count($hull) < 3) {
            return $this->buildBoundingPolygon($points);
        }

        $hull[] = $hull[0];

        return $hull;
    }

    /**
     * Build a smoother territory polygon from stop points:
     * 1) angle-ordered radial perimeter (organic shell)
     * 2) Chaikin corner cutting for visual smoothness
     * 3) clamp to bbox to avoid runaway coordinates
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    private function buildConcaveTerritoryPolygon(array $points): array
    {
        $uniquePoints = [];
        foreach ($points as [$lat, $lon]) {
            $key = round($lat, 6).'|'.round($lon, 6);
            $uniquePoints[$key] = [$lat, $lon];
        }

        $points = array_values($uniquePoints);
        if (count($points) < 4) {
            return $this->buildConvexHull($points);
        }

        $hull = $this->buildConvexHull($points);
        if (count($hull) < 4) {
            return $this->buildBoundingPolygon($points);
        }

        $ring = array_slice($hull, 0, -1);
        $centroid = $this->calculateCentroid($ring);

        // Expand slightly so smoothing does not cut valid route extremities.
        $expanded = array_map(function (array $pt) use ($centroid): array {
            return [
                $centroid[0] + (($pt[0] - $centroid[0]) * 1.07),
                $centroid[1] + (($pt[1] - $centroid[1]) * 1.07),
            ];
        }, $ring);

        $ring = $expanded;

        $ring = $this->deduplicateSequentialPoints($ring);
        if (count($ring) < 4) {
            return $this->buildConvexHull($points);
        }

        // Ensure orientation consistency before smoothing.
        if ($this->signedArea($ring) < 0) {
            $ring = array_reverse($ring);
        }

        $ring = $this->simplifyRing($ring, 0.00008);
        if (count($ring) < 4) {
            return $this->buildConvexHull($points);
        }

        $smoothed = $this->chaikinSmooth($ring, 3, 0.25);
        $smoothed = $this->simplifyRing($smoothed, 0.00012);

        $bbox = $this->calculateBbox($points);
        $latPad = max(0.002, ($bbox['max_lat'] - $bbox['min_lat']) * 0.12);
        $lonPad = max(0.002, ($bbox['max_lon'] - $bbox['min_lon']) * 0.12);

        $minLat = $bbox['min_lat'] - $latPad;
        $maxLat = $bbox['max_lat'] + $latPad;
        $minLon = $bbox['min_lon'] - $lonPad;
        $maxLon = $bbox['max_lon'] + $lonPad;

        $polygon = array_map(function (array $pt) use ($minLat, $maxLat, $minLon, $maxLon): array {
            return [
                max($minLat, min($maxLat, $pt[0])),
                max($minLon, min($maxLon, $pt[1])),
            ];
        }, $smoothed);

        $polygon = $this->deduplicateSequentialPoints($polygon);
        if (count($polygon) < 4) {
            return $this->buildConvexHull($points);
        }

        $polygon[] = $polygon[0];

        return $polygon;
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     * @param  array{0: float, 1: float}  $c
     */
    private function cross(array $a, array $b, array $c): float
    {
        return ($b[1] - $a[1]) * ($c[0] - $a[0]) - ($b[0] - $a[0]) * ($c[1] - $a[1]);
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array{0: float, 1: float}
     */
    private function calculateCentroid(array $points): array
    {
        $count = count($points);
        if ($count === 0) {
            return [0.0, 0.0];
        }

        $sumLat = 0.0;
        $sumLon = 0.0;

        foreach ($points as [$lat, $lon]) {
            $sumLat += $lat;
            $sumLon += $lon;
        }

        return [$sumLat / $count, $sumLon / $count];
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private function distanceSquared(array $a, array $b): float
    {
        return ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2;
    }

    /**
     * Fetch route geometry points for one tenant around the current stop cluster only.
     *
     * @param  array<int, array{0: float, 1: float}>  $clusterPoints
     * @return array<int, array{0: float, 1: float}>
     */
    private function getShapePointsForTenantCluster(int $tenantId, array $clusterPoints): array
    {
        $shapeIds = DB::table('routes as r')
            ->join('trips as t', 'r.id', '=', 't.route_id')
            ->where('r.tenant_id', $tenantId)
            ->whereNotNull('t.shape_id')
            ->distinct()
            ->pluck('t.shape_id');

        if ($shapeIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('shapes')
            ->whereIn('shape_id', $shapeIds)
            ->whereRaw('MOD(shape_pt_sequence, 4) = 0 OR shape_pt_sequence = 1')
            ->limit(12000)
            ->get(['shape_pt_lat', 'shape_pt_lon']);

        $shapePoints = [];
        foreach ($rows as $row) {
            $shapePoints[] = [(float) $row->shape_pt_lat, (float) $row->shape_pt_lon];
        }

        return $shapePoints;
    }

    /**
     * @param  array<int, float|null>  $values
     * @return array<int, float>
     */
    private function interpolateCircularMissingValues(array $values): array
    {
        $count = count($values);
        $known = array_filter($values, fn ($value): bool => $value !== null);

        if (empty($known)) {
            return array_fill(0, $count, 0.0);
        }

        for ($i = 0; $i < $count; $i++) {
            if ($values[$i] !== null) {
                continue;
            }

            $prevIndex = null;
            for ($step = 1; $step <= $count; $step++) {
                $candidate = ($i - $step + $count) % $count;
                if ($values[$candidate] !== null) {
                    $prevIndex = $candidate;
                    break;
                }
            }

            $nextIndex = null;
            for ($step = 1; $step <= $count; $step++) {
                $candidate = ($i + $step) % $count;
                if ($values[$candidate] !== null) {
                    $nextIndex = $candidate;
                    break;
                }
            }

            if ($prevIndex === null && $nextIndex === null) {
                $values[$i] = 0.0;
            } elseif ($prevIndex === null) {
                $values[$i] = (float) $values[$nextIndex];
            } elseif ($nextIndex === null) {
                $values[$i] = (float) $values[$prevIndex];
            } else {
                $forward = ($nextIndex - $prevIndex + $count) % $count;
                $offset = ($i - $prevIndex + $count) % $count;
                $t = $forward === 0 ? 0.0 : $offset / $forward;

                $values[$i] = ((1 - $t) * (float) $values[$prevIndex]) + ($t * (float) $values[$nextIndex]);
            }
        }

        return array_map(fn ($value): float => (float) $value, $values);
    }

    /**
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function smoothCircularValues(array $values, int $windowRadius): array
    {
        $count = count($values);
        if ($count === 0 || $windowRadius <= 0) {
            return $values;
        }

        $smoothed = [];

        for ($i = 0; $i < $count; $i++) {
            $sum = 0.0;
            $weightSum = 0.0;

            for ($offset = -$windowRadius; $offset <= $windowRadius; $offset++) {
                $idx = ($i + $offset + $count) % $count;
                $weight = ($windowRadius + 1) - abs($offset);
                $sum += $values[$idx] * $weight;
                $weightSum += $weight;
            }

            $smoothed[$i] = $weightSum > 0 ? $sum / $weightSum : $values[$i];
        }

        return $smoothed;
    }

    /**
     * Limit bucket-to-bucket radius jumps to reduce narrow spikes.
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function limitCircularDistanceJumps(array $values, float $maxRatioDelta, int $passes): array
    {
        $count = count($values);
        if ($count < 3 || $passes <= 0) {
            return $values;
        }

        $result = $values;

        for ($pass = 0; $pass < $passes; $pass++) {
            $next = $result;

            for ($i = 0; $i < $count; $i++) {
                $prev = $result[($i - 1 + $count) % $count];
                $curr = $result[$i];
                $nxt = $result[($i + 1) % $count];
                $neighborAvg = ($prev + $nxt) / 2;

                if ($neighborAvg <= 0.0) {
                    continue;
                }

                $maxAllowed = $neighborAvg * (1 + $maxRatioDelta);
                $minAllowed = $neighborAvg * (1 - $maxRatioDelta);

                if ($curr > $maxAllowed) {
                    $next[$i] = $maxAllowed;
                } elseif ($curr < $minAllowed) {
                    $next[$i] = $minAllowed;
                }
            }

            $result = $next;
        }

        return $result;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ring
     */
    private function signedArea(array $ring): float
    {
        $area = 0.0;
        $count = count($ring);
        if ($count < 3) {
            return 0.0;
        }

        for ($i = 0; $i < $count; $i++) {
            $j = ($i + 1) % $count;
            $area += ($ring[$i][1] * $ring[$j][0]) - ($ring[$j][1] * $ring[$i][0]);
        }

        return $area / 2.0;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ring
     * @return array<int, array{0: float, 1: float}>
     */
    private function chaikinSmooth(array $ring, int $iterations = 2, float $ratio = 0.25): array
    {
        $count = count($ring);
        if ($count < 3) {
            return $ring;
        }

        $result = $ring;

        for ($iter = 0; $iter < $iterations; $iter++) {
            $smoothed = [];
            $n = count($result);

            for ($i = 0; $i < $n; $i++) {
                $p0 = $result[$i];
                $p1 = $result[($i + 1) % $n];

                $q = [
                    (1 - $ratio) * $p0[0] + $ratio * $p1[0],
                    (1 - $ratio) * $p0[1] + $ratio * $p1[1],
                ];

                $r = [
                    $ratio * $p0[0] + (1 - $ratio) * $p1[0],
                    $ratio * $p0[1] + (1 - $ratio) * $p1[1],
                ];

                $smoothed[] = $q;
                $smoothed[] = $r;
            }

            $result = $smoothed;
        }

        return $result;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    private function deduplicateSequentialPoints(array $points): array
    {
        $result = [];
        foreach ($points as $point) {
            if (empty($result)) {
                $result[] = $point;

                continue;
            }

            $last = $result[count($result) - 1];
            if (abs($last[0] - $point[0]) < 0.000001 && abs($last[1] - $point[1]) < 0.000001) {
                continue;
            }

            $result[] = $point;
        }

        if (count($result) > 1) {
            $first = $result[0];
            $last = $result[count($result) - 1];
            if (abs($first[0] - $last[0]) < 0.000001 && abs($first[1] - $last[1]) < 0.000001) {
                array_pop($result);
            }
        }

        return $result;
    }

    /**
     * Group stop rows into geographic clusters to avoid one polygon bridging separate cities.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, Collection<int, object>>
     */
    private function clusterStopsByDistance($rows, float $radiusKm): array
    {
        $clusters = [];
        $visited = [];

        for ($i = 0; $i < $rows->count(); $i++) {
            if (isset($visited[$i])) {
                continue;
            }

            $queue = [$i];
            $visited[$i] = true;
            $clusterIndexes = [];

            while (! empty($queue)) {
                $current = array_pop($queue);
                $clusterIndexes[] = $current;

                $currentRow = $rows[$current];
                $currentLat = (float) $currentRow->stop_lat;
                $currentLon = (float) $currentRow->stop_lon;

                for ($j = 0; $j < $rows->count(); $j++) {
                    if (isset($visited[$j])) {
                        continue;
                    }

                    $candidate = $rows[$j];
                    $candidateLat = (float) $candidate->stop_lat;
                    $candidateLon = (float) $candidate->stop_lon;

                    if ($this->haversineKm($currentLat, $currentLon, $candidateLat, $candidateLon) <= $radiusKm) {
                        $visited[$j] = true;
                        $queue[] = $j;
                    }
                }
            }

            $clusters[] = collect($clusterIndexes)->map(fn ($idx) => $rows[$idx]);
        }

        usort($clusters, fn ($a, $b): int => $b->count() <=> $a->count());

        return $clusters;
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Lightweight ring simplification for map-friendly, less jittery outlines.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring
     * @return array<int, array{0: float, 1: float}>
     */
    private function simplifyRing(array $ring, float $epsilon): array
    {
        if (count($ring) < 4) {
            return $ring;
        }

        $simplified = [$ring[0]];

        for ($i = 1; $i < count($ring) - 1; $i++) {
            $prev = $simplified[count($simplified) - 1];
            $curr = $ring[$i];
            $next = $ring[$i + 1];

            $distPrev = sqrt($this->distanceSquared($prev, $curr));
            if ($distPrev < $epsilon) {
                continue;
            }

            // Drop nearly-collinear micro-bends.
            $cross = abs($this->cross($prev, $curr, $next));
            if ($cross < ($epsilon * 0.2)) {
                continue;
            }

            $simplified[] = $curr;
        }

        $simplified[] = $ring[count($ring) - 1];

        return $this->deduplicateSequentialPoints($simplified);
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    private function buildBoundingPolygon(array $points): array
    {
        if (empty($points)) {
            return [];
        }

        $lats = array_map(fn ($point) => $point[0], $points);
        $lons = array_map(fn ($point) => $point[1], $points);

        $minLat = min($lats);
        $maxLat = max($lats);
        $minLon = min($lons);
        $maxLon = max($lons);

        $latPad = max(0.003, ($maxLat - $minLat) * 0.2);
        $lonPad = max(0.003, ($maxLon - $minLon) * 0.2);

        $minLat -= $latPad;
        $maxLat += $latPad;
        $minLon -= $lonPad;
        $maxLon += $lonPad;

        return [
            [$minLat, $minLon],
            [$minLat, $maxLon],
            [$maxLat, $maxLon],
            [$maxLat, $minLon],
            [$minLat, $minLon],
        ];
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $polygon
     * @return array{min_lat: float, max_lat: float, min_lon: float, max_lon: float}
     */
    private function calculateBbox(array $polygon): array
    {
        if (empty($polygon)) {
            return [
                'min_lat' => 0.0,
                'max_lat' => 0.0,
                'min_lon' => 0.0,
                'max_lon' => 0.0,
            ];
        }

        $lats = array_map(fn ($point) => $point[0], $polygon);
        $lons = array_map(fn ($point) => $point[1], $polygon);

        return [
            'min_lat' => min($lats),
            'max_lat' => max($lats),
            'min_lon' => min($lons),
            'max_lon' => max($lons),
        ];
    }

    /**
     * Generate point-to-point GBFS routes (bikes and cars).
     */
    private function getGbfsRoutes(?float $originLat, ?float $originLon, ?float $destLat, ?float $destLon, string $time, string $originName, string $destName, bool $allowCar = false): array
    {
        $gbfsRoutes = [];

        // Try to find a Bike route
        $bikeOrigin = $this->getNearestGbfsStation($originLat, $originLon, true, 'cycle');
        $bikeDest = $this->getNearestGbfsStation($destLat, $destLon, false, 'cycle');

        if ($bikeOrigin && $bikeDest && $bikeOrigin->id !== $bikeDest->id) {
            $gbfsRoutes[] = $this->buildGbfsRoute($bikeOrigin, $bikeDest, $originLat, $originLon, $destLat, $destLon, $time, $originName, $destName, 'bike', '3b82f6', false);
        }

        if (! $allowCar) {
            return $gbfsRoutes;
        }

        // Try to find a Car route
        $carOrigin = $this->getNearestGbfsStation($originLat, $originLon, true, 'car');

        if ($carOrigin) {
            $carDest = $this->getNearestGbfsStation($destLat, $destLon, false, 'car');
            $parkingDest = $this->getNearestParkingSpot($destLat, $destLon);

            // Determine the closest drop-off point (GBFS station or Parking Spot)
            $bestDest = null;
            $bestDist = PHP_FLOAT_MAX;
            $isParkingDest = false;

            if ($carDest) {
                $dist = $this->haversineDistance($destLat, $destLon, $carDest->lat, $carDest->lon);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestDest = $carDest;
                    $isParkingDest = false;
                }
            }

            if ($parkingDest) {
                $dist = $this->haversineDistance($destLat, $destLon, $parkingDest->lat, $parkingDest->lon);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestDest = $parkingDest;
                    $isParkingDest = true;
                }
            }

            if ($bestDest && ($isParkingDest || $carOrigin->id !== $bestDest->id)) {
                $gbfsRoutes[] = $this->buildGbfsRoute($carOrigin, $bestDest, $originLat, $originLon, $destLat, $destLon, $time, $originName, $destName, 'car', 'ef4444', $isParkingDest);
            }
        }

        return $gbfsRoutes;
    }

    private function getNearestParkingSpot(?float $lat, ?float $lon)
    {
        if (! $lat || ! $lon) {
            return null;
        }

        // Within 2.5km radius
        $parkingSpots = ParkingSpot::all();
        $bestSpot = null;
        $minDist = 2.5;

        foreach ($parkingSpots as $spot) {
            $dist = $this->haversineDistance($lat, $lon, $spot->lat, $spot->lon);
            if ($dist <= $minDist) {
                $minDist = $dist;
                $bestSpot = $spot;
            }
        }

        return $bestSpot;
    }

    private function buildGbfsRoute($stationA, $stationB, $originLat, $originLon, $destLat, $destLon, $time, $originName, $destName, $type, $color, $isParkingDest)
    {
        $walkDistA = $this->haversineDistance($originLat, $originLon, $stationA->lat, $stationA->lon);
        $walkDurA = (int) round(($walkDistA / 5.0) * 60);

        // Bike ~15km/h = 15/60 km/min = 0.25 km/min. Time = dist / 0.25 = dist * 4
        // Car ~30km/h = 30/60 km/min = 0.5 km/min. Time = dist / 0.5 = dist * 2
        $rideDist = $this->haversineDistance($stationA->lat, $stationA->lon, $stationB->lat, $stationB->lon); // Straight line proxy
        $rideDur = $type === 'car' ? (int) round($rideDist * 2) : (int) round($rideDist * 4);

        $walkDistB = $this->haversineDistance($stationB->lat, $stationB->lon, $destLat, $destLon);
        $walkDurB = (int) round(($walkDistB / 5.0) * 60);

        $totalDuration = $walkDurA + $rideDur + $walkDurB;
        $departureTimeObj = Carbon::createFromFormat('H:i', substr($time, 0, 5));

        $legs = [];
        $currentTimeObj = clone $departureTimeObj;

        if ($walkDistA > 0.05) {
            $endTimeObj = (clone $currentTimeObj)->addMinutes($walkDurA);
            $legs[] = [
                'type' => 'walk',
                'from' => $originName,
                'to' => $stationA->name,
                'from_lat' => $originLat,
                'from_lon' => $originLon,
                'to_lat' => $stationA->lat,
                'to_lon' => $stationA->lon,
                'distance' => round($walkDistA, 2),
                'duration' => $walkDurA,
                'start_time' => $currentTimeObj->format('H:i'),
                'end_time' => $endTimeObj->format('H:i'),
            ];
            $currentTimeObj = $endTimeObj;
        }

        $endTimeObj = (clone $currentTimeObj)->addMinutes($rideDur);
        $legs[] = [
            'type' => $type,
            'from' => $stationA->name,
            'to' => $isParkingDest ? $stationB->name.' (Parking)' : $stationB->name,
            'from_lat' => $stationA->lat,
            'from_lon' => $stationA->lon,
            'to_lat' => $stationB->lat,
            'to_lon' => $stationB->lon,
            'distance' => round($rideDist, 2),
            'duration' => $rideDur,
            'start_time' => $currentTimeObj->format('H:i'),
            'end_time' => $endTimeObj->format('H:i'),
            'route_name' => ($stationA->source->tenant->name ?? 'GBFS').' '.ucfirst($type),
            'route_color' => $color,
        ];
        $currentTimeObj = $endTimeObj;

        if ($walkDistB > 0.05) {
            $endTimeObj = (clone $currentTimeObj)->addMinutes($walkDurB);
            $legs[] = [
                'type' => 'walk',
                'from' => $isParkingDest ? $stationB->name.' (Parking)' : $stationB->name,
                'to' => $destName,
                'from_lat' => $stationB->lat,
                'from_lon' => $stationB->lon,
                'to_lat' => $destLat,
                'to_lon' => $destLon,
                'distance' => round($walkDistB, 2),
                'duration' => $walkDurB,
                'start_time' => $currentTimeObj->format('H:i'),
                'end_time' => $endTimeObj->format('H:i'),
            ];
            $currentTimeObj = $endTimeObj;
        }

        return [
            'route_name' => ($stationA->source->tenant->name ?? 'GBFS').' '.ucfirst($type),
            'route_short_name' => ucfirst($type),
            'route_color' => $color,
            'route_text_color' => 'ffffff',
            'agency_name' => $stationA->source->tenant->name ?? 'GBFS',
            'departure_time' => $departureTimeObj->format('H:i'),
            'leave_at' => $departureTimeObj->format('H:i'),
            'arrival_time' => $currentTimeObj->format('H:i'),
            'duration' => $totalDuration.' min',
            'connection_type' => 'transfer',
            'is_gbfs' => true,
            'gbfs_type' => $type,
            'is_car' => ($type === 'car'),
            'is_bike' => ($type === 'cycle' || $type === 'bike'),
            'stops' => [],
            'legs' => $legs,
        ];
    }

    /**
     * Find the nearest GBFS station with availability.
     */
    private function getNearestGbfsStation(?float $lat, ?float $lon, bool $needsVehicle, $type = 'cycle')
    {
        if (! $lat || ! $lon) {
            return null;
        }

        $stations = GbfsStation::with(['source', 'source.tenant'])
            ->whereHas('source', function ($query) use ($type) {
                $query->where('is_active', true)->where('type', $type);
            })->get();

        $bestStation = null;
        $minDist = ($type === 'car') ? 2.5 : 2.0;

        foreach ($stations as $station) {
            $dist = $this->haversineDistance($lat, $lon, $station->lat, $station->lon);
            if ($dist <= $minDist) {
                $sourceId = $station->gbfs_source_id;
                $stationId = $station->station_id;
                $cacheKey = "gbfs_station_status_{$sourceId}_{$stationId}";
                $status = Cache::get($cacheKey, []);

                // Determine availability
                $isCar = $station->source->type === 'car';
                $available = 0;

                if ($needsVehicle) {
                    if ($isCar) {
                        if (isset($status['vehicle_types_available'])) {
                            foreach ($status['vehicle_types_available'] as $type) {
                                $available += $type['count'] ?? 0;
                            }
                        } else {
                            $available = $status['num_bikes_available'] ?? 0;
                        }
                    } else {
                        $available = $status['num_bikes_available'] ?? 0;
                    }

                    if ($available > 0 || ! empty($status['is_renting'])) {
                        $minDist = $dist;
                        $bestStation = $station;
                    }
                } else {
                    // Needs dock
                    $docks = $status['num_docks_available'] ?? 0;
                    if ($docks > 0 || ! empty($status['is_returning'])) {
                        $minDist = $dist;
                        $bestStation = $station;
                    }
                }
            }
        }

        return $bestStation;
    }

    /**
     * Get all stops and stations for a specific territory.
     */
    public function getTerritoryStops(Request $request, $tenantId)
    {
        $stops = DB::table('stops')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('stop_lat')
            ->whereNotNull('stop_lon')
            ->select('id', 'stop_name as name', 'stop_lat as lat', 'stop_lon as lon')
            ->get()
            ->unique('name')
            ->values();

        $gbfsStations = DB::table('gbfs_stations as gs')
            ->join('gbfs_sources as src', 'gs.gbfs_source_id', '=', 'src.id')
            ->where('src.tenant_id', $tenantId)
            ->where('src.is_active', true)
            ->select('gs.name', 'gs.lat', 'gs.lon', 'src.type as gbfs_type')
            ->get();

        return response()->json([
            'stops' => $stops,
            'gbfs_stations' => $gbfsStations,
        ]);
    }

    public function getParkingSpots()
    {
        $parkingSpots = DB::table('parking_spots')
            ->select('name', 'lat', 'lon')
            ->get();

        return response()->json([
            'parking_spots' => $parkingSpots,
        ]);
    }
}
