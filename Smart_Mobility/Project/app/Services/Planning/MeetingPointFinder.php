<?php

namespace App\Services\Planning;

use App\Models\CandidatePoint;
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

        // 1. Compute reachability for all participants. Car owners compute only their
        // direct-to-destination reach (no candidate hubs needed). Arrival-by searches
        // sample departure times so the result is the latest feasible option.
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

        $carpool = $this->resolveCarpoolAssignments($participants);
        $participantsByName = [];
        foreach ($participants as $p) {
            $participantsByName[$p['name']] = $p;
        }

        // Independent non-car participants (those not picked up by any driver)
        $independentNonCarParticipants = array_values(array_filter(
            $participants,
            fn (array $p): bool => ! ($p['has_car'] ?? false) && ! isset($carpool['passenger_driver'][$p['name']])
        ));

        $allStrategies = [];
        $hubStrategies = [];

        // 2. Hub-based Meeting Point Strategies — all participants converge at the meeting point.
        // Drivers (and any passengers they pick up) drive to the meeting point first.
        // Independent non-car participants reach the meeting point via transit/walk.
        // From the meeting point, the entire group travels together to the destination.
        $candidates = $this->dataset->candidates();
        $stops = $this->dataset->stops();
        $routesKeyed = $this->dataset->routes();
        $destLat = (float) $destination['lat'];
        $destLon = (float) $destination['lon'];

        foreach ($candidates as $cand) {
            $candId = $cand->id;
            $candTarget = [
                'name' => $cand->name,
                'lat' => (float) $cand->lat,
                'lon' => (float) $cand->lon,
            ];
            $allCanReach = true;
            $arrivalTimes = [];
            $participantLegsToHub = [];
            $participantWaitMin = [];

            // 2a. Independent non-car participants reach hub via transit/walk
            foreach ($independentNonCarParticipants as $p) {
                $pName = $p['name'];
                $pReach = $reachMap[$pName]['candidates_reach'][$candId] ?? null;

                if ($pReach === null) {
                    $allCanReach = false;
                    break;
                }

                $arrivalTimes[$pName] = $pReach['arrival_time_sec'];
                $participantLegsToHub[$pName] = $pReach['legs'];
                $participantWaitMin[$pName] = 0;
            }

            if (! $allCanReach) {
                continue;
            }

            // 2b. Drivers and their picked up passengers drive to the meeting point
            foreach ($participants as $p) {
                if (! ($p['has_car'] ?? false)) {
                    continue;
                }
                $driverName = $p['name'];
                $pickedPassNames = $carpool['driver_passengers'][$driverName] ?? [];
                $pickedPasses = array_map(fn (string $name): array => $participantsByName[$name], $pickedPassNames);

                $carpoolHub = $this->computeDriverCarpoolTo($p, $pickedPasses, $candTarget, $startTimeSec);

                $participantLegsToHub[$driverName] = $carpoolHub['driver_legs'];
                $arrivalTimes[$driverName] = $carpoolHub['driver_arrival'];
                $participantWaitMin[$driverName] = 0;

                foreach ($pickedPasses as $pass) {
                    $passName = $pass['name'];
                    $participantLegsToHub[$passName] = $carpoolHub['passenger_legs'][$passName];
                    $arrivalTimes[$passName] = $carpoolHub['passenger_arrivals'][$passName];
                    $participantWaitMin[$passName] = $carpoolHub['passenger_wait_min'][$passName];
                }
            }

            // Synchronized group departure time from hub (latest participant arrival).
            $tLeaveHub = max($arrivalTimes);

            // Compute group leg from hub to destination.
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
                $hubWaitSec = max(0, $tLeaveHub - $arrivalTimes[$pName]);
                $hubWaitMin = (int) round($hubWaitSec / 60);
                $pWaitMin = ($participantWaitMin[$pName] ?? 0) + $hubWaitMin;
                $totalWaitMin += $pWaitMin;

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
                    'wait_min' => $pWaitMin,
                    'transfers' => 1,
                    'walking_m' => $pWalkingM,
                    'legs' => $legs,
                ];
            }

            $hubStrategies[] = [
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

        // Keep only the single best hub strategy (earliest group arrival, then least walking).
        if ($hubStrategies !== []) {
            usort($hubStrategies, fn ($a, $b) => $a['arrival_sec'] !== $b['arrival_sec']
                ? $a['arrival_sec'] <=> $b['arrival_sec']
                : $a['total_walking_m'] <=> $b['total_walking_m']);
            $allStrategies[] = $hubStrategies[0];
        }

        // 3. Fallback direct strategy (each participant or carpool goes directly to destination)
        $directGroupArrival = 0;
        $directItins = [];
        $directWaitMin = 0;
        $directWalkingM = 0;
        $directSumArrivals = 0;

        $destTarget = [
            'name' => $destination['name'],
            'lat' => $destLat,
            'lon' => $destLon,
        ];

        // 3a. Independent passengers (transit/walk directly to destination)
        foreach ($independentNonCarParticipants as $p) {
            $pName = $p['name'];
            $itin = $reachMap[$pName]['best_itinerary_to_destination'];
            $pArr = $reachMap[$pName]['earliest_at_destination'];
            $directGroupArrival = max($directGroupArrival, $pArr);
            $directSumArrivals += $pArr;
            $directWaitMin += $itin['wait_min'];
            $directWalkingM += $itin['walking_m'];
            $directItins[] = $itin;
        }

        // 3b. Drivers and their picked up passengers (drive directly to destination)
        foreach ($participants as $p) {
            if (! ($p['has_car'] ?? false)) {
                continue;
            }
            $driverName = $p['name'];
            $pickedPassNames = $carpool['driver_passengers'][$driverName] ?? [];
            $pickedPasses = array_map(fn (string $name): array => $participantsByName[$name], $pickedPassNames);

            $carpoolDirect = $this->computeDriverCarpoolTo($p, $pickedPasses, $destTarget, $startTimeSec);

            $driverArrival = $carpoolDirect['driver_arrival'];
            $directGroupArrival = max($directGroupArrival, $driverArrival);
            $directSumArrivals += $driverArrival;

            $directItins[] = [
                'participant' => $driverName,
                'total_duration_min' => max(1, (int) round(($driverArrival - $startTimeSec) / 60)),
                'arrival_at_destination' => $this->legBuilder->formatSeconds($driverArrival),
                'wait_min' => 0,
                'transfers' => 0,
                'walking_m' => 0,
                'legs' => $carpoolDirect['driver_legs'],
            ];

            foreach ($pickedPasses as $pass) {
                $passName = $pass['name'];
                $passWaitMin = $carpoolDirect['passenger_wait_min'][$passName] ?? 0;
                $directWaitMin += $passWaitMin;
                $directSumArrivals += $driverArrival;

                $directItins[] = [
                    'participant' => $passName,
                    'total_duration_min' => max(1, (int) round(($driverArrival - $startTimeSec) / 60)),
                    'arrival_at_destination' => $this->legBuilder->formatSeconds($driverArrival),
                    'wait_min' => $passWaitMin,
                    'transfers' => 0,
                    'walking_m' => 0,
                    'legs' => $carpoolDirect['passenger_legs'][$passName],
                ];
            }
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

        // 4. Filter strategies that violate the arrive-by deadline.
        if ($latestArrivalSec !== null) {
            $allStrategies = array_values(array_filter(
                $allStrategies,
                fn (array $strategy): bool => $strategy['arrival_sec'] <= $latestArrivalSec
            ));
        }

        // 5. Return exactly 2 options: the best hub/meeting-point strategy (first)
        // and the direct independent-journey strategy (second).
        $hubOption = null;
        $directOption = null;

        foreach ($allStrategies as $strat) {
            if ($directOption === null && $strat['strategy_type'] === 'direct') {
                $directOption = $strat;
            } elseif ($hubOption === null && ($strat['strategy_type'] === 'hub' || ! empty($strat['meeting_points']))) {
                $hubOption = $strat;
            }
            if ($hubOption !== null && $directOption !== null) {
                break;
            }
        }

        $selected = [];
        if ($hubOption !== null) {
            $selected[] = $hubOption;
        }
        if ($directOption !== null) {
            $selected[] = $directOption;
        }

        return $selected;
    }

    /**
     * Map drivers to their assigned passengers.
     *
     * @param  list<array<string, mixed>>  $participants
     * @return array{
     *     driver_passengers: array<string, list<string>>,
     *     passenger_driver: array<string, string>
     * }
     */
    protected function resolveCarpoolAssignments(array $participants): array
    {
        $driverPassengers = [];
        $passengerDriver = [];
        $participantsByName = [];

        foreach ($participants as $p) {
            $participantsByName[$p['name']] = $p;
        }

        // 1. Explicit pickup_passengers on drivers
        foreach ($participants as $p) {
            if (($p['has_car'] ?? false) && ! empty($p['pickup_passengers'])) {
                $driverName = $p['name'];
                foreach ($p['pickup_passengers'] as $passName) {
                    if (isset($participantsByName[$passName]) && ! ($participantsByName[$passName]['has_car'] ?? false) && ! isset($passengerDriver[$passName])) {
                        $driverPassengers[$driverName][] = $passName;
                        $passengerDriver[$passName] = $driverName;
                    }
                }
            }
        }

        // 2. Backward compatibility: legacy share_ride on passengers
        $drivers = array_values(array_filter($participants, fn (array $p): bool => (bool) ($p['has_car'] ?? false)));
        if ($drivers !== []) {
            foreach ($participants as $p) {
                if (! ($p['has_car'] ?? false) && ($p['share_ride'] ?? false)) {
                    $passName = $p['name'];
                    if (! isset($passengerDriver[$passName])) {
                        $driverName = $drivers[0]['name'];
                        $driverPassengers[$driverName][] = $passName;
                        $passengerDriver[$passName] = $driverName;
                    }
                }
            }
        }

        return [
            'driver_passengers' => $driverPassengers,
            'passenger_driver' => $passengerDriver,
        ];
    }

    /**
     * Compute carpool legs for a driver and their assigned passengers to a target location (hub or destination).
     *
     * @param  array<string, mixed>  $driver
     * @param  list<array<string, mixed>>  $passengers
     * @param  array{name: string, lat: float, lon: float}  $target
     * @return array{
     *     driver_legs: list<array<string, mixed>>,
     *     passenger_legs: array<string, list<array<string, mixed>>>,
     *     driver_arrival: int,
     *     passenger_arrivals: array<string, int>,
     *     passenger_wait_min: array<string, int>
     * }
     */
    protected function computeDriverCarpoolTo(
        array $driver,
        array $passengers,
        array $target,
        int $startTimeSec
    ): array {
        $currLat = (float) $driver['lat'];
        $currLon = (float) $driver['lon'];
        $currName = $driver['name'];
        $currTime = $startTimeSec;
        $driverLegs = [];
        $passengerLegs = [];
        $passengerArrivals = [];
        $passengerWaitMin = [];
        $passengerPickupTimes = [];

        foreach ($passengers as $pass) {
            $passLat = (float) $pass['lat'];
            $passLon = (float) $pass['lon'];
            $distToPass = $this->travelTime->distanceMeters($currLat, $currLon, $passLat, $passLon);
            $driveToPassSec = $this->travelTime->durationSeconds($currLat, $currLon, $passLat, $passLon, 'car');
            $passPickupTime = $currTime + $driveToPassSec;

            $driverLegs[] = $this->legBuilder->makeLeg([
                'mode' => 'car',
                'line' => 'Covoiturage',
                'from' => ['name' => $currName, 'lat' => $currLat, 'lon' => $currLon],
                'to' => ['name' => $pass['name'], 'lat' => $passLat, 'lon' => $passLon],
                'dep_seconds' => $currTime,
                'arr_seconds' => $passPickupTime,
                'distance_m' => $distToPass,
            ]);

            $passengerWaitMin[$pass['name']] = max(0, (int) round(($passPickupTime - $startTimeSec) / 60));
            $passengerPickupTimes[$pass['name']] = $passPickupTime;

            $currLat = $passLat;
            $currLon = $passLon;
            $currTime = $passPickupTime;
            $currName = $pass['name'];
        }

        // Final drive from last pickup to target
        $targetLat = (float) $target['lat'];
        $targetLon = (float) $target['lon'];
        $distToTarget = $this->travelTime->distanceMeters($currLat, $currLon, $targetLat, $targetLon);
        $driveToTargetSec = $this->travelTime->durationSeconds($currLat, $currLon, $targetLat, $targetLon, 'car');
        $arrivalAtTarget = $currTime + $driveToTargetSec;

        $driverLegs[] = $this->legBuilder->makeLeg([
            'mode' => 'car',
            'line' => $passengers === [] ? 'Voiture personnelle' : 'Covoiturage',
            'from' => ['name' => $currName, 'lat' => $currLat, 'lon' => $currLon],
            'to' => ['name' => $target['name'], 'lat' => $targetLat, 'lon' => $targetLon],
            'dep_seconds' => $currTime,
            'arr_seconds' => $arrivalAtTarget,
            'distance_m' => $distToTarget,
        ]);

        foreach ($passengers as $pass) {
            $passName = $pass['name'];
            $pPickupTime = $passengerPickupTimes[$passName] ?? $startTimeSec;
            $passLat = (float) $pass['lat'];
            $passLon = (float) $pass['lon'];
            $rideDist = $this->travelTime->distanceMeters($passLat, $passLon, $targetLat, $targetLon);

            $passengerLegs[$passName] = [
                $this->legBuilder->makeLeg([
                    'mode' => 'car_passenger',
                    'line' => "Covoiturage avec {$driver['name']}",
                    'from' => ['name' => $passName, 'lat' => $passLat, 'lon' => $passLon],
                    'to' => ['name' => $target['name'], 'lat' => $targetLat, 'lon' => $targetLon],
                    'dep_seconds' => $pPickupTime,
                    'arr_seconds' => $arrivalAtTarget,
                    'distance_m' => $rideDist,
                ]),
            ];
            $passengerArrivals[$passName] = $arrivalAtTarget;
        }

        return [
            'driver_legs' => $driverLegs,
            'passenger_legs' => $passengerLegs,
            'driver_arrival' => $arrivalAtTarget,
            'passenger_arrivals' => $passengerArrivals,
            'passenger_wait_min' => $passengerWaitMin,
        ];
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
            $bestStopId = null;

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
                        $bestStopId = $stopId;
                    }
                }
            }

            if ($bestStopArr !== PHP_INT_MAX) {
                // Resolve the actual transit line used for the group leg.
                [$lineName, $lineMode, $lineColor] = $this->resolveGroupTransitLine(
                    $bestStopId,
                    $csa['predecessors'] ?? [],
                    $routes
                );

                return [
                    'arrival_sec' => $bestStopArr,
                    'leg' => $this->legBuilder->makeLeg([
                        'mode' => $lineMode,
                        'line' => $lineName,
                        'line_color' => $lineColor,
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

    /**
     * Resolve the real transit line name, mode, and colour for the group leg from a hub
     * by walking the predecessor tree backwards from the alighting stop.
     *
     * @param  array<string, array<string, mixed>>  $predecessors
     * @param  string|null  $stopId  feed-qualified stop id of the alighting stop
     * @param  Collection<string, Route>  $routes
     * @return array{string, string, string|null} [lineName, mode, lineColor]
     */
    protected function resolveGroupTransitLine(
        ?string $stopId,
        array $predecessors,
        $routes
    ): array {
        $curr = $stopId;
        while ($curr !== null && isset($predecessors[$curr])) {
            $pred = $predecessors[$curr];
            if ($pred['type'] === 'transit') {
                $route = $routes->get($pred['route_id'] ?? '');
                $isRail = $route && $route->route_type === 2;
                $lineName = $route ? ($route->short_name ?: $route->long_name) : null;

                return [
                    $lineName ?? ($isRail ? 'Train régional' : 'Bus'),
                    $isRail ? 'train' : 'bus',
                    $route?->route_color,
                ];
            }
            if ($pred['type'] === 'origin') {
                break;
            }
            $curr = $pred['from_stop'] ?? null;
        }

        return ['Bus', 'bus', null];
    }
}
