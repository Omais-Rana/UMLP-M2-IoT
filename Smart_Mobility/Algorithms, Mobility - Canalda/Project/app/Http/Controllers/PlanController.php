<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlanRequest;
use App\Models\Calendar;
use App\Models\TransportFeed;
use App\Services\Planning\MeetingPointFinder;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    /**
     * Compute and rank multimodal meeting points and participant itineraries.
     */
    public function plan(PlanRequest $request, MeetingPointFinder $finder): JsonResponse
    {
        $startExec = microtime(true);

        $date = $request->validated('date');
        $time = $request->validated('time');
        $timeType = $request->validated('time_type');
        $destination = $request->validated('destination');
        $participants = $request->validated('participants');
        $objective = $request->validated('objective', 'latest_arrival');

        $options = $finder->find(
            $participants,
            $date,
            $time,
            $timeType,
            $destination,
            $objective
        );

        $durationMs = (int) round((microtime(true) - $startExec) * 1000);
        $minDate = Calendar::min('start_date') ?? '2026-01-01';
        $maxDate = Calendar::max('end_date') ?? '2027-12-31';
        $feedSnapshot = TransportFeed::max('imported_at');

        return response()->json([
            'status' => 'success',
            'meta' => [
                'feed_snapshot_date' => $feedSnapshot ? date('Y-m-d', strtotime($feedSnapshot)) : null,
                'valid_from' => $minDate,
                'valid_until' => $maxDate,
                'computation_time_ms' => $durationMs,
                'participants_count' => count($participants),
            ],
            'options' => $options,
        ]);
    }
}
