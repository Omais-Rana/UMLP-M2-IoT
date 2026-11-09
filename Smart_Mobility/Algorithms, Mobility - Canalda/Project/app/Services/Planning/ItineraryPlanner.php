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
    private const MAX_ACCESS_WALK_METERS = 1500.0;

    private const MAX_DESTINATION_WALK_METERS = 1500.0;

    private const MAX_DIRECT_WALK_METERS = 1500.0;

    private const WALKING_PENALTY_SECONDS_PER_METER = 0.72;

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
     *     earliest_at_destination: int,
     *     best_itinerary_to_destination: array<string, mixed>,
     *     candidates_reach: array<int, array{
     *         candidate_id: int,
     *         name: string,
     *         lat: float,
     *         lon: float,
     *         arrival_time_sec: int,
     *         legs: list<array<string, mixed>>
     *     }>
     * }
     */
    public function reach(
        array $participant,
        int $startTimeSec,
        string $date,
        array $destination
    ): array {
        $pName = $participant['name'];
        $pLat = (float) $participant['lat'];
        $pLon = (float) $participant['lon'];
        $hasCar = (bool) ($participant['has_car'] ?? false);

        $stops = $this->dataset->stops();
        $candidates = $this->dataset->candidates();

        // 1. Initial stop arrivals from origin
        $initialStopArrivals = [];
        $originAccessLegs = []; // [stop_id => leg]

        foreach ($stops as $stopId => $stop) {
            $distMeters = $this->travelTime->distanceMeters($pLat, $pLon, $stop->lat, $stop->lon);

            // A. Walking access (up to 2000m)
            if ($distMeters <= self::MAX_ACCESS_WALK_METERS) {
                $walkSec = $this->travelTime->durationSeconds($pLat, $pLon, $stop->lat, $stop->lon, 'walk');
                $arrSec = $startTimeSec + $walkSec;

                if (! isset($initialStopArrivals[$stopId]) || $arrSec < $initialStopArrivals[$stopId]) {
                    $initialStopArrivals[$stopId] = $arrSec;
                    $originAccessLegs[$stopId] = $this->legBuilder->makeLeg([
                        'mode' => 'walk',
                        'from' => ['name' => "Départ ({$pName})", 'lat' => $pLat, 'lon' => $pLon],
                        'to' => ['name' => $stop->name, 'lat' => $stop->lat, 'lon' => $stop->lon],
                        'dep_seconds' => $startTimeSec,
                        'arr_seconds' => $arrSec,
                        'distance_m' => $distMeters,
                    ]);
                }

            }

        }

        // 2. Run Connection Scan on transit network
        $csaResult = $this->csa->earliestArrival($initialStopArrivals, $date);
        $stopArrivals = $csaResult['arrivals'];
        $predecessors = $csaResult['predecessors'];

        // 3. Multimodal reach to all candidate meeting points
        $candidatesReach = [];
        $routesKeyed = $this->dataset->routes();

        foreach ($candidates as $cand) {
            $bestCandArrival = PHP_INT_MAX;
            $bestCandScore = PHP_INT_MAX;
            $bestCandLegs = [];

            // Direct walk from origin to candidate
            $directWalkDist = $this->travelTime->distanceMeters($pLat, $pLon, $cand->lat, $cand->lon);
            if ($directWalkDist <= self::MAX_ACCESS_WALK_METERS) {
                $walkSec = $this->travelTime->durationSeconds($pLat, $pLon, $cand->lat, $cand->lon, 'walk');
                $arrSec = $startTimeSec + $walkSec;
                $candidateScore = $this->routeScore($arrSec, $directWalkDist);
                if ($candidateScore < $bestCandScore) {
                    $bestCandArrival = $arrSec;
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
            foreach ($stopArrivals as $stopId => $stopArrSec) {
                if (! isset($stops[$stopId])) {
                    continue;
                }
                $stopObj = $stops[$stopId];
                $distStopToCand = $this->travelTime->distanceMeters($stopObj->lat, $stopObj->lon, $cand->lat, $cand->lon);

                if ($distStopToCand <= self::MAX_DESTINATION_WALK_METERS) {
                    $finalWalkSec = $this->travelTime->durationSeconds($stopObj->lat, $stopObj->lon, $cand->lat, $cand->lon, 'walk');
                    $candArrSec = $stopArrSec + $finalWalkSec;

                    $legs = $this->reconstructTransitLegs($stopId, $predecessors, $originAccessLegs, $stops, $routesKeyed);
                    $candidateScore = $this->routeScore(
                        $candArrSec,
                        $this->walkingDistance($legs) + $distStopToCand
                    );
                    if ($candidateScore < $bestCandScore) {
                        $bestCandArrival = $candArrSec;
                        $bestCandScore = $candidateScore;

                        // Reconstruct legs from origin to candidate
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
            }

            if ($bestCandArrival !== PHP_INT_MAX) {
                $candidatesReach[$cand->id] = [
                    'candidate_id' => $cand->id,
                    'name' => $cand->name,
                    'lat' => $cand->lat,
                    'lon' => $cand->lon,
                    'arrival_time_sec' => $bestCandArrival,
                    'legs' => $bestCandLegs,
                ];
            }
        }

        // 4. Reachability to Final Destination
        $destLat = (float) $destination['lat'];
        $destLon = (float) $destination['lon'];
        $bestDestArrival = PHP_INT_MAX;
        $bestWalkingMeters = INF;
        $bestDestLegs = [];

        // Direct car to destination if car owner
        if ($hasCar) {
            $directCarDist = $this->travelTime->distanceMeters($pLat, $pLon, $destLat, $destLon);
            $directCarSec = $this->travelTime->durationSeconds($pLat, $pLon, $destLat, $destLon, 'car');
            $parkingSec = (int) (config('mobility.parking_time_min', 7) * 60);
            $arrSec = $startTimeSec + $directCarSec + $parkingSec;

            if ($this->routeScore($arrSec, 0.0) < $this->routeScore($bestDestArrival, $bestWalkingMeters)) {
                $bestDestArrival = $arrSec;
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

        // Transit to destination
        foreach ($stopArrivals as $stopId => $stopArrSec) {
            if (! isset($stops[$stopId])) {
                continue;
            }
            $stopObj = $stops[$stopId];
            $distStopToDest = $this->travelTime->distanceMeters($stopObj->lat, $stopObj->lon, $destLat, $destLon);

            if ($distStopToDest <= self::MAX_DESTINATION_WALK_METERS) {
                $finalWalkSec = $this->travelTime->durationSeconds($stopObj->lat, $stopObj->lon, $destLat, $destLon, 'walk');
                $destArrSec = $stopArrSec + $finalWalkSec;

                $transitLegs = $this->reconstructTransitLegs($stopId, $predecessors, $originAccessLegs, $stops, $routesKeyed);
                $walkingMeters = $this->walkingDistance($transitLegs) + $distStopToDest;
                if ($this->routeScore($destArrSec, $walkingMeters) < $this->routeScore($bestDestArrival, $bestWalkingMeters)) {
                    $bestDestArrival = $destArrSec;
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
        }

        // Direct walk fallback if close
        $directWalkToDest = $this->travelTime->distanceMeters($pLat, $pLon, $destLat, $destLon);
        if ($directWalkToDest <= self::MAX_DIRECT_WALK_METERS) {
            $walkSec = $this->travelTime->durationSeconds($pLat, $pLon, $destLat, $destLon, 'walk');
            $arrSec = $startTimeSec + $walkSec;
            if ($this->routeScore($arrSec, $directWalkToDest) < $this->routeScore($bestDestArrival, $bestWalkingMeters)) {
                $bestDestArrival = $arrSec;
                $bestWalkingMeters = $directWalkToDest;
                $bestDestLegs = [
                    $this->legBuilder->makeLeg([
                        'mode' => 'walk',
                        'from' => ['name' => "Départ ({$pName})", 'lat' => $pLat, 'lon' => $pLon],
                        'to' => ['name' => $destination['name'], 'lat' => $destLat, 'lon' => $destLon],
                        'dep_seconds' => $startTimeSec,
                        'arr_seconds' => $arrSec,
                        'distance_m' => $directWalkToDest,
                    ]),
                ];
            }
        }

        // Compute metrics
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
            'earliest_at_destination' => $bestDestArrival,
            'best_itinerary_to_destination' => [
                'participant' => $pName,
                'legs' => $bestDestLegs,
                'total_duration_min' => max(1, (int) round(($bestDestArrival - $startTimeSec) / 60)),
                'arrival_at_destination' => $this->legBuilder->formatSeconds($bestDestArrival),
                'walking_m' => $walkingMeters,
                'transfers' => $transfersCount,
                'wait_min' => $totalWaitMin,
            ],
            'candidates_reach' => $candidatesReach,
        ];
    }

    /**
     * Reconstruct transit legs by following the CSA predecessor tree backwards.
     *
     * @param  array<string, array<string, mixed>>  $predecessors
     * @param  array<string, array<string, mixed>>  $originAccessLegs
     * @param  Collection<string, Stop>  $stops
     * @param  Collection<string, Route>  $routes
     * @return list<array<string, mixed>>
     */
    protected function reconstructTransitLegs(
        string $targetStopId,
        array $predecessors,
        array $originAccessLegs,
        $stops,
        $routes
    ): array {
        $chain = [];
        $curr = $targetStopId;

        while (isset($predecessors[$curr])) {
            $pred = $predecessors[$curr];

            if ($pred['type'] === 'origin') {
                if (isset($originAccessLegs[$curr])) {
                    $chain[] = $originAccessLegs[$curr];
                }
                break;
            }

            if ($pred['type'] === 'transit') {
                $fromStop = $stops->get($pred['from_stop']);
                $toStop = $stops->get($pred['to_stop']);
                $route = $routes->get($pred['route_id']);

                $isRail = $route && $route->route_type === 2;
                $mode = $isRail ? 'train' : 'bus';

                $chain[] = $this->legBuilder->makeLeg([
                    'mode' => $mode,
                    'line' => $route ? ($route->short_name ?: $route->long_name) : 'Ligne',
                    'line_color' => $route ? $route->route_color : ($isRail ? '#7C3AED' : '#2563EB'),
                    'trip_id' => $pred['trip_id'],
                    'from' => ['name' => $fromStop ? $fromStop->name : $pred['from_stop'], 'lat' => $fromStop ? $fromStop->lat : 0.0, 'lon' => $fromStop ? $fromStop->lon : 0.0],
                    'to' => ['name' => $toStop ? $toStop->name : $pred['to_stop'], 'lat' => $toStop ? $toStop->lat : 0.0, 'lon' => $toStop ? $toStop->lon : 0.0],
                    'dep_seconds' => $pred['dep_time'],
                    'arr_seconds' => $pred['arr_time'],
                    'distance_m' => $fromStop && $toStop ? $this->travelTime->distanceMeters($fromStop->lat, $fromStop->lon, $toStop->lat, $toStop->lon) : 1000,
                ]);

                $curr = $pred['from_stop'];
            } elseif ($pred['type'] === 'transfer') {
                $fromStop = $stops->get($pred['from_stop']);
                $toStop = $stops->get($pred['to_stop']);

                $chain[] = $this->legBuilder->makeLeg([
                    'mode' => 'walk',
                    'from' => ['name' => $fromStop ? $fromStop->name : $pred['from_stop'], 'lat' => $fromStop ? $fromStop->lat : 0.0, 'lon' => $fromStop ? $fromStop->lon : 0.0],
                    'to' => ['name' => $toStop ? $toStop->name : $pred['to_stop'], 'lat' => $toStop ? $toStop->lat : 0.0, 'lon' => $toStop ? $toStop->lon : 0.0],
                    'dep_seconds' => $pred['time'] - $pred['duration'],
                    'arr_seconds' => $pred['time'],
                    'distance_m' => $fromStop && $toStop ? $this->travelTime->distanceMeters($fromStop->lat, $fromStop->lon, $toStop->lat, $toStop->lon) : 200,
                ]);

                $curr = $pred['from_stop'];
            } else {
                break;
            }
        }

        return array_reverse($chain);
    }

    protected function stopKey(string $feedId, string $recordId): string
    {
        return $feedId.':'.$recordId;
    }

    private function routeScore(int $arrivalSeconds, float $walkingMeters): float
    {
        if ($arrivalSeconds === PHP_INT_MAX) {
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
            fn (array $leg): float => $leg['mode'] === 'walk' ? (float) $leg['distance_m'] : 0.0,
            $legs
        ));
    }
}
