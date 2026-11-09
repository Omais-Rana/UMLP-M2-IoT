<?php

namespace Tests\Unit;

use App\Services\Planning\CarAssignment;
use App\Services\Routing\HaversineTravelTime;
use App\Services\Routing\LegBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected CarAssignment $carAssignment;

    protected function setUp(): void
    {
        parent::setUp();

        $travelTime = new HaversineTravelTime;
        $legBuilder = new LegBuilder;

        $this->carAssignment = new CarAssignment($travelTime, $legBuilder);
    }

    public function test_driver_picks_up_along_the_way_passenger_with_car_only_final_leg(): void
    {
        // Driver Alice in Montbéliard (47.5100, 6.7980) going to UTBM Belfort (47.6425, 6.8436)
        // Passenger Robin is in Sevenans (47.5878, 6.8665), directly on the way along N19/A36!
        $destination = ['name' => 'UTBM Belfort', 'lat' => 47.6425, 'lon' => 6.8436];
        $startTimeSec = (8 * 3600) + (30 * 60); // 08:30:00
        $date = '2026-10-27';

        $participants = [
            [
                'name' => 'Alice',
                'lat' => 47.5100,
                'lon' => 6.7980,
                'has_car' => true,
            ],
            [
                'name' => 'Robin',
                'lat' => 47.5878,
                'lon' => 6.8665,
                'has_car' => false,
                'share_ride' => true,
            ],
        ];

        $reachMap = $this->reachMap($participants);

        $result = $this->carAssignment->solve($participants, $reachMap, $destination, $startTimeSec);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['car_decisions']);
        $decision = $result['car_decisions'][0];

        // Robin is picked up
        $this->assertContains('Robin', $decision['passengers']);

        // Check Robin's itinerary: last leg must be car_passenger
        $robinItin = null;
        foreach ($result['itineraries'] as $it) {
            if ($it['participant'] === 'Robin') {
                $robinItin = $it;
                break;
            }
        }
        $this->assertNotNull($robinItin);
        $lastLeg = end($robinItin['legs']);
        $this->assertSame('car_passenger', $lastLeg['mode']);
    }

    public function test_non_sharing_passenger_keeps_direct_itinerary(): void
    {
        // Driver in Belfort (47.6334, 6.8542) going to Valdoie (47.6672, 6.8312) - heading North
        // Passenger FarAway is not opted into ride sharing.
        $destination = ['name' => 'Valdoie', 'lat' => 47.6672, 'lon' => 6.8312];
        $startTimeSec = (8 * 3600) + (30 * 60);
        $date = '2026-10-27';

        $participants = [
            [
                'name' => 'DriverBob',
                'lat' => 47.6334,
                'lon' => 6.8542,
                'has_car' => true,
            ],
            [
                'name' => 'FarAwayPassenger',
                'lat' => 47.4625,
                'lon' => 6.8320,
                'has_car' => false,
                'share_ride' => false,
            ],
            [
                'name' => 'SharedPassenger',
                'lat' => 47.6500,
                'lon' => 6.8400,
                'has_car' => false,
                'share_ride' => true,
            ],
        ];

        $reachMap = $this->reachMap($participants);

        $result = $this->carAssignment->solve($participants, $reachMap, $destination, $startTimeSec);

        $this->assertNotNull($result);
        $this->assertSame(['SharedPassenger'], $result['car_decisions'][0]['passengers']);
        $this->assertSame(
            ['DriverBob', 'SharedPassenger', 'FarAwayPassenger'],
            array_column($result['itineraries'], 'participant')
        );
        $this->assertNotContains(
            'car_passenger',
            array_column($result['itineraries'][2]['legs'], 'mode')
        );
    }

    public function test_unlimited_driver_capacity_picks_up_all_opted_in_passengers(): void
    {
        // Unlimited-capacity driver with two eligible passengers along the route.
        $destination = ['name' => 'UTBM Belfort', 'lat' => 47.6425, 'lon' => 6.8436];
        $startTimeSec = (8 * 3600) + (30 * 60);
        $date = '2026-10-27';

        $participants = [
            [
                'name' => 'Driver1Seat',
                'lat' => 47.5100,
                'lon' => 6.7980,
                'has_car' => true,
            ],
            [
                'name' => 'PassengerA',
                'lat' => 47.5878,
                'lon' => 6.8665,
                'has_car' => false,
                'share_ride' => true,
            ],
            [
                'name' => 'PassengerB',
                'lat' => 47.5752,
                'lon' => 6.8645,
                'has_car' => false,
                'share_ride' => true,
            ],
            [
                'name' => 'NonSharingPassenger',
                'lat' => 47.6000,
                'lon' => 6.8600,
                'has_car' => false,
                'share_ride' => false,
            ],
        ];

        $reachMap = $this->reachMap($participants);

        $result = $this->carAssignment->solve($participants, $reachMap, $destination, $startTimeSec);

        $this->assertNotNull($result);
        $decision = $result['car_decisions'][0];

        $this->assertSame(['PassengerA', 'PassengerB'], $decision['passengers']);
        $this->assertSame(
            ['PassengerB', 'PassengerA'],
            array_column($decision['pickup'], 'who')
        );
        $this->assertSame(
            ['Driver1Seat', 'PassengerB', 'PassengerA', 'NonSharingPassenger'],
            array_column($result['itineraries'], 'participant')
        );
        $this->assertCount(4, $result['itineraries']);
        $this->assertSame(
            ['car_passenger'],
            array_column($result['itineraries'][1]['legs'], 'mode')
        );
        $this->assertSame(
            ['car_passenger'],
            array_column($result['itineraries'][2]['legs'], 'mode')
        );
    }

    /**
     * @param  list<array{name: string, lat: float, lon: float, has_car: bool, share_ride?: bool}>  $participants
     * @return array<string, array<string, mixed>>
     */
    protected function reachMap(array $participants): array
    {
        $reachMap = [];
        foreach ($participants as $participant) {
            $directLegs = [
                [
                    'mode' => 'walk',
                    'from' => ['name' => $participant['name'], 'lat' => $participant['lat'], 'lon' => $participant['lon']],
                    'to' => ['name' => 'Transit stop', 'lat' => $participant['lat'], 'lon' => $participant['lon']],
                    'depart' => '08:30',
                    'arrive' => '08:31',
                    'dep_seconds' => 30600,
                    'arr_seconds' => 30660,
                    'duration_min' => 1,
                    'distance_m' => 0,
                ],
                [
                    'mode' => 'transit',
                    'from' => ['name' => 'Transit stop', 'lat' => $participant['lat'], 'lon' => $participant['lon']],
                    'to' => ['name' => 'Destination', 'lat' => 47.64, 'lon' => 6.84],
                    'depart' => '08:31',
                    'arrive' => '09:00',
                    'dep_seconds' => 30660,
                    'arr_seconds' => 32400,
                    'duration_min' => 29,
                    'distance_m' => 1000,
                ],
            ];
            $reachMap[$participant['name']] = [
                'candidates_reach' => [
                    1 => [
                        'candidate_id' => 1,
                        'name' => 'Meeting point',
                        'lat' => 47.60,
                        'lon' => 6.84,
                        'arrival_time_sec' => 32000,
                        'legs' => [],
                    ],
                ],
                'best_itinerary_to_destination' => [
                    'participant' => $participant['name'],
                    'total_duration_min' => 30,
                    'arrival_at_destination' => '09:00',
                    'wait_min' => 0,
                    'transfers' => 1,
                    'walking_m' => 0,
                    'legs' => $directLegs,
                ],
            ];
        }

        return $reachMap;
    }
}
