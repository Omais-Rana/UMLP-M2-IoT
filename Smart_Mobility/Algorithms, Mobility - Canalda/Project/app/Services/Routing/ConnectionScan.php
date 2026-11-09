<?php

namespace App\Services\Routing;

use App\Models\Calendar;
use App\Models\CalendarDate;
use App\Models\Stop;
use App\Models\Transfer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConnectionScan
{
    /**
     * Get or build sorted connections for a specific service date.
     *
     * @return iterable<array{
     *     trip_id: string,
     *     route_id: string,
     *     dep_stop: string,
     *     arr_stop: string,
     *     dep_time: int,
     *     arr_time: int,
     *     seq: int
     * }>
     */
    public function getConnectionsForDate(string $date, bool $reverse = false): iterable
    {
        $parsedDate = Carbon::parse($date);
        $dayOfWeek = $parsedDate->dayOfWeekIso; // 1 (Mon) to 7 (Sun)
        $dateStr = $parsedDate->format('Y-m-d');

        // 1. Identify active service IDs
        $calendars = Calendar::where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->get();

        $activeServices = [];
        foreach ($calendars as $cal) {
            $runs = match ($dayOfWeek) {
                1 => $cal->monday,
                2 => $cal->tuesday,
                3 => $cal->wednesday,
                4 => $cal->thursday,
                5 => $cal->friday,
                6 => $cal->saturday,
                7 => $cal->sunday,
                default => false,
            };
            if ($runs) {
                $activeServices[$cal->service_id] = true;
            }
        }

        // Exceptions
        $exceptions = CalendarDate::where('date', $dateStr)->get();
        foreach ($exceptions as $exc) {
            if ($exc->exception_type === 1) {
                $activeServices[$exc->service_id] = true;
            } elseif ($exc->exception_type === 2) {
                unset($activeServices[$exc->service_id]);
            }
        }

        if (empty($activeServices)) {
            return [];
        }

        $serviceIds = array_keys($activeServices);

        // 2. Build adjacent trip connections in SQL, ordered for CSA, and
        // consume them incrementally so PHP never stores the full timetable.
        $query = DB::table('stop_times as dep')
            ->join('stop_times as arr', function ($join): void {
                $join->on('arr.feed_id', '=', 'dep.feed_id')
                    ->on('arr.trip_id', '=', 'dep.trip_id')
                    ->whereRaw('arr.stop_sequence = dep.stop_sequence + 1');
            })
            ->join('trips', function ($join): void {
                $join->on('trips.feed_id', '=', 'dep.feed_id')
                    ->on('trips.trip_id', '=', 'dep.trip_id');
            })
            ->whereIn('trips.service_id', $serviceIds)
            ->select([
                'dep.feed_id',
                'dep.trip_id',
                'trips.route_id',
                'dep.stop_id as dep_stop_id',
                'arr.stop_id as arr_stop_id',
                'dep.departure_seconds as dep_time',
                'arr.arrival_seconds as arr_time',
                'dep.stop_sequence as seq',
            ]);

        if ($reverse) {
            $query->orderByDesc('arr.arrival_seconds');
        } else {
            $query->orderBy('dep.departure_seconds');
        }

        return $query
            ->orderBy('dep.feed_id')
            ->orderBy('dep.trip_id')
            ->orderBy('dep.stop_sequence')
            ->cursor()
            ->map(fn (object $connection): array => [
                'trip_id' => $this->key($connection->feed_id, $connection->trip_id),
                'route_id' => $this->key($connection->feed_id, $connection->route_id),
                'dep_stop' => $this->key($connection->feed_id, $connection->dep_stop_id),
                'arr_stop' => $this->key($connection->feed_id, $connection->arr_stop_id),
                'dep_time' => (int) $connection->dep_time,
                'arr_time' => (int) $connection->arr_time,
                'seq' => (int) $connection->seq,
            ]);
    }

    /**
     * Compute earliest arrival at all stops starting from one or more origins.
     *
     * @param  array<string, int>  $originArrivals  [stop_id => arrival_seconds]
     * @param  string  $date  (Y-m-d)
     * @return array{
     *     arrivals: array<string, int>,
     *     predecessors: array<string, array<string, mixed>>
     * }
     */
    public function earliestArrival(array $originArrivals, string $date): array
    {
        $connections = $this->getConnectionsForDate($date);

        // Load transfers lookup: [from_stop_id => [[to_stop_id, min_transfer_time], ...]]
        $transfersByStop = $this->getTransfersMap();

        /** @var array<string, int> $T Earliest arrival time at each stop */
        $T = [];
        /** @var array<string, string|null> $Ttrip Boarding stop for active trips */
        $Ttrip = [];
        /** @var array<string, array<string, mixed>> $predecessors Rebuilding trace */
        $predecessors = [];

        // Initialize origin stops
        foreach ($this->qualifyStopArrivals($originArrivals) as $stopId => $arrSec) {
            $T[$stopId] = $arrSec;
            $predecessors[$stopId] = [
                'type' => 'origin',
                'stop_id' => $stopId,
                'time' => $arrSec,
            ];

            // Propagate initial transfers
            if (isset($transfersByStop[$stopId])) {
                foreach ($transfersByStop[$stopId] as $tr) {
                    $targetStop = $tr['to_stop_id'];
                    $targetTime = $arrSec + $tr['min_transfer_time'];
                    if (! isset($T[$targetStop]) || $targetTime < $T[$targetStop]) {
                        $T[$targetStop] = $targetTime;
                        $predecessors[$targetStop] = [
                            'type' => 'transfer',
                            'from_stop' => $stopId,
                            'to_stop' => $targetStop,
                            'time' => $targetTime,
                            'duration' => $tr['min_transfer_time'],
                        ];
                    }
                }
            }
        }

        // Connection Scan Algorithm
        foreach ($connections as $c) {
            $depStop = $c['dep_stop'];
            $arrStop = $c['arr_stop'];
            $tripId = $c['trip_id'];
            $depTime = $c['dep_time'];
            $arrTime = $c['arr_time'];

            $isBoarded = isset($Ttrip[$tripId]);

            // Can we board this trip at $depStop?
            if (! $isBoarded && isset($T[$depStop]) && $T[$depStop] <= $depTime) {
                $Ttrip[$tripId] = $depStop;
                $isBoarded = true;
            }

            // If trip is boarded, check if arrival at $arrStop improves
            if ($isBoarded) {
                if (! isset($T[$arrStop]) || $arrTime < $T[$arrStop]) {
                    $T[$arrStop] = $arrTime;
                    $predecessors[$arrStop] = [
                        'type' => 'transit',
                        'trip_id' => $tripId,
                        'route_id' => $c['route_id'],
                        'from_stop' => $depStop,
                        'to_stop' => $arrStop,
                        'dep_time' => $depTime,
                        'arr_time' => $arrTime,
                        'board_stop' => $Ttrip[$tripId],
                    ];

                    // Propagate transfers from $arrStop
                    if (isset($transfersByStop[$arrStop])) {
                        foreach ($transfersByStop[$arrStop] as $tr) {
                            $targetStop = $tr['to_stop_id'];
                            $targetTime = $arrTime + $tr['min_transfer_time'];
                            if (! isset($T[$targetStop]) || $targetTime < $T[$targetStop]) {
                                $T[$targetStop] = $targetTime;
                                $predecessors[$targetStop] = [
                                    'type' => 'transfer',
                                    'from_stop' => $arrStop,
                                    'to_stop' => $targetStop,
                                    'time' => $targetTime,
                                    'duration' => $tr['min_transfer_time'],
                                ];
                            }
                        }
                    }
                }
            }
        }

        return [
            'arrivals' => $T,
            'predecessors' => $predecessors,
        ];
    }

    /**
     * Compute latest departure times to reach a destination by a target arrival time.
     *
     * @param  array<string, int>  $destinationArrivals  [stop_id => latest_arrival_seconds]
     * @param  string  $date  (Y-m-d)
     * @return array<string, int> [stop_id => latest_departure_seconds]
     */
    public function latestDeparture(array $destinationArrivals, string $date): array
    {
        $connections = $this->getConnectionsForDate($date, true);
        $transfersByStop = $this->getTransfersMap();

        /** @var array<string, int> $L Latest departure time at each stop */
        $L = [];
        /** @var array<string, string|null> $Ltrip Alighting stop for active trips */
        $Ltrip = [];

        foreach ($this->qualifyStopArrivals($destinationArrivals) as $stopId => $arrSec) {
            $L[$stopId] = $arrSec;
        }

        foreach ($connections as $c) {
            $depStop = $c['dep_stop'];
            $arrStop = $c['arr_stop'];
            $tripId = $c['trip_id'];
            $depTime = $c['dep_time'];
            $arrTime = $c['arr_time'];

            $canAlight = isset($Ltrip[$tripId]);

            if (! $canAlight && isset($L[$arrStop]) && $arrTime <= $L[$arrStop]) {
                $Ltrip[$tripId] = $arrStop;
                $canAlight = true;
            }

            if ($canAlight) {
                if (! isset($L[$depStop]) || $depTime > $L[$depStop]) {
                    $L[$depStop] = $depTime;

                    // Propagate reverse transfers
                    if (isset($transfersByStop[$depStop])) {
                        foreach ($transfersByStop[$depStop] as $tr) {
                            $fromStop = $tr['to_stop_id'];
                            $earlierTime = $depTime - $tr['min_transfer_time'];
                            if (! isset($L[$fromStop]) || $earlierTime > $L[$fromStop]) {
                                $L[$fromStop] = $earlierTime;
                            }
                        }
                    }
                }
            }
        }

        return $L;
    }

    /**
     * Helper to load transfers map from DB.
     *
     * @return array<string, list<array{to_stop_id: string, min_transfer_time: int}>>
     */
    protected function getTransfersMap(): array
    {
        return Cache::remember('csa_transfers_map', 3600, function () {
            $transfers = Transfer::all(['feed_id', 'from_feed_id', 'from_stop_id', 'to_feed_id', 'to_stop_id', 'min_transfer_time']);
            $map = [];
            foreach ($transfers as $tr) {
                $fromFeedId = $tr->from_feed_id ?: $tr->feed_id;
                $toFeedId = $tr->to_feed_id ?: $tr->feed_id;

                // Legacy generated transfers may predate feed-qualified endpoints.
                // They cannot be mapped safely once multiple feeds share stop IDs.
                if (! $fromFeedId || ! $toFeedId) {
                    continue;
                }

                $fromKey = $this->key($fromFeedId, $tr->from_stop_id);
                $toKey = $this->key($toFeedId, $tr->to_stop_id);
                $map[$fromKey][] = [
                    'to_stop_id' => $toKey,
                    'min_transfer_time' => (int) $tr->min_transfer_time,
                ];
            }

            return $map;
        });
    }

    protected function key(string $feedId, string $recordId): string
    {
        return $feedId.':'.$recordId;
    }

    /**
     * @param  array<string, int>  $arrivals
     * @return array<string, int>
     */
    protected function qualifyStopArrivals(array $arrivals): array
    {
        $qualified = [];
        foreach ($arrivals as $stopId => $arrival) {
            if (str_contains($stopId, ':')) {
                $qualified[$stopId] = $arrival;

                continue;
            }

            foreach (Stop::query()->where('stop_id', $stopId)->pluck('feed_id') as $feedId) {
                $qualified[$this->key($feedId, $stopId)] = $arrival;
            }
        }

        return $qualified;
    }
}
