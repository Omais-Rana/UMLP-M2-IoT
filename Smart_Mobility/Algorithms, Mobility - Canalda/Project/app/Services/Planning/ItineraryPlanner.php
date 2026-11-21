<?php

namespace App\Services\Planning;

use App\Models\Route;
use App\Models\Stop;
use App\Services\Routing\ConnectionScan;
use App\Services\Routing\LegBuilder;
use App\Services\Routing\TravelTimeProvider;
use Illuminate\Support\Collection;

class ItineraryPlanner
{
    private const MAX_ACCESS_WALK_METERS = 500.0;

    private const MAX_DESTINATION_WALK_METERS = 500.0;

    private const MAX_DIRECT_WALK_METERS = 500.0;

    // A transit itinerary can contain both origin access and final access.
    // Keep each walking leg at 500 m while allowing both legs to be used.
    private const MAX_TOTAL_WALK_METERS = 1000.0;

    private const WALKING_PENALTY_SECONDS_PER_METER = 1.2;

    private const MAX_TRANSIT_DETOUR_SECONDS = 600;

    private const MAX_TRANSIT_WALKING_RATIO = 0.8;

    /** @var array<string, array<string, array{distance: float, walk_sec: int}>> */
    protected array $nearbyStopsCache = [];

    public function __construct(
        protected TravelTimeProvider $travelTime,
        protected ConnectionScan $csa,
        protected LegBuilder $legBuilder,
        protected PlanningDataset $dataset,
    ) {}

    /**
     * Compute multimodal reachability from a participant's starting point to all candidate hubs and destination.
     *
     * @param  array{name: string, lat: float, lon: float, has_car?: bool, share_ride?: bool}  $participant
     * @param  int  $startTimeSec  Seconds from midnight
     * @param  string  $date  (Y-m-d)
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @return array{
     *     participant: string,
     *     start_time_sec: int,
     *     earliest_at_destination: int,
     *     best_itinerary_to_destination: array<string, mixed>,
     *     candidates_reach: array<int, array{
     *         candidate_id: int,
     *         name: string,
     *         lat: float,
     *         lon: float,
     *         start_time_sec: int,
     *         arrival_time_sec: int,
     *         legs: list<array<string, mixed>>
     *     }>
     * }
     */
    public function reach(
        array $participant,
        int $startTimeSec,
        string $date,
        array $destination,
        ?int $latestArrivalSec = null,
        bool $includeCandidates = true
    ): array {
        $pName = $participant['name'];
        $pLat = (float) $participant['lat'];
        $pLon = (float) $participant['lon'];
        $hasCar = (bool) ($participant['has_car'] ?? false);

        $stops = $this->dataset->stops();
        $candidates = $this->dataset->candidates();
        $routingStartSec = $startTimeSec;

        // 1. Initial stop arrivals from origin
        $initialStopArrivals = [];
        $originAccessParams = []; // [stop_id => raw leg params]

        foreach ($this->getNearbyStops($pLat, $pLon, self::MAX_ACCESS_WALK_METERS) as $stopId => $near) {
            $stop = $stops[$stopId];
            $arrSec = $routingStartSec + $near['walk_sec'];

            if (! isset($initialStopArrivals[$stopId]) || $arrSec < $initialStopArrivals[$stopId]) {
                $initialStopArrivals[$stopId] = $arrSec;
                $originAccessParams[$stopId] = [
                    'mode' => 'walk',
                    'from' => ['name' => "Départ ({$pName})", 'lat' => $pLat, 'lon' => $pLon],
                    'to' => ['name' => $stop->name, 'lat' => $stop->lat, 'lon' => $stop->lon],
                    'dep_seconds' => $startTimeSec,
                    'arr_seconds' => $arrSec,
                    'distance_m' => $near['distance'],
                ];
            }
        }

        // 2. Run Connection Scan on transit network
        $csaResult = $this->csa->earliestArrival($initialStopArrivals, $date, $latestArrivalSec);
        $stopArrivals = $csaResult['arrivals'];
        $predecessors = $csaResult['predecessors'];

        // 3. Multimodal reach to all candidate meeting points.
        // Car owners drive directly to the destination and never take transit to a hub,
        // so skip the full candidate hub evaluation for them.
        $candidatesReach = [];
        $routesKeyed = $this->dataset->routes();

        if (! $hasCar && $includeCandidates) {
            foreach ($candidates as $cand) {
                $bestCandArrival = PHP_INT_MAX;
                $bestCandStartSec = $startTimeSec;
                $bestCandScore = PHP_INT_MAX;
                $bestCandLegs = [];
                $nearbyCandidateStops = $this->getNearbyStops(
                    (float) $cand->lat,
                    (float) $cand->lon,
                    self::MAX_DESTINATION_WALK_METERS
                );

                // Direct walk from origin to candidate
                $directWalkDist = $this->travelTime->distanceMeters($pLat, $pLon, $cand->lat, $cand->lon);
                if ($directWalkDist <= self::MAX_ACCESS_WALK_METERS) {
                    $walkSec = $this->travelTime->durationSeconds($pLat, $pLon, $cand->lat, $cand->lon, 'walk');
                    $arrSec = $routingStartSec + $walkSec;
                    $candidateScore = $this->routeScore($arrSec, $directWalkDist, $latestArrivalSec);
                    if ($candidateScore < $bestCandScore) {
                        $bestCandArrival = $arrSec;
                        $bestCandStartSec = $startTimeSec;
                        $bestCandScore = $candidateScore;
                        $bestCandLegs = [
                            $this->legBuilder->makeLeg([
                                'mode' => 'walk',
                                'from' => ['name' => "Départ ({$pName})", 'lat' => $pLat, 'lon' => $pLon],
                                'to' => ['name' => $cand->name, 'lat' => $cand->lat, 'lon' => $cand->lon],
                                'dep_seconds' => $startTimeSec,
                                'arr_seconds' => $arrSec,
                                'distance_m' => $directWalkDist,
                            ]),
                        ];
                    }
                }

                // Transit options: from reachable stops, walk to candidate
                foreach ($nearbyCandidateStops as $stopId => $nearStop) {
                    if (! isset($stopArrivals[$stopId])) {
                        continue;
                    }
                    $stopArrSec = $stopArrivals[$stopId];
                    $stopObj = $stops[$stopId];
                    $distStopToCand = $nearStop['distance'];
                    $candArrSec = $stopArrSec + $nearStop['walk_sec'];

                    if ($latestArrivalSec !== null && $candArrSec > $latestArrivalSec) {
                        continue;
                    }

                    $reconstructed = $this->reconstructTransitLegs(
                        $stopId,
                        $predecessors,
                        $originAccessParams,
                        $stops,
                        $routesKeyed,
                        $startTimeSec
                    );
                    $legs = $reconstructed['legs'];
                    $routeStartSec = $reconstructed['start_sec'];

                    if ($this->isAbsurdTransitRoute($legs, $routeStartSec, $candArrSec, $directWalkDist)) {
                        continue;
                    }
                    $walkingDistance = $this->walkingDistance($legs) + $distStopToCand;
                    if ($walkingDistance > self::MAX_TOTAL_WALK_METERS) {
                        continue;
                    }

                    $candidateScore = $this->routeScore(
                        $candArrSec,
                        $walkingDistance,
                        $latestArrivalSec
                    );
                    if ($candidateScore < $bestCandScore) {
                        $bestCandArrival = $candArrSec;
                        $bestCandStartSec = $routeStartSec;
                        $bestCandScore = $candidateScore;

                        if ($distStopToCand > 50.0) {
                            $legs[] = $this->legBuilder->makeLeg([
                                'mode' => 'walk',
                                'from' => ['name' => $stopObj->name, 'lat' => $stopObj->lat, 'lon' => $stopObj->lon],
                                'to' => ['name' => $cand->name, 'lat' => $cand->lat, 'lon' => $cand->lon],
                                'dep_seconds' => $stopArrSec,
                                'arr_seconds' => $candArrSec,
                                'distance_m' => $distStopToCand,
                            ]);
                        }

                        $bestCandLegs = $legs;
                    }
                }

                if ($bestCandArrival !== PHP_INT_MAX) {
                    $candidatesReach[$cand->id] = [
                        'candidate_id' => $cand->id,
                        'name' => $cand->name,
                        'lat' => $cand->lat,
                        'lon' => $cand->lon,
                        'start_time_sec' => $bestCandStartSec,
                        'arrival_time_sec' => $bestCandArrival,
                        'legs' => $bestCandLegs,
                    ];
                }
            }
        } // end if (! $hasCar && $includeCandidates)

        // 4. Reachability to Final Destination
        $destLat = (float) $destination['lat'];
        $destLon = (float) $destination['lon'];
        $bestDestArrival = PHP_INT_MAX;
        $bestDestStartSec = $startTimeSec;
        $bestWalkingMeters = INF;
        $bestDestLegs = [];

        // Direct car to destination if car owner
        if ($hasCar) {
            $directCarDist = $this->travelTime->distanceMeters($pLat, $pLon, $destLat, $destLon);
            $directCarSec = $this->travelTime->durationSeconds($pLat, $pLon, $destLat, $destLon, 'car');
            $parkingSec = (int) (config('mobility.parking_time_min', 7) * 60);
            $arrSec = $startTimeSec + $directCarSec + $parkingSec;

            if ($latestArrivalSec === null || $arrSec <= $latestArrivalSec) {
                if ($this->routeScore($arrSec, 0.0, $latestArrivalSec) < $this->routeScore($bestDestArrival, $bestWalkingMeters, $latestArrivalSec)) {
                    $bestDestArrival = $arrSec;
                    $bestDestStartSec = $startTimeSec;
                    $bestWalkingMeters = 0.0;
                    $bestDestLegs = [
                        $this->legBuilder->makeLeg([
                            'mode' => 'car',
                            'from' => ['name' => "Départ ({$pName})", 'lat' => $pLat, 'lon' => $pLon],
                            'to' => ['name' => $destination['name'], 'lat' => $destLat, 'lon' => $destLon],
                            'dep_seconds' => $startTimeSec,
                            'arr_seconds' => $arrSec,
                            'distance_m' => $directCarDist,
                        ]),
                    ];
                }
            }
        }

        // Transit to destination (only for non-car participants)
        if (! $hasCar) {
            $nearbyDestinationStops = $this->getNearbyStops($destLat, $destLon, self::MAX_DESTINATION_WALK_METERS);
            $directWalkDist = $this->travelTime->distanceMeters($pLat, $pLon, $destLat, $destLon);

            foreach ($nearbyDestinationStops as $stopId => $nearStop) {
                if (! isset($stopArrivals[$stopId])) {
                    continue;
                }
                $stopArrSec = $stopArrivals[$stopId];
                $stopObj = $stops[$stopId];
                $distStopToDest = $nearStop['distance'];
                $destArrSec = $stopArrSec + $nearStop['walk_sec'];

                if ($latestArrivalSec !== null && $destArrSec > $latestArrivalSec) {
                    continue;
                }

                $reconstructed = $this->reconstructTransitLegs(
                    $stopId,
                    $predecessors,
                    $originAccessParams,
                    $stops,
                    $routesKeyed,
                    $startTimeSec
                );
                $transitLegs = $reconstructed['legs'];
                $routeStartSec = $reconstructed['start_sec'];

                $walkingMeters = $this->walkingDistance($transitLegs) + $distStopToDest;
                if ($walkingMeters > self::MAX_TOTAL_WALK_METERS) {
                    continue;
                }
                if ($this->isAbsurdTransitRoute($transitLegs, $routeStartSec, $destArrSec, $directWalkDist)) {
                    continue;
                }
                if ($this->routeScore($destArrSec, $walkingMeters, $latestArrivalSec) < $this->routeScore($bestDestArrival, $bestWalkingMeters, $latestArrivalSec)) {
                    $bestDestArrival = $destArrSec;
                    $bestDestStartSec = $routeStartSec;
                    $bestWalkingMeters = $walkingMeters;

                    $legs = $transitLegs;
                    if ($distStopToDest > 50.0) {
                        $legs[] = $this->legBuilder->makeLeg([
                            'mode' => 'walk',
                            'from' => ['name' => $stopObj->name, 'lat' => $stopObj->lat, 'lon' => $stopObj->lon],
                            'to' => ['name' => $destination['name'], 'lat' => $destLat, 'lon' => $destLon],
                            'dep_seconds' => $stopArrSec,
                            'arr_seconds' => $destArrSec,
                            'distance_m' => $distStopToDest,
                        ]);
                    }

                    $bestDestLegs = $legs;
                }
            }

            // Direct walk fallback if close
            if ($directWalkDist <= self::MAX_DIRECT_WALK_METERS) {
                $walkSec = $this->travelTime->durationSeconds($pLat, $pLon, $destLat, $destLon, 'walk');
                $arrSec = $routingStartSec + $walkSec;
                if (($latestArrivalSec === null || $arrSec <= $latestArrivalSec)
                    && $this->routeScore($arrSec, $directWalkDist, $latestArrivalSec) < $this->routeScore($bestDestArrival, $bestWalkingMeters, $latestArrivalSec)
                ) {
                    $bestDestArrival = $arrSec;
                    $bestDestStartSec = $startTimeSec;
                    $bestWalkingMeters = $directWalkDist;
                    $bestDestLegs = [
                        $this->legBuilder->makeLeg([
                            'mode' => 'walk',
                            'from' => ['name' => "Départ ({$pName})", 'lat' => $pLat, 'lon' => $pLon],
                            'to' => ['name' => $destination['name'], 'lat' => $destLat, 'lon' => $destLon],
                            'dep_seconds' => $startTimeSec,
                            'arr_seconds' => $arrSec,
                            'distance_m' => $directWalkDist,
                        ]),
                    ];
                }
            }
        } // end if (! $hasCar)

        // Compute metrics using the participant's actual departure time
        $walkingMeters = 0;
        $transfersCount = 0;
        $totalWaitMin = 0;
        foreach ($bestDestLegs as $l) {
            if ($l['mode'] === 'walk') {
                $walkingMeters += $l['distance_m'];
            }
            if ($l['mode'] === 'bus' || $l['mode'] === 'train') {
                $transfersCount++;
            }
        }
        $transfersCount = max(0, $transfersCount - 1);

        return [
            'participant' => $pName,
            'start_time_sec' => $bestDestStartSec,
            'earliest_at_destination' => $bestDestArrival,
            'best_itinerary_to_destination' => [
                'participant' => $pName,
                'legs' => $bestDestLegs,
                'total_duration_min' => $bestDestArrival === PHP_INT_MAX
                    ? 0
                    : max(1, (int) round(($bestDestArrival - $bestDestStartSec) / 60)),
                'arrival_at_destination' => $this->legBuilder->formatSeconds($bestDestArrival),
                'walking_m' => $walkingMeters,
                'transfers' => $transfersCount,
                'wait_min' => $totalWaitMin,
            ],
            'candidates_reach' => $candidatesReach,
        ];
    }

    /**
     * Find the latest feasible departure for an arrive-by request using reverse CSA.
     *
     * @param  array{name: string, lat: float, lon: float, has_car?: bool, share_ride?: bool}  $participant
     * @param  int  $requestedArrivalSec  Target destination/hub arrival time
     * @param  int  $fallbackStartSec  Start time used when no reverse route is feasible
     * @param  string  $date  (Y-m-d)
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @param  array<string, mixed>|null  $precomputedFallbackReach
     * @return array<string, mixed>
     */
    public function latestReach(
        array $participant,
        int $requestedArrivalSec,
        int $fallbackStartSec,
        string $date,
        array $destination,
        ?int $latestArrivalSec = null,
        bool $includeCandidates = true,
        ?array $precomputedFallbackReach = null
    ): array {
        $targets = [$requestedArrivalSec];
        if ($latestArrivalSec !== null && $latestArrivalSec > $requestedArrivalSec) {
            $targets[] = $latestArrivalSec;
        }

        foreach ($targets as $targetArrivalSec) {
            $reach = $this->solveReverseReach(
                $participant,
                $targetArrivalSec,
                $fallbackStartSec,
                $date,
                $destination,
                $latestArrivalSec ?? $targetArrivalSec,
                $includeCandidates
            );
            if ($reach !== null) {
                return $reach;
            }
        }

        return $precomputedFallbackReach
            ?? $this->reach($participant, $fallbackStartSec, $date, $destination, $latestArrivalSec, $includeCandidates);
    }

    /**
     * @param  array{name: string, lat: float, lon: float, has_car?: bool, share_ride?: bool}  $participant
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @return array<string, mixed>|null
     */
    protected function solveReverseReach(
        array $participant,
        int $targetArrivalSec,
        int $minDepSec,
        string $date,
        array $destination,
        int $hardDeadlineSec,
        bool $includeCandidates
    ): ?array {
        $pLat = (float) $participant['lat'];
        $pLon = (float) $participant['lon'];
        $destLat = (float) $destination['lat'];
        $destLon = (float) $destination['lon'];
        $candidateStarts = [];

        if ((bool) ($participant['has_car'] ?? false)) {
            $driveSec = $this->travelTime->durationSeconds($pLat, $pLon, $destLat, $destLon, 'car');
            $parkingSec = (int) (config('mobility.parking_time_min', 7) * 60);
            $latestStart = $targetArrivalSec - $driveSec - $parkingSec;

            if ($latestStart >= 0) {
                $candidateStarts[] = $latestStart;
            }
        } else {
            $destinationArrivals = [];
            foreach ($this->getNearbyStops($destLat, $destLon, self::MAX_DESTINATION_WALK_METERS) as $stopId => $near) {
                $destinationArrivals[$stopId] = $targetArrivalSec - $near['walk_sec'];
            }

            $directWalkDist = $this->travelTime->distanceMeters($pLat, $pLon, $destLat, $destLon);
            if ($directWalkDist <= self::MAX_DIRECT_WALK_METERS) {
                $walkSec = $this->travelTime->durationSeconds($pLat, $pLon, $destLat, $destLon, 'walk');
                $candidateStarts[] = $targetArrivalSec - $walkSec;
            }

            if ($destinationArrivals !== []) {
                $latestByStop = $this->csa->latestDeparture($destinationArrivals, $date, $minDepSec);

                foreach ($this->getNearbyStops($pLat, $pLon, self::MAX_ACCESS_WALK_METERS) as $stopId => $near) {
                    if (! isset($latestByStop[$stopId])) {
                        continue;
                    }

                    $candidateStarts[] = $latestByStop[$stopId] - $near['walk_sec'];
                }
            }
        }

        $candidateStarts = array_values(array_unique(array_filter(
            $candidateStarts,
            static fn(int $start): bool => $start >= 0
        )));
        rsort($candidateStarts);

        foreach (array_slice($candidateStarts, 0, 2) as $startSec) {
            $reach = $this->reach($participant, $startSec, $date, $destination, $hardDeadlineSec, $includeCandidates);

            if ($reach['earliest_at_destination'] !== PHP_INT_MAX) {
                return $reach;
            }
        }

        return null;
    }

    /**
     * Find and memoize stops within $maxMeters of a coordinate using a fast bounding-box pre-filter.
     *
     * @return array<string, array{distance: float, walk_sec: int}>
     */
    protected function getNearbyStops(float $lat, float $lon, float $maxMeters): array
    {
        $cacheKey = round($lat, 6) . ':' . round($lon, 6) . ':' . (int) $maxMeters;
        if (isset($this->nearbyStopsCache[$cacheKey])) {
            return $this->nearbyStopsCache[$cacheKey];
        }

        $latDelta = ($maxMeters / 111320.0) * 1.25;
        $lonScale = max(0.2, cos(deg2rad($lat)));
        $lonDelta = ($maxMeters / (111320.0 * $lonScale)) * 1.25;

        $nearby = [];
        foreach ($this->dataset->stops() as $stopId => $stop) {
            $sLat = (float) $stop->lat;
            $sLon = (float) $stop->lon;

            if (abs($sLat - $lat) > $latDelta || abs($sLon - $lon) > $lonDelta) {
                continue;
            }

            $distance = $this->travelTime->distanceMeters($lat, $lon, $sLat, $sLon);
            if ($distance <= $maxMeters) {
                $nearby[$stopId] = [
                    'distance' => $distance,
                    'walk_sec' => $this->travelTime->durationSeconds($lat, $lon, $sLat, $sLon, 'walk'),
                ];
            }
        }

        return $this->nearbyStopsCache[$cacheKey] = $nearby;
    }

    /**
     * Reconstruct transit legs by following the CSA predecessor tree backwards,
     * and align the initial walk leg so the participant leaves just in time for the first transit leg.
     *
     * @param  array<string, array<string, mixed>>  $predecessors
     * @param  array<string, array<string, mixed>>  $originAccessParams
     * @param  Collection<string, Stop>  $stops
     * @param  Collection<string, Route>  $routes
     * @return array{legs: list<array<string, mixed>>, start_sec: int}
     */
    protected function reconstructTransitLegs(
        string $targetStopId,
        array $predecessors,
        array $originAccessParams,
        $stops,
        $routes,
        int $fallbackStartSec
    ): array {
        $chain = [];
        $curr = $targetStopId;

        while (isset($predecessors[$curr])) {
            $pred = $predecessors[$curr];

            if ($pred['type'] === 'origin') {
                if (isset($originAccessParams[$curr])) {
                    $chain[] = $originAccessParams[$curr];
                }
                break;
            }

            if ($pred['type'] === 'transit') {
                $fromStop = $stops->get($pred['from_stop']);
                $toStop = $stops->get($pred['to_stop']);
                $route = $routes->get($pred['route_id']);

                $isRail = $route && $route->route_type === 2;
                $mode = $isRail ? 'train' : 'bus';

                $chain[] = [
                    'mode' => $mode,
                    'line' => $route ? ($route->short_name ?: $route->long_name) : 'Ligne',
                    'line_color' => $route ? $route->route_color : ($isRail ? '#7C3AED' : '#2563EB'),
                    'trip_id' => $pred['trip_id'],
                    'from' => ['name' => $fromStop ? $fromStop->name : $pred['from_stop'], 'lat' => $fromStop ? $fromStop->lat : 0.0, 'lon' => $fromStop ? $fromStop->lon : 0.0],
                    'to' => ['name' => $toStop ? $toStop->name : $pred['to_stop'], 'lat' => $toStop ? $toStop->lat : 0.0, 'lon' => $toStop ? $toStop->lon : 0.0],
                    'dep_seconds' => (int) $pred['dep_time'],
                    'arr_seconds' => (int) $pred['arr_time'],
                    'distance_m' => $fromStop && $toStop ? $this->travelTime->distanceMeters($fromStop->lat, $fromStop->lon, $toStop->lat, $toStop->lon) : 1000,
                ];

                $curr = $pred['from_stop'];
            } elseif ($pred['type'] === 'transfer') {
                $fromStop = $stops->get($pred['from_stop']);
                $toStop = $stops->get($pred['to_stop']);

                $chain[] = [
                    'mode' => 'walk',
                    'from' => ['name' => $fromStop ? $fromStop->name : $pred['from_stop'], 'lat' => $fromStop ? $fromStop->lat : 0.0, 'lon' => $fromStop ? $fromStop->lon : 0.0],
                    'to' => ['name' => $toStop ? $toStop->name : $pred['to_stop'], 'lat' => $toStop ? $toStop->lat : 0.0, 'lon' => $toStop ? $toStop->lon : 0.0],
                    'dep_seconds' => (int) $pred['time'] - (int) $pred['duration'],
                    'arr_seconds' => (int) $pred['time'],
                    'distance_m' => $fromStop && $toStop ? $this->travelTime->distanceMeters($fromStop->lat, $fromStop->lon, $toStop->lat, $toStop->lon) : 200,
                ];

                $curr = $pred['from_stop'];
            } else {
                break;
            }
        }

        $reconstructed = array_reverse($chain);

        if (
            count($reconstructed) >= 2
            && $reconstructed[0]['mode'] === 'walk'
            && in_array($reconstructed[1]['mode'], ['bus', 'train'], true)
        ) {
            $walkDuration = max(0, (int) $reconstructed[0]['arr_seconds'] - (int) $reconstructed[0]['dep_seconds']);
            $firstTransitDep = (int) $reconstructed[1]['dep_seconds'];
            $reconstructed[0]['arr_seconds'] = $firstTransitDep;
            $reconstructed[0]['dep_seconds'] = max(0, $firstTransitDep - $walkDuration);
        }

        $actualStartSec = $reconstructed !== []
            ? (int) $reconstructed[0]['dep_seconds']
            : $fallbackStartSec;

        $legs = array_map(
            fn(array $legParams): array => $this->legBuilder->makeLeg($legParams),
            $reconstructed
        );

        return [
            'legs' => $legs,
            'start_sec' => $actualStartSec,
        ];
    }

    protected function stopKey(string $feedId, string $recordId): string
    {
        return $feedId . ':' . $recordId;
    }

    private function routeScore(int $arrivalSeconds, float $walkingMeters, ?int $latestArrivalSec = null): float
    {
        if ($arrivalSeconds === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        if ($latestArrivalSec !== null && $arrivalSeconds > $latestArrivalSec) {
            return PHP_INT_MAX;
        }

        return $arrivalSeconds + ($walkingMeters * self::WALKING_PENALTY_SECONDS_PER_METER);
    }

    /**
     * @param  list<array<string, mixed>>  $legs
     */
    private function walkingDistance(array $legs): float
    {
        return array_sum(array_map(
            fn(array $leg): float => $leg['mode'] === 'walk' ? (float) $leg['distance_m'] : 0.0,
            $legs
        ));
    }

    /**
     * Discard transit routes that are clearly worse than walking directly.
     *
     * @param  list<array<string, mixed>>  $legs
     */
    private function isAbsurdTransitRoute(
        array $legs,
        int $startTimeSec,
        int $arrivalSeconds,
        float $directWalkDistance
    ): bool {
        if ($directWalkDistance <= 0.0 || $legs === []) {
            return false;
        }

        $directWalkSeconds = $this->travelTime->durationSeconds(
            (float) $legs[0]['from']['lat'],
            (float) $legs[0]['from']['lon'],
            (float) $legs[array_key_last($legs)]['to']['lat'],
            (float) $legs[array_key_last($legs)]['to']['lon'],
            'walk'
        );
        $walkingDistance = $this->walkingDistance($legs);
        $transitDuration = $arrivalSeconds - $startTimeSec;

        return $transitDuration > $directWalkSeconds + self::MAX_TRANSIT_DETOUR_SECONDS
            && $walkingDistance >= $directWalkDistance * self::MAX_TRANSIT_WALKING_RATIO;
    }
}
