<?php

namespace App\Services\Planning;

use App\Models\CandidatePoint;
use App\Models\Route;
use App\Models\Stop;
use App\Services\Routing\ConnectionScan;
use App\Services\Routing\LegBuilder;
use App\Services\Routing\TravelTimeProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MeetingPointFinder
{
    private const MAX_GROUP_WALK_METERS = 500.0;

    /** Number of hub (meeting point) options returned, in addition to the direct option. */
    private const HUB_OPTIONS = 2;

    /** Two hub options closer than this (detour-adjusted meters) are not both offered. */
    private const MIN_HUB_SEPARATION_M = 1000.0;

    /** In arrive-by mode, aim to arrive this long before the deadline. */
    private const ARRIVAL_BUFFER_SEC = 2 * 60;

    /** Connection scans for the group leg only look this far after the hub departure. */
    private const GROUP_LEG_SEARCH_WINDOW_SEC = 5400;

    /** @var array<string, array<string, array{distance: float, walk_sec: int}>> */
    protected array $nearbyStopsCache = [];

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
     * Returns up to HUB_OPTIONS hub strategies (best first) followed by the
     * direct independent-journey strategy.
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
     * @return list<array<string, mixed>>
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
        $t0 = microtime(true);
        $timeParts = explode(':', $time);
        $requestedTimeSec = ((int) ($timeParts[0] ?? 8) * 3600) + ((int) ($timeParts[1] ?? 30) * 60);
        $startTimeSec = $timeType === 'arrive_by'
            ? max(0, $requestedTimeSec - (3 * 3600))
            : $requestedTimeSec;
        $latestArrivalSec = $timeType === 'arrive_by' ? $requestedTimeSec : null;
        $deadlineTarget = $latestArrivalSec !== null
            ? max(0, $latestArrivalSec - self::ARRIVAL_BUFFER_SEC)
            : null;

        $carpool = $this->resolveCarpoolAssignments($participants);
        $participantsByName = [];
        foreach ($participants as $p) {
            $participantsByName[$p['name']] = $p;
        }

        // Independent non-car participants (those not picked up by any driver).
        $independentNonCarParticipants = array_values(array_filter(
            $participants,
            fn(array $p): bool => ! ($p['has_car'] ?? false) && ! isset($carpool['passenger_driver'][$p['name']])
        ));
        $independentNames = array_column($independentNonCarParticipants, 'name');

        $hasDrivers = false;
        foreach ($participants as $p) {
            if ($p['has_car'] ?? false) {
                $hasDrivers = true;
                break;
            }
        }

        // 1. Reachability. Only independent riders need a transit search: drivers and
        // picked-up passengers are routed arithmetically (computeDriverCarpoolTo).
        $reachMap = [];
        $completed = 0;
        foreach ($participants as $p) {
            if (in_array($p['name'], $independentNames, true)) {
                if ($deadlineTarget !== null) {
                    $earliestReach = $this->planner->reach(
                        $p,
                        $startTimeSec,
                        $date,
                        $destination,
                        $latestArrivalSec,
                        true
                    );

                    $directReach = $this->planner->latestReach(
                        $p,
                        $deadlineTarget,
                        $startTimeSec,
                        $date,
                        $destination,
                        $latestArrivalSec,
                        false,
                        $earliestReach
                    );
                    $directReach['earliest_candidates_reach'] = $earliestReach['candidates_reach'];
                    $reachMap[$p['name']] = $directReach;
                } else {
                    $reachMap[$p['name']] = $this->planner->reach(
                        $p,
                        $startTimeSec,
                        $date,
                        $destination
                    );
                }
            }
            $completed++;
            if ($onParticipantCompleted !== null) {
                $onParticipantCompleted($p['name'], $completed);
            }
        }

        Log::info('MeetingPointFinder riders done', ['elapsed_s' => round(microtime(true) - $t0, 2)]);

        $allStrategies = [];
        $candidates = $this->dataset->candidates();
        $stops = $this->dataset->stops();
        $routesKeyed = $this->dataset->routes();
        $destLat = (float) $destination['lat'];
        $destLon = (float) $destination['lon'];
        $destNearbyStops = $this->getNearbyStops($destLat, $destLon, self::MAX_GROUP_WALK_METERS);

        // Precompute the destination reverse-CSA stop table ONCE for all candidate hubs.
        $precomputedDestLatestByStop = [];
        if ($deadlineTarget !== null && $latestArrivalSec !== null && $destNearbyStops !== []) {
            $targets = $latestArrivalSec > $deadlineTarget
                ? [$deadlineTarget, $latestArrivalSec]
                : [$deadlineTarget];

            foreach ($targets as $targetArrivalSec) {
                $destStopArrivals = [];
                foreach ($destNearbyStops as $stopId => $near) {
                    $destStopArrivals[$stopId] = $targetArrivalSec - $near['walk_sec'];
                }

                $precomputedDestLatestByStop[$targetArrivalSec] = $this->csa->latestDeparture(
                    $destStopArrivals,
                    $date,
                    $startTimeSec
                );
            }
        }

        $why = ['candidates' => 0, 'unreachable' => 0, 'no_group_leg' => 0, 'deadline' => 0, 'ok' => 0];
        $preliminaryHubs = [];

        // 2. Evaluate all candidate hubs in O(1) without running Forward CSA per hub.
        foreach ($candidates as $cand) {
            $why['candidates']++;
            $candId = $cand->id;

            // Prune immediately if any independent rider cannot reach this hub.
            $anyUnreachable = false;
            foreach ($independentNonCarParticipants as $p) {
                $pName = $p['name'];
                $reach = $deadlineTarget !== null
                    ? ($reachMap[$pName]['earliest_candidates_reach'][$candId] ?? null)
                    : ($reachMap[$pName]['candidates_reach'][$candId] ?? null);
                if ($reach === null) {
                    $anyUnreachable = true;
                    break;
                }
            }
            if ($anyUnreachable) {
                $why['unreachable']++;
                continue;
            }

            $candTarget = [
                'name' => $cand->name,
                'lat' => (float) $cand->lat,
                'lon' => (float) $cand->lon,
            ];

            // 2a. Arrive-by: resolve latest group departure and expected destination arrival in O(1).
            $groupDepSec = null;
            $expectedDestArrivalSec = null;

            if ($deadlineTarget !== null && $latestArrivalSec !== null) {
                $resolved = $this->resolveLatestGroupDepartureFromHub(
                    $cand,
                    $destination,
                    $deadlineTarget,
                    $latestArrivalSec,
                    $precomputedDestLatestByStop,
                    $destNearbyStops
                );
                if ($resolved === null) {
                    $why['no_group_leg']++;
                    continue;
                }
                [$groupDepSec, $expectedDestArrivalSec] = $resolved;
            }

            $arrivalTimes = [];
            $participantLegsToHub = [];
            $participantWaitMin = [];
            $departSec = [];
            $allCanReach = true;

            // 2b. Independent non-car participants reach the hub via already-computed Step 1 reachability.
            foreach ($independentNonCarParticipants as $p) {
                $pName = $p['name'];

                if ($groupDepSec !== null) {
                    $earliestCandReach = $reachMap[$pName]['earliest_candidates_reach'][$candId] ?? null;
                    if ($earliestCandReach === null || $earliestCandReach['arrival_time_sec'] > $groupDepSec) {
                        $allCanReach = false;
                        break;
                    }

                    $arrivalTimes[$pName] = $earliestCandReach['arrival_time_sec'];
                    $participantLegsToHub[$pName] = $earliestCandReach['legs'];
                    $participantWaitMin[$pName] = 0;
                    $departSec[$pName] = $earliestCandReach['start_time_sec'];
                } else {
                    $pReach = $reachMap[$pName]['candidates_reach'][$candId] ?? null;
                    if ($pReach === null) {
                        $allCanReach = false;
                        break;
                    }

                    $arrivalTimes[$pName] = $pReach['arrival_time_sec'];
                    $participantLegsToHub[$pName] = $pReach['legs'];
                    $participantWaitMin[$pName] = 0;
                    $departSec[$pName] = $pReach['start_time_sec'] ?? $reachMap[$pName]['start_time_sec'];
                }
            }

            if (! $allCanReach) {
                $why['unreachable']++;
                continue;
            }

            // 2c. Drivers (and their passengers) drive to the hub.
            foreach ($participants as $p) {
                if (! ($p['has_car'] ?? false)) {
                    continue;
                }
                $driverName = $p['name'];
                $pickedPasses = array_map(
                    fn(string $name): array => $participantsByName[$name],
                    $carpool['driver_passengers'][$driverName] ?? []
                );

                $carStart = $startTimeSec;
                $carpoolHub = $this->computeDriverCarpoolTo($p, $pickedPasses, $candTarget, $carStart);

                if ($groupDepSec !== null) {
                    $carStart = max(0, $carStart + ($groupDepSec - $carpoolHub['driver_arrival']));
                    $carpoolHub = $this->computeDriverCarpoolTo($p, $pickedPasses, $candTarget, $carStart);
                }

                $participantLegsToHub[$driverName] = $carpoolHub['driver_legs'];
                $arrivalTimes[$driverName] = $carpoolHub['driver_arrival'];
                $participantWaitMin[$driverName] = 0;
                $departSec[$driverName] = $carStart;

                foreach ($pickedPasses as $pass) {
                    $passName = $pass['name'];
                    $participantLegsToHub[$passName] = $carpoolHub['passenger_legs'][$passName];
                    $arrivalTimes[$passName] = $carpoolHub['passenger_arrivals'][$passName];
                    $participantWaitMin[$passName] = $carpoolHub['passenger_wait_min'][$passName];
                    $departSec[$passName] = $carStart + $carpoolHub['passenger_wait_min'][$passName] * 60;
                }
            }

            if ($arrivalTimes === []) {
                continue;
            }

            $latestAtHub = max($arrivalTimes);
            $tLeaveHub = $groupDepSec !== null ? max($groupDepSec, $latestAtHub) : $latestAtHub;

            // Lower-bound destination arrival for sorting before building the actual final leg.
            $directDist = $this->travelTime->distanceMeters((float) $cand->lat, (float) $cand->lon, $destLat, $destLon);
            $minLegSec = $directDist <= self::MAX_GROUP_WALK_METERS
                ? $this->travelTime->durationSeconds((float) $cand->lat, (float) $cand->lon, $destLat, $destLon, 'walk')
                : $this->travelTime->durationSeconds((float) $cand->lat, (float) $cand->lon, $destLat, $destLon, 'car');

            $estArrivalSec = ($expectedDestArrivalSec !== null && $tLeaveHub === $groupDepSec)
                ? $expectedDestArrivalSec
                : ($tLeaveHub + $minLegSec);

            if ($latestArrivalSec !== null && $estArrivalSec > $latestArrivalSec) {
                $why['deadline']++;
                continue;
            }

            $preliminaryHubs[] = [
                'cand' => $cand,
                't_leave_hub' => $tLeaveHub,
                'est_arrival_sec' => $estArrivalSec,
                'centrality_m' => $this->candidateCentralityMeters($participants, $cand),
                'arrival_times' => $arrivalTimes,
                'legs_to_hub' => $participantLegsToHub,
                'wait_min' => $participantWaitMin,
                'depart_sec' => $departSec,
            ];
        }

        // Sort preliminary hubs so the best candidates are finalized first,
        // avoiding Forward CSA calls on dozens of suboptimal hubs.
        if ($preliminaryHubs !== []) {
            $earliestEstArrival = min(array_column($preliminaryHubs, 'est_arrival_sec'));
            $arrivalToleranceSec = 10 * 60;

            $practicalPrelim = array_values(array_filter(
                $preliminaryHubs,
                fn(array $h): bool => $h['est_arrival_sec'] <= $earliestEstArrival + $arrivalToleranceSec
            ));
            $restPrelim = array_values(array_filter(
                $preliminaryHubs,
                fn(array $h): bool => $h['est_arrival_sec'] > $earliestEstArrival + $arrivalToleranceSec
            ));

            usort(
                $practicalPrelim,
                fn(array $a, array $b): int => $a['centrality_m'] <=> $b['centrality_m']
                    ?: $a['est_arrival_sec'] <=> $b['est_arrival_sec']
            );
            usort(
                $restPrelim,
                fn(array $a, array $b): int => $a['est_arrival_sec'] <=> $b['est_arrival_sec']
                    ?: $a['centrality_m'] <=> $b['centrality_m']
            );

            $orderedPrelim = [...$practicalPrelim, ...$restPrelim];
            $hubStrategies = [];
            $maxToEvaluate = $deadlineTarget !== null ? (self::HUB_OPTIONS + 2) : (self::HUB_OPTIONS + 4);
            $evaluated = 0;

            foreach ($orderedPrelim as $item) {
                /** @var CandidatePoint $cand */
                $cand = $item['cand'];

                // Skip early if already too close to a finalized hub.
                $tooClose = false;
                foreach ($hubStrategies as $picked) {
                    $pmp = $picked['meeting_points'][0];
                    $separation = $this->travelTime->distanceMeters(
                        (float) $cand->lat,
                        (float) $cand->lon,
                        (float) $pmp['lat'],
                        (float) $pmp['lon']
                    );
                    if ($separation < self::MIN_HUB_SEPARATION_M) {
                        $tooClose = true;
                        break;
                    }
                }
                if ($tooClose) {
                    continue;
                }

                $tLeaveHub = $item['t_leave_hub'];
                $groupLeg = $this->computeGroupLegFromHub(
                    $cand,
                    $destination,
                    $tLeaveHub,
                    $date,
                    $stops,
                    $routesKeyed,
                    $destNearbyStops
                );
                $evaluated++;

                if ($groupLeg === null) {
                    $why['no_group_leg']++;
                    continue;
                }

                $finalArrivalSec = $groupLeg['arrival_sec'];
                if ($latestArrivalSec !== null && $finalArrivalSec > $latestArrivalSec) {
                    $why['deadline']++;
                    continue;
                }

                $why['ok']++;

                $itineraries = [];
                $totalWaitMin = 0;
                $totalWalkingM = 0;
                $sumArrivals = 0;

                foreach ($participants as $p) {
                    $pName = $p['name'];
                    $hubWaitSec = max(0, $tLeaveHub - $item['arrival_times'][$pName]);
                    $hubWaitMin = (int) round($hubWaitSec / 60);
                    $pWaitMin = ($item['wait_min'][$pName] ?? 0) + $hubWaitMin;
                    $totalWaitMin += $pWaitMin;

                    $legs = $item['legs_to_hub'][$pName];
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
                        'total_duration_min' => max(1, (int) round(($finalArrivalSec - ($item['depart_sec'][$pName] ?? $startTimeSec)) / 60)),
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
                    'centrality_m' => $item['centrality_m'],
                    'itineraries' => $itineraries,
                    'car_decisions' => [],
                    'strategy_type' => 'hub',
                    '_candidate' => $cand,
                    '_t_leave_hub' => $tLeaveHub,
                    '_group_leg' => $groupLeg['leg'],
                ];

                if (count($hubStrategies) >= self::HUB_OPTIONS && $evaluated >= $maxToEvaluate) {
                    break;
                }
            }

            // Final re-sort in case exact transit leg times shifted relative order slightly.
            if ($hubStrategies !== []) {
                $earliestHubArrival = min(array_column($hubStrategies, 'arrival_sec'));
                $practical = array_values(array_filter(
                    $hubStrategies,
                    fn(array $s): bool => $s['arrival_sec'] <= $earliestHubArrival + $arrivalToleranceSec
                ));
                $rest = array_values(array_filter(
                    $hubStrategies,
                    fn(array $s): bool => $s['arrival_sec'] > $earliestHubArrival + $arrivalToleranceSec
                ));

                usort(
                    $practical,
                    fn(array $a, array $b): int => $a['centrality_m'] <=> $b['centrality_m']
                        ?: $a['arrival_sec'] <=> $b['arrival_sec']
                        ?: $a['total_wait_min'] <=> $b['total_wait_min']
                        ?: $a['total_walking_m'] <=> $b['total_walking_m']
                );
                usort(
                    $rest,
                    fn(array $a, array $b): int => $a['arrival_sec'] <=> $b['arrival_sec']
                        ?: $a['centrality_m'] <=> $b['centrality_m']
                );

                $pickedHubs = array_slice([...$practical, ...$rest], 0, self::HUB_OPTIONS);

                if ($deadlineTarget !== null && $independentNonCarParticipants !== []) {
                    foreach ($pickedHubs as $idx => $strategy) {
                        $pickedHubs[$idx] = $this->refinePickedHubForArriveBy(
                            $strategy,
                            $independentNonCarParticipants,
                            $reachMap,
                            $startTimeSec,
                            $date
                        );
                    }
                }

                foreach ($pickedHubs as $idx => $strategy) {
                    unset($pickedHubs[$idx]['_candidate'], $pickedHubs[$idx]['_t_leave_hub'], $pickedHubs[$idx]['_group_leg']);
                }

                array_push($allStrategies, ...$pickedHubs);
            }
        }

        Log::info('MeetingPointFinder hub filter', $why + [
            'time_type' => $timeType,
            'requested' => $this->legBuilder->formatSeconds($requestedTimeSec),
            'start_window' => $this->legBuilder->formatSeconds($startTimeSec),
            'has_drivers' => $hasDrivers,
            'independent_riders' => $independentNames,
            'elapsed_s' => round(microtime(true) - $t0, 2),
        ]);

        // 3. Direct strategy (each participant or carpool goes directly to destination)
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
            $pickedPasses = array_map(fn(string $name): array => $participantsByName[$name], $pickedPassNames);

            $carStart = $startTimeSec;
            $carpoolDirect = $this->computeDriverCarpoolTo($p, $pickedPasses, $destTarget, $carStart);

            // In arrive-by mode, leave as late as possible instead of at the start of the window.
            if ($deadlineTarget !== null) {
                $carStart = max(0, $carStart + ($deadlineTarget - $carpoolDirect['driver_arrival']));
                $carpoolDirect = $this->computeDriverCarpoolTo($p, $pickedPasses, $destTarget, $carStart);
            }

            $driverArrival = $carpoolDirect['driver_arrival'];
            $directGroupArrival = max($directGroupArrival, $driverArrival);
            $directSumArrivals += $driverArrival;

            $directItins[] = [
                'participant' => $driverName,
                'total_duration_min' => max(1, (int) round(($driverArrival - $carStart) / 60)),
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
                    'total_duration_min' => max(1, (int) round(($driverArrival - $carStart) / 60)),
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
                fn(array $strategy): bool => $strategy['arrival_sec'] <= $latestArrivalSec
            ));
        }

        // 5. Return up to HUB_OPTIONS hub options (best first) followed by the direct option.
        $hubOptions = [];
        $directOption = null;

        foreach ($allStrategies as $strat) {
            if ($strat['strategy_type'] === 'direct') {
                $directOption ??= $strat;
            } elseif (count($hubOptions) < self::HUB_OPTIONS) {
                $hubOptions[] = $strat;
            }
        }

        Log::info('MeetingPointFinder total done', ['elapsed_s' => round(microtime(true) - $t0, 2)]);

        return $directOption !== null
            ? [...$hubOptions, $directOption]
            : $hubOptions;
    }

    /**
     * Shift independent riders' home departures as late as possible for a selected hub in arrive-by mode.
     *
     * @param  array<string, mixed>  $strategy
     * @param  list<array<string, mixed>>  $independentNonCarParticipants
     * @param  array<string, array<string, mixed>>  $reachMap
     * @return array<string, mixed>
     */
    protected function refinePickedHubForArriveBy(
        array $strategy,
        array $independentNonCarParticipants,
        array $reachMap,
        int $startTimeSec,
        string $date
    ): array {
        /** @var CandidatePoint $cand */
        $cand = $strategy['_candidate'];
        $tLeaveHub = (int) $strategy['_t_leave_hub'];
        $groupLeg = $strategy['_group_leg'];
        $finalArrivalSec = (int) $strategy['arrival_sec'];
        $candTarget = [
            'name' => $cand->name,
            'lat' => (float) $cand->lat,
            'lon' => (float) $cand->lon,
        ];

        $refinedByParticipant = [];
        foreach ($independentNonCarParticipants as $p) {
            $pName = $p['name'];
            $earliestCandReach = $reachMap[$pName]['earliest_candidates_reach'][$cand->id] ?? null;
            if ($earliestCandReach === null) {
                continue;
            }

            // Skip reverse scan if the rider already arrives within 3 minutes of group departure.
            if (($tLeaveHub - $earliestCandReach['arrival_time_sec']) <= 180) {
                continue;
            }

            $candFallbackReach = [
                'participant' => $pName,
                'start_time_sec' => $earliestCandReach['start_time_sec'],
                'earliest_at_destination' => $earliestCandReach['arrival_time_sec'],
                'best_itinerary_to_destination' => [
                    'participant' => $pName,
                    'legs' => $earliestCandReach['legs'],
                    'total_duration_min' => max(1, (int) round(($earliestCandReach['arrival_time_sec'] - $earliestCandReach['start_time_sec']) / 60)),
                    'arrival_at_destination' => $this->legBuilder->formatSeconds($earliestCandReach['arrival_time_sec']),
                    'walking_m' => 0,
                    'transfers' => 1,
                    'wait_min' => 0,
                ],
                'candidates_reach' => [],
            ];

            $pReach = $this->planner->latestReach(
                $p,
                $tLeaveHub,
                $startTimeSec,
                $date,
                $candTarget,
                $tLeaveHub,
                false,
                $candFallbackReach
            );

            if ($pReach['earliest_at_destination'] !== PHP_INT_MAX && $pReach['earliest_at_destination'] <= $tLeaveHub) {
                $legs = $pReach['best_itinerary_to_destination']['legs'];
                $legs[] = $groupLeg;

                $pWalkingM = 0;
                foreach ($legs as $l) {
                    if ($l['mode'] === 'walk') {
                        $pWalkingM += $l['distance_m'];
                    }
                }

                $hubWaitMin = (int) round(max(0, $tLeaveHub - $pReach['earliest_at_destination']) / 60);
                $refinedByParticipant[$pName] = [
                    'participant' => $pName,
                    'total_duration_min' => max(1, (int) round(($finalArrivalSec - $pReach['start_time_sec']) / 60)),
                    'arrival_at_destination' => $this->legBuilder->formatSeconds($finalArrivalSec),
                    'wait_min' => $hubWaitMin,
                    'transfers' => 1,
                    'walking_m' => $pWalkingM,
                    'legs' => $legs,
                ];
            }
        }

        if ($refinedByParticipant === []) {
            return $strategy;
        }

        $totalWaitMin = 0;
        $totalWalkingM = 0;
        foreach ($strategy['itineraries'] as $idx => $itin) {
            $pName = $itin['participant'];
            if (isset($refinedByParticipant[$pName])) {
                $strategy['itineraries'][$idx] = $refinedByParticipant[$pName];
            }
            $totalWaitMin += $strategy['itineraries'][$idx]['wait_min'];
            $totalWalkingM += $strategy['itineraries'][$idx]['walking_m'];
        }

        $strategy['total_wait_min'] = $totalWaitMin;
        $strategy['total_walking_m'] = $totalWalkingM;

        return $strategy;
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
        $drivers = array_values(array_filter($participants, fn(array $p): bool => (bool) ($p['has_car'] ?? false)));
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
     * Resolve [latestGroupDepartureSec, expectedDestinationArrivalSec] in O(1) using the precomputed reverse-CSA table.
     *
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @param  array<int, array<string, int>>  $precomputedDestLatestByStop
     * @param  array<string, array{distance: float, walk_sec: int}>  $destNearbyStops
     * @return array{0: int, 1: int}|null
     */
    protected function resolveLatestGroupDepartureFromHub(
        CandidatePoint $hub,
        array $destination,
        int $deadlineTarget,
        int $latestArrivalSec,
        array $precomputedDestLatestByStop,
        array $destNearbyStops
    ): ?array {
        $targets = [$deadlineTarget];
        if ($latestArrivalSec > $deadlineTarget) {
            $targets[] = $latestArrivalSec;
        }

        $destLat = (float) $destination['lat'];
        $destLon = (float) $destination['lon'];
        $hubLat = (float) $hub->lat;
        $hubLon = (float) $hub->lon;

        $hubNearbyStops = $this->getNearbyStops($hubLat, $hubLon, self::MAX_GROUP_WALK_METERS);

        foreach ($targets as $targetArrivalSec) {
            // 1. Direct walk if within walking distance
            $directDist = $this->travelTime->distanceMeters($hubLat, $hubLon, $destLat, $destLon);
            if ($directDist <= self::MAX_GROUP_WALK_METERS) {
                $walkSec = $this->travelTime->durationSeconds($hubLat, $hubLon, $destLat, $destLon, 'walk');
                $depSec = $targetArrivalSec - $walkSec;
                if ($depSec >= 0) {
                    return [$depSec, $targetArrivalSec];
                }
            }

            // 2. Transit via precomputed reverse-CSA stop table
            $latestByStop = $precomputedDestLatestByStop[$targetArrivalSec] ?? [];
            if ($hubNearbyStops !== [] && $destNearbyStops !== [] && $latestByStop !== []) {
                $bestDep = null;
                foreach ($hubNearbyStops as $stopId => $near) {
                    if (isset($latestByStop[$stopId])) {
                        $dep = $latestByStop[$stopId] - $near['walk_sec'];
                        if ($dep >= 0 && ($bestDep === null || $dep > $bestDep)) {
                            $bestDep = $dep;
                        }
                    }
                }
                if ($bestDep !== null) {
                    return [$bestDep, $targetArrivalSec];
                }
            }

            // 3. Car / Bus shuttle fallback
            $driveSec = $this->travelTime->durationSeconds($hubLat, $hubLon, $destLat, $destLon, 'car');
            $depSec = $targetArrivalSec - $driveSec;
            if ($depSec >= 0) {
                return [$depSec, $targetArrivalSec];
            }
        }

        return null;
    }

    /**
     * Measure how close a candidate hub is to the whole participant group.
     */
    protected function candidateCentralityMeters(array $participants, CandidatePoint $candidate): float
    {
        $totalDistance = 0.0;

        foreach ($participants as $participant) {
            $totalDistance += $this->travelTime->distanceMeters(
                (float) $participant['lat'],
                (float) $participant['lon'],
                (float) $candidate->lat,
                (float) $candidate->lon
            );
        }

        return $totalDistance;
    }

    /**
     * Compute transit or direct group travel from a candidate meeting hub to the final destination.
     *
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @param  Collection<string, Stop>  $stops
     * @param  Collection<string, Route>  $routes
     * @param  array<string, array{distance: float, walk_sec: int}>|null  $destNearbyStops
     * @return array{leg: array<string, mixed>, arrival_sec: int}|null
     */
    protected function computeGroupLegFromHub(
        CandidatePoint $hub,
        array $destination,
        int $depSec,
        string $date,
        $stops,
        $routes,
        ?array $destNearbyStops = null
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
        $hubNearbyStops = $this->getNearbyStops($hubLat, $hubLon, self::MAX_GROUP_WALK_METERS);
        $destNearbyStops ??= $this->getNearbyStops($destLat, $destLon, self::MAX_GROUP_WALK_METERS);

        if ($hubNearbyStops !== [] && $destNearbyStops !== []) {
            $nearStops = [];
            foreach ($hubNearbyStops as $stopId => $near) {
                $nearStops[$stopId] = $depSec + $near['walk_sec'];
            }

            $csa = $this->csa->earliestArrival($nearStops, $date, $depSec + self::GROUP_LEG_SEARCH_WINDOW_SEC);
            $bestStopArr = PHP_INT_MAX;
            $bestStopId = null;

            foreach ($destNearbyStops as $stopId => $nearDest) {
                if (! isset($csa['arrivals'][$stopId])) {
                    continue;
                }
                $totArr = $csa['arrivals'][$stopId] + $nearDest['walk_sec'];
                if ($totArr < $bestStopArr) {
                    $bestStopArr = $totArr;
                    $bestStopId = $stopId;
                }
            }

            if ($bestStopArr !== PHP_INT_MAX) {
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
