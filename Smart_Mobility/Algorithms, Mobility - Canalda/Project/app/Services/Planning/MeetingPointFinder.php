<?php

namespace App\Services\Planning;

use App\Models\CandidatePoint;
use App\Models\CarPark;
use App\Models\CarShareStation;
use App\Models\Route;
use App\Models\Stop;
use App\Services\Routing\ConnectionScan;
use App\Services\Routing\LegBuilder;
use App\Services\Routing\TravelTimeProvider;
use Illuminate\Support\Collection;

class MeetingPointFinder
{
    private const MAX_GROUP_WALK_METERS = 500.0;

    public function __construct(
        protected ItineraryPlanner $planner,
        protected CarAssignment $carAssignment,
        protected ConnectionScan $csa,
        protected TravelTimeProvider $travelTime,
        protected LegBuilder $legBuilder,
        protected PlanningDataset $dataset,
    ) {}

    /**
     * Find and rank the best multimodal meeting strategies.
     *
     * @param list<array{
     *     name: string,
     *     lat: float,
     *     lon: float,
     *     has_car: bool,
     *     share_ride?: bool
     * }> $participants
     * @param  string  $date  (Y-m-d)
     * @param  string  $time  (H:i)
     * @param  string  $timeType  ('depart_at' or 'arrive_by')
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @param  string  $objective  ('latest_arrival' or 'sum_arrivals')
     * @return list<array<string, mixed>> Top 3 ranked options
     */
    public function find(
        array $participants,
        string $date,
        string $time,
        string $timeType,
        array $destination,
        string $objective = 'latest_arrival',
        ?callable $onParticipantCompleted = null
    ): array {
        $timeParts = explode(':', $time);
        $requestedTimeSec = ((int) ($timeParts[0] ?? 8) * 3600) + ((int) ($timeParts[1] ?? 30) * 60);
        $startTimeSec = $timeType === 'arrive_by'
            ? max(0, $requestedTimeSec - (3 * 3600))
            : $requestedTimeSec;
        $latestArrivalSec = $timeType === 'arrive_by' ? $requestedTimeSec : null;

        // 1. Compute reachability for all participants. Arrival-by searches
        // sample departure times so the result is the latest feasible option,
        // rather than the earliest option from the start of the search window.
        $reachMap = [];
        foreach ($participants as $p) {
            $reachMap[$p['name']] = $this->findBestReach(
                $p,
                $startTimeSec,
                $requestedTimeSec,
                $date,
                $destination,
                $latestArrivalSec
            );
            if ($onParticipantCompleted !== null) {
                $onParticipantCompleted($p['name'], count($reachMap));
            }
        }

        $allStrategies = [];

        // 2. Hub-based Meeting Point Strategies
        $candidates = $this->dataset->candidates();
        $stops = $this->dataset->stops();
        $routesKeyed = $this->dataset->routes();
        $destLat = (float) $destination['lat'];
        $destLon = (float) $destination['lon'];

        foreach ($candidates as $cand) {
            $candId = $cand->id;
            $allCanReach = true;
            $arrivalTimes = [];
            $participantLegsToHub = [];

            foreach ($participants as $p) {
                $pName = $p['name'];
                $pReach = $reachMap[$pName]['candidates_reach'][$candId] ?? null;

                if ($pReach === null) {
                    $allCanReach = false;
                    break;
                }

                $arrivalTimes[$pName] = $pReach['arrival_time_sec'];
                $participantLegsToHub[$pName] = $pReach['legs'];
            }

            if (! $allCanReach) {
                continue;
            }

            // Synchronized group departure time from hub
            $tLeaveHub = max($arrivalTimes);

            // Compute group leg from hub to destination D
            $groupLeg = $this->computeGroupLegFromHub(
                $cand,
                $destination,
                $tLeaveHub,
                $date,
                $stops,
                $routesKeyed
            );

            if ($groupLeg === null) {
                continue;
            }

            $finalArrivalSec = $groupLeg['arrival_sec'];
            if ($latestArrivalSec !== null && $finalArrivalSec > $latestArrivalSec) {
                continue;
            }
            $itineraries = [];
            $totalWaitMin = 0;
            $totalWalkingM = 0;
            $sumArrivals = 0;

            foreach ($participants as $p) {
                $pName = $p['name'];
                $waitSec = max(0, $tLeaveHub - $arrivalTimes[$pName]);
                $waitMin = (int) round($waitSec / 60);
                $totalWaitMin += $waitMin;

                $legs = $participantLegsToHub[$pName];
                $legs[] = $groupLeg['leg'];

                $pWalkingM = 0;
                foreach ($legs as $l) {
                    if ($l['mode'] === 'walk') {
                        $pWalkingM += $l['distance_m'];
                    }
                }
                $totalWalkingM += $pWalkingM;
                $sumArrivals += $finalArrivalSec;

                $itineraries[] = [
                    'participant' => $pName,
                    'total_duration_min' => max(1, (int) round(($finalArrivalSec - $startTimeSec) / 60)),
                    'arrival_at_destination' => $this->legBuilder->formatSeconds($finalArrivalSec),
                    'wait_min' => $waitMin,
                    'transfers' => 1,
                    'walking_m' => $pWalkingM,
                    'legs' => $legs,
                ];
            }

            $allStrategies[] = [
                'title' => "Rendez-vous à {$cand->name}",
                'meeting_points' => [
                    [
                        'name' => $cand->name,
                        'lat' => $cand->lat,
                        'lon' => $cand->lon,
                        'type' => $cand->transit_hub ? 'hub' : 'hub_parking',
                    ],
                ],
                'arrival_at_destination' => $this->legBuilder->formatSeconds($finalArrivalSec),
                'arrival_sec' => $finalArrivalSec,
                'sum_arrivals_sec' => $sumArrivals,
                'total_wait_min' => $totalWaitMin,
                'total_walking_m' => $totalWalkingM,
                'itineraries' => $itineraries,
                'car_decisions' => [],
                'strategy_type' => 'hub',
            ];
        }

        // 3. Route-aware Car Pickup Strategy
        $carpoolSolution = $this->carAssignment->solve(
            $participants,
            $reachMap,
            $destination,
            $startTimeSec
        );

        if ($carpoolSolution !== null) {
            $totalWaitMin = 0;
            $totalWalkingM = 0;
            $sumArrivals = 0;

            foreach ($carpoolSolution['itineraries'] as $itin) {
                $totalWaitMin += $itin['wait_min'];
                $totalWalkingM += $itin['walking_m'];
                // Duration in seconds
                $sumArrivals += $carpoolSolution['arrival_at_destination_sec'];
            }

            $allStrategies[] = [
                'title' => 'Covoiturage avec ramassage sur itinéraire',
                'meeting_points' => $carpoolSolution['meeting_points'],
                'arrival_at_destination' => $carpoolSolution['arrival_at_destination'],
                'arrival_sec' => $carpoolSolution['arrival_at_destination_sec'],
                'sum_arrivals_sec' => $sumArrivals,
                'total_wait_min' => $totalWaitMin,
                'total_walking_m' => $totalWalkingM,
                'itineraries' => $carpoolSolution['itineraries'],
                'car_decisions' => $carpoolSolution['car_decisions'],
                'strategy_type' => 'car_pickup',
            ];
        }

        // 4. One-way shared car is available only for cross-city journeys.
        $sharedCarStrategy = $this->sharedCarStrategy($participants, $destination, $startTimeSec);
        if ($sharedCarStrategy !== null) {
            $allStrategies[] = $sharedCarStrategy;
        }

        // 5. Fallback direct strategy (each participant goes directly to destination)
        $directGroupArrival = 0;
        $directItins = [];
        $directWaitMin = 0;
        $directWalkingM = 0;
        $directSumArrivals = 0;

        foreach ($participants as $p) {
            $pName = $p['name'];
            $itin = $reachMap[$pName]['best_itinerary_to_destination'];
            $pArr = $reachMap[$pName]['earliest_at_destination'];
            $directGroupArrival = max($directGroupArrival, $pArr);
            $directSumArrivals += $pArr;
            $directWaitMin += $itin['wait_min'];
            $directWalkingM += $itin['walking_m'];
            $directItins[] = $itin;
        }

        $allStrategies[] = [
            'title' => 'Trajets directs indépendants vers la destination',
            'meeting_points' => [],
            'arrival_at_destination' => $this->legBuilder->formatSeconds($directGroupArrival),
            'arrival_sec' => $directGroupArrival,
            'sum_arrivals_sec' => $directSumArrivals,
            'total_wait_min' => $directWaitMin,
            'total_walking_m' => $directWalkingM,
            'itineraries' => $directItins,
            'car_decisions' => [],
            'strategy_type' => 'direct',
        ];

        // 5. Sort strategies by objective and tie-breakers
        if ($latestArrivalSec !== null) {
            $allStrategies = array_values(array_filter(
                $allStrategies,
                fn (array $strategy): bool => $strategy['arrival_sec'] <= $latestArrivalSec
            ));
        }

        usort($allStrategies, function ($a, $b) use ($objective) {
            $aHasMeetingPoint = $a['meeting_points'] !== [];
            $bHasMeetingPoint = $b['meeting_points'] !== [];
            if ($aHasMeetingPoint !== $bHasMeetingPoint) {
                return $aHasMeetingPoint ? -1 : 1;
            }

            if ($objective === 'sum_arrivals') {
                if ($a['sum_arrivals_sec'] !== $b['sum_arrivals_sec']) {
                    return $a['sum_arrivals_sec'] <=> $b['sum_arrivals_sec'];
                }
            } else {
                // Primary: earliest latest arrival at D
                if ($a['arrival_sec'] !== $b['arrival_sec']) {
                    return $a['arrival_sec'] <=> $b['arrival_sec'];
                }
            }

            // Secondary: total waiting time
            if ($a['total_wait_min'] !== $b['total_wait_min']) {
                return $a['total_wait_min'] <=> $b['total_wait_min'];
            }

            // Tertiary: total walking distance
            return $a['total_walking_m'] <=> $b['total_walking_m'];
        });

        // 6. Return Top N options (distinct meeting points where possible)
        $topN = (int) config('mobility.top_n_results', 3);
        $selected = [];
        $seenTitles = [];

        foreach ($allStrategies as $strat) {
            $key = $strat['title'].'_'.$strat['arrival_at_destination'];
            if (! isset($seenTitles[$key])) {
                $seenTitles[$key] = true;
                $selected[] = $strat;
                if (count($selected) >= $topN) {
                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * Build a one-way GBFS car option for a participant crossing the prototype city boundary.
     *
     * @param  list<array{name: string, lat: float, lon: float, has_car: bool, share_ride?: bool}>  $participants
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @return array<string, mixed>|null
     */
    protected function sharedCarStrategy(array $participants, array $destination, int $startTimeSec): ?array
    {
        $destinationZone = $this->cityZone((float) $destination['lat'], (float) $destination['lon']);
        if ($destinationZone === null) {
            return null;
        }

        $station = CarShareStation::query()->where('num_cars_available', '>', 0)->orderByDesc('num_cars_available')->first();
        $parking = CarPark::query()->get()->sortBy(fn (CarPark $park): float => $this->travelTime->distanceMeters(
            (float) $park->lat,
            (float) $park->lon,
            (float) $destination['lat'],
            (float) $destination['lon'],
        ))->first();
        if ($station === null || $parking === null) {
            return null;
        }

        $itineraries = [];
        $latestArrival = 0;
        $sumArrivals = 0;
        foreach ($participants as $participant) {
            $participantZone = $this->cityZone((float) $participant['lat'], (float) $participant['lon']);
            if (($participant['has_car'] ?? false) || $participantZone === null || $participantZone === $destinationZone) {
                continue;
            }

            $walkDistance = $this->travelTime->distanceMeters((float) $participant['lat'], (float) $participant['lon'], (float) $station->lat, (float) $station->lon);
            $walkToStation = $this->travelTime->durationSeconds((float) $participant['lat'], (float) $participant['lon'], (float) $station->lat, (float) $station->lon, 'walk');
            $stationDeparture = $startTimeSec + $walkToStation;
            $carDistance = $this->travelTime->distanceMeters((float) $station->lat, (float) $station->lon, (float) $parking->lat, (float) $parking->lon);
            $carArrival = $stationDeparture + $this->travelTime->durationSeconds((float) $station->lat, (float) $station->lon, (float) $parking->lat, (float) $parking->lon, 'car');
            $finalDistance = $this->travelTime->distanceMeters((float) $parking->lat, (float) $parking->lon, (float) $destination['lat'], (float) $destination['lon']);
            $finalArrival = $carArrival + $this->travelTime->durationSeconds((float) $parking->lat, (float) $parking->lon, (float) $destination['lat'], (float) $destination['lon'], 'walk');
            $legs = [
                $this->legBuilder->makeLeg(['mode' => 'walk', 'from' => ['name' => $participant['name'], 'lat' => $participant['lat'], 'lon' => $participant['lon']], 'to' => ['name' => $station->name, 'lat' => $station->lat, 'lon' => $station->lon], 'dep_seconds' => $startTimeSec, 'arr_seconds' => $stationDeparture, 'distance_m' => $walkDistance]),
                $this->legBuilder->makeLeg(['mode' => 'car_share', 'line' => 'GBFS car', 'from' => ['name' => $station->name, 'lat' => $station->lat, 'lon' => $station->lon], 'to' => ['name' => $parking->name, 'lat' => $parking->lat, 'lon' => $parking->lon], 'dep_seconds' => $stationDeparture, 'arr_seconds' => $carArrival, 'distance_m' => $carDistance]),
                $this->legBuilder->makeLeg(['mode' => 'walk', 'from' => ['name' => $parking->name, 'lat' => $parking->lat, 'lon' => $parking->lon], 'to' => ['name' => $destination['name'], 'lat' => $destination['lat'], 'lon' => $destination['lon']], 'dep_seconds' => $carArrival, 'arr_seconds' => $finalArrival, 'distance_m' => $finalDistance]),
            ];
            $itineraries[] = ['participant' => $participant['name'], 'total_duration_min' => max(1, (int) round(($finalArrival - $startTimeSec) / 60)), 'arrival_at_destination' => $this->legBuilder->formatSeconds($finalArrival), 'wait_min' => 0, 'transfers' => 0, 'walking_m' => $walkDistance + $finalDistance, 'legs' => $legs];
            $latestArrival = max($latestArrival, $finalArrival);
            $sumArrivals += $finalArrival;
        }

        return $itineraries === [] ? null : ['title' => 'Voiture partagée GBFS (trajet simple)', 'meeting_points' => [], 'arrival_at_destination' => $this->legBuilder->formatSeconds($latestArrival), 'arrival_sec' => $latestArrival, 'sum_arrivals_sec' => $sumArrivals, 'total_wait_min' => 0, 'total_walking_m' => array_sum(array_column($itineraries, 'walking_m')), 'itineraries' => $itineraries, 'car_decisions' => [], 'strategy_type' => 'gbfs_car'];
    }

    protected function cityZone(float $lat, float $lon): ?string
    {
        if ($lat >= 47.60 && $lat <= 47.70 && $lon >= 6.78 && $lon <= 6.92) {
            return 'belfort';
        }
        if ($lat >= 47.42 && $lat <= 47.57 && $lon >= 6.68 && $lon <= 6.92) {
            return 'montbeliard';
        }

        return null;
    }

    /**
     * @param  array{name: string, lat: float, lon: float, has_car?: bool, share_ride?: bool}  $participant
     * @return array<string, mixed>
     */
    protected function findBestReach(
        array $participant,
        int $earliestStartSec,
        int $latestStartSec,
        string $date,
        array $destination,
        ?int $latestArrivalSec
    ): array {
        if ($latestArrivalSec === null) {
            return $this->planner->reach($participant, $latestStartSec, $date, $destination);
        }

        // A full connection scan is expensive. Four one-hour samples provide
        // a practical latest-feasible departure search without 10-minute scans.
        $stepSec = 60 * 60;
        $bestReach = null;

        for ($searchStartSec = $earliestStartSec; $searchStartSec <= $latestStartSec; $searchStartSec += $stepSec) {
            $reach = $this->planner->reach(
                $participant,
                $searchStartSec,
                $date,
                $destination,
                $latestArrivalSec
            );

            if ($reach['earliest_at_destination'] === PHP_INT_MAX) {
                continue;
            }

            if ($bestReach === null || $reach['earliest_at_destination'] > $bestReach['earliest_at_destination']) {
                $bestReach = $reach;
            }
        }

        return $bestReach ?? $this->planner->reach(
            $participant,
            $earliestStartSec,
            $date,
            $destination,
            $latestArrivalSec
        );
    }

    /**
     * Compute transit or direct group travel from a candidate meeting hub to the final destination.
     *
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @param  Collection<string, Stop>  $stops
     * @param  Collection<string, Route>  $routes
     * @return array{leg: array<string, mixed>, arrival_sec: int}|null
     */
    protected function computeGroupLegFromHub(
        CandidatePoint $hub,
        array $destination,
        int $depSec,
        string $date,
        $stops,
        $routes
    ): ?array {
        $destLat = (float) $destination['lat'];
        $destLon = (float) $destination['lon'];
        $hubLat = (float) $hub->lat;
        $hubLon = (float) $hub->lon;

        // 1. If very close, walk together
        $directDist = $this->travelTime->distanceMeters($hubLat, $hubLon, $destLat, $destLon);
        if ($directDist <= self::MAX_GROUP_WALK_METERS) {
            $walkSec = $this->travelTime->durationSeconds($hubLat, $hubLon, $destLat, $destLon, 'walk');
            $arrSec = $depSec + $walkSec;

            return [
                'arrival_sec' => $arrSec,
                'leg' => $this->legBuilder->makeLeg([
                    'mode' => 'walk',
                    'line' => 'Marche de groupe',
                    'from' => ['name' => $hub->name, 'lat' => $hubLat, 'lon' => $hubLon],
                    'to' => ['name' => $destination['name'], 'lat' => $destLat, 'lon' => $destLon],
                    'dep_seconds' => $depSec,
                    'arr_seconds' => $arrSec,
                    'distance_m' => $directDist,
                ]),
            ];
        }

        // 2. Look for transit from nearby stop to destination stop
        $nearStops = [];
        foreach ($stops as $s) {
            $dist = $this->travelTime->distanceMeters($hubLat, $hubLon, $s->lat, $s->lon);
            if ($dist <= self::MAX_GROUP_WALK_METERS) {
                $walkTime = $this->travelTime->durationSeconds($hubLat, $hubLon, $s->lat, $s->lon, 'walk');
                $nearStops[$s->feed_id.':'.$s->stop_id] = $depSec + $walkTime;
            }
        }

        if (! empty($nearStops)) {
            $csa = $this->csa->earliestArrival($nearStops, $date);
            $bestStopArr = PHP_INT_MAX;
            $bestStopObj = null;

            foreach ($csa['arrivals'] as $stopId => $arrTime) {
                if (! isset($stops[$stopId])) {
                    continue;
                }
                $sObj = $stops[$stopId];
                $distToDest = $this->travelTime->distanceMeters($sObj->lat, $sObj->lon, $destLat, $destLon);
                if ($distToDest <= self::MAX_GROUP_WALK_METERS) {
                    $walkToDestSec = $this->travelTime->durationSeconds($sObj->lat, $sObj->lon, $destLat, $destLon, 'walk');
                    $totArr = $arrTime + $walkToDestSec;
                    if ($totArr < $bestStopArr) {
                        $bestStopArr = $totArr;
                        $bestStopObj = $sObj;
                    }
                }
            }

            if ($bestStopArr !== PHP_INT_MAX) {
                return [
                    'arrival_sec' => $bestStopArr,
                    'leg' => $this->legBuilder->makeLeg([
                        'mode' => 'bus',
                        'line' => 'Ligne de transit collective',
                        'from' => ['name' => $hub->name, 'lat' => $hubLat, 'lon' => $hubLon],
                        'to' => ['name' => $destination['name'], 'lat' => $destLat, 'lon' => $destLon],
                        'dep_seconds' => $depSec,
                        'arr_seconds' => $bestStopArr,
                        'distance_m' => $directDist,
                    ]),
                ];
            }
        }

        // 3. Car / Bus shuttle fallback
        $driveSec = $this->travelTime->durationSeconds($hubLat, $hubLon, $destLat, $destLon, 'car');
        $arrSec = $depSec + $driveSec;

        return [
            'arrival_sec' => $arrSec,
            'leg' => $this->legBuilder->makeLeg([
                'mode' => 'car',
                'line' => 'Navette / Covoiturage final',
                'from' => ['name' => $hub->name, 'lat' => $hubLat, 'lon' => $hubLon],
                'to' => ['name' => $destination['name'], 'lat' => $destLat, 'lon' => $destLon],
                'dep_seconds' => $depSec,
                'arr_seconds' => $arrSec,
                'distance_m' => $directDist,
            ]),
        ];
    }
}
