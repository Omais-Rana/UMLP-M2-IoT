<?php

namespace App\Services\Planning;

use App\Services\Routing\LegBuilder;
use App\Services\Routing\TravelTimeProvider;

class CarAssignment
{
    /** In arrive-by mode, aim to arrive this long before the deadline. */
    private const ARRIVAL_BUFFER_SEC = 2 * 60;

    public function __construct(
        protected TravelTimeProvider $travelTime,
        protected LegBuilder $legBuilder,
    ) {}

    /**
     * Build one simple unlimited-capacity carpool for explicitly opted-in passengers.
     *
     * @param list<array{
     *     name: string,
     *     lat: float,
     *     lon: float,
     *     has_car: bool,
     *     share_ride?: bool
     * }> $participants
     * @param  array<string, array<string, mixed>>  $participantReachMap  [pName => reachResult]
     * @param  array{name: string, lat: float, lon: float}  $destination
     * @return array<string, mixed>|null
     */
    public function solve(
        array $participants,
        array $participantReachMap,
        array $destination,
        int $startTimeSec,
        string $timeType = 'depart_at',
        ?int $requestedTimeSec = null
    ): ?array {
        $drivers = array_values(array_filter($participants, fn(array $participant): bool => (bool) ($participant['has_car'] ?? false)));
        $passengersByName = [];
        foreach ($participants as $participant) {
            if (! ($participant['has_car'] ?? false)) {
                $passengersByName[$participant['name']] = $participant;
            }
        }

        $passengers = [];
        foreach ($drivers as $driver) {
            foreach ($driver['pickup_passengers'] ?? [] as $passengerName) {
                if (isset($passengersByName[$passengerName]) && ! in_array($passengerName, array_column($passengers, 'name'), true)) {
                    $passengers[] = $passengersByName[$passengerName];
                }
            }
        }

        // Backward compatibility for requests that only set share_ride.
        foreach ($participants as $participant) {
            if (
                ! ($participant['has_car'] ?? false)
                && ($participant['share_ride'] ?? false)
                && ! in_array($participant['name'], array_column($passengers, 'name'), true)
            ) {
                $passengers[] = $participant;
            }
        }

        if ($drivers === [] || $passengers === []) {
            return null;
        }

        $driver = $drivers[0];

        $currentLat = (float) $driver['lat'];
        $currentLon = (float) $driver['lon'];
        $currentTime = $startTimeSec;
        $rawCarLegs = [];
        $pickupEvents = [];
        $pickupSpots = [];
        $remaining = $passengers;

        while ($remaining !== []) {
            usort($remaining, function (array $a, array $b) use ($currentLat, $currentLon): int {
                $aDistance = $this->travelTime->distanceMeters($currentLat, $currentLon, (float) $a['lat'], (float) $a['lon']);
                $bDistance = $this->travelTime->distanceMeters($currentLat, $currentLon, (float) $b['lat'], (float) $b['lon']);

                return $aDistance <=> $bDistance;
            });
            $passenger = array_shift($remaining);
            $passengerLat = (float) $passenger['lat'];
            $passengerLon = (float) $passenger['lon'];
            $distance = $this->travelTime->distanceMeters($currentLat, $currentLon, $passengerLat, $passengerLon);
            $arrival = $currentTime + $this->travelTime->durationSeconds($currentLat, $currentLon, $passengerLat, $passengerLon, 'car');

            $rawCarLegs[] = [
                'mode' => 'car',
                'line' => 'Covoiturage',
                'from' => ['name' => $driver['name'], 'lat' => $currentLat, 'lon' => $currentLon],
                'to' => ['name' => $passenger['name'], 'lat' => $passengerLat, 'lon' => $passengerLon],
                'dep_seconds' => $currentTime,
                'arr_seconds' => $arrival,
                'distance_m' => $distance,
            ];
            $pickupEvents[] = [
                'who' => $passenger['name'],
                'at' => $passenger['name'],
                'spot_lat' => $passengerLat,
                'spot_lon' => $passengerLon,
                'pickup_time_sec' => $arrival,
                'wait_min' => 0,
                'detour_min' => 0,
            ];
            $pickupSpots[$passenger['name']] = [
                'name' => $passenger['name'],
                'lat' => $passengerLat,
                'lon' => $passengerLon,
                'access_legs' => [],
            ];
            $currentLat = $passengerLat;
            $currentLon = $passengerLon;
            $currentTime = $arrival;
        }

        $destinationDistance = $this->travelTime->distanceMeters($currentLat, $currentLon, (float) $destination['lat'], (float) $destination['lon']);
        $destinationArrival = $currentTime + $this->travelTime->durationSeconds($currentLat, $currentLon, (float) $destination['lat'], (float) $destination['lon'], 'car');
        $rawCarLegs[] = [
            'mode' => 'car',
            'line' => 'Covoiturage',
            'from' => ['name' => $driver['name'], 'lat' => $currentLat, 'lon' => $currentLon],
            'to' => ['name' => $destination['name'], 'lat' => (float) $destination['lat'], 'lon' => (float) $destination['lon']],
            'dep_seconds' => $currentTime,
            'arr_seconds' => $destinationArrival,
            'distance_m' => $destinationDistance,
        ];

        // In arrive-by mode, shift the entire carpool schedule forward so it arrives just
        // before $requestedTimeSec instead of departing at the start of the search window.
        if ($timeType === 'arrive_by' && $requestedTimeSec !== null) {
            $targetArrivalSec = max(0, $requestedTimeSec - self::ARRIVAL_BUFFER_SEC);
            $shiftSec = max(0, $targetArrivalSec - $destinationArrival);

            if ($shiftSec > 0) {
                $startTimeSec += $shiftSec;
                $destinationArrival += $shiftSec;

                foreach ($rawCarLegs as $idx => $leg) {
                    $rawCarLegs[$idx]['dep_seconds'] = (int) $leg['dep_seconds'] + $shiftSec;
                    $rawCarLegs[$idx]['arr_seconds'] = (int) $leg['arr_seconds'] + $shiftSec;
                }

                foreach ($pickupEvents as $idx => $event) {
                    $pickupEvents[$idx]['pickup_time_sec'] = (int) $event['pickup_time_sec'] + $shiftSec;
                }
            }
        }

        $carLegs = array_map(
            fn(array $legParams): array => $this->legBuilder->makeLeg($legParams),
            $rawCarLegs
        );

        $unpickedItineraries = [];
        foreach ($participants as $participant) {
            if (! ($participant['has_car'] ?? false) && ! ($participant['share_ride'] ?? false)) {
                $name = $participant['name'];
                if (isset($participantReachMap[$name]['best_itinerary_to_destination'])) {
                    $unpickedItineraries[$name] = $participantReachMap[$name]['best_itinerary_to_destination'];
                }
            }
        }

        return $this->formatSolution([
            'driver' => $driver,
            'passengers_picked' => array_column($passengers, 'name'),
            'pickup_spots' => $pickupSpots,
            'timeline' => [
                'driver_arrival' => $destinationArrival,
                'total_wait_sec' => 0,
                'pickup_events' => $pickupEvents,
                'car_legs' => $carLegs,
            ],
            'unpicked_itineraries' => $unpickedItineraries,
            'latest_arrival_sec' => $destinationArrival,
        ], $participantReachMap, $destination, $startTimeSec);
    }

    /**
     * @param  list<array<string, mixed>>  $participants
     * @param  array<string, array<string, mixed>>  $participantReachMap
     * @return array{name: string, lat: float, lon: float}|null
     */
    protected function selectMeetingPoint(array $participants, array $participantReachMap): ?array
    {
        $candidates = [];
        foreach ($participantReachMap as $reach) {
            foreach ($reach['candidates_reach'] ?? [] as $candidate) {
                $candidates[$candidate['candidate_id']] = [
                    'id' => $candidate['candidate_id'],
                    'name' => $candidate['name'],
                    'lat' => (float) $candidate['lat'],
                    'lon' => (float) $candidate['lon'],
                ];
            }
        }

        foreach ($candidates as $candidate) {
            $reachable = true;
            foreach ($participants as $participant) {
                if (! isset($participantReachMap[$participant['name']]['candidates_reach'][$candidate['id']])) {
                    $reachable = false;
                    break;
                }
            }
            if ($reachable) {
                return $candidate;
            }
        }

        return $candidates !== [] ? reset($candidates) : null;
    }

    /**
     * Format the raw solution into the output structure defined in Plan.md.
     *
     * @param  array<string, mixed>  $solution
     * @param  array<string, mixed>  $participantReachMap
     * @param  array<string, mixed>  $destination
     * @return array<string, mixed>
     */
    protected function formatSolution(
        array $solution,
        array $participantReachMap,
        array $destination,
        int $startTimeSec
    ): array {
        $driver = $solution['driver'];
        $timeline = $solution['timeline'];
        $pickupEvents = $timeline['pickup_events'];
        $unpicked = $solution['unpicked_itineraries'];

        $itineraries = [];

        // 1. Driver's itinerary
        $driverWalkingM = 0;
        $driverTotalDist = 0;
        foreach ($timeline['car_legs'] as $cl) {
            $driverTotalDist += $cl['distance_m'];
        }

        $itineraries[] = [
            'participant' => $driver['name'],
            'total_duration_min' => max(1, (int) round(($timeline['driver_arrival'] - $startTimeSec) / 60)),
            'arrival_at_destination' => $this->legBuilder->formatSeconds($timeline['driver_arrival']),
            'wait_min' => (int) round($timeline['total_wait_sec'] / 60),
            'transfers' => 0,
            'walking_m' => $driverWalkingM,
            'legs' => $timeline['car_legs'],
        ];

        // 2. Picked passengers' itineraries
        foreach ($solution['pickup_spots'] as $pName => $spot) {
            $pLegs = $spot['access_legs']; // Walk/transit legs to pickup spot
            $matchingEvent = null;
            foreach ($pickupEvents as $pe) {
                if ($pe['who'] === $pName) {
                    $matchingEvent = $pe;
                    break;
                }
            }

            $pickupTime = $matchingEvent['pickup_time_sec'] ?? $startTimeSec;

            // Final leg is car_passenger to destination (no public transport)
            $pLegs[] = $this->legBuilder->makeLeg([
                'mode' => 'car_passenger',
                'line' => "Covoiturage avec {$driver['name']}",
                'from' => ['name' => $spot['name'], 'lat' => $spot['lat'], 'lon' => $spot['lon']],
                'to' => ['name' => $destination['name'], 'lat' => (float) $destination['lat'], 'lon' => (float) $destination['lon']],
                'dep_seconds' => $pickupTime,
                'arr_seconds' => $timeline['driver_arrival'],
                'distance_m' => $this->travelTime->distanceMeters($spot['lat'], $spot['lon'], (float) $destination['lat'], (float) $destination['lon']),
            ]);

            $pWalkingM = 0;
            foreach ($pLegs as $pl) {
                if ($pl['mode'] === 'walk') {
                    $pWalkingM += $pl['distance_m'];
                }
            }

            $itineraries[] = [
                'participant' => $pName,
                'total_duration_min' => max(1, (int) round(($timeline['driver_arrival'] - $startTimeSec) / 60)),
                'arrival_at_destination' => $this->legBuilder->formatSeconds($timeline['driver_arrival']),
                'wait_min' => $matchingEvent['wait_min'] ?? 0,
                'transfers' => 1,
                'walking_m' => $pWalkingM,
                'legs' => $pLegs,
            ];
        }

        // 3. Unpicked passengers traveling directly
        foreach ($unpicked as $pName => $itin) {
            $itineraries[] = $itin;
        }

        $carDecisions = [
            [
                'driver' => $driver['name'],
                'mode' => 'pickup',
                'passengers' => $solution['passengers_picked'],
                'pickup' => $pickupEvents,
            ],
        ];

        return [
            'meeting_points' => [],
            'arrival_at_destination' => $this->legBuilder->formatSeconds($solution['latest_arrival_sec']),
            'arrival_at_destination_sec' => $solution['latest_arrival_sec'],
            'itineraries' => $itineraries,
            'car_decisions' => $carDecisions,
            'strategy_type' => 'car_pickup',
        ];
    }
}
