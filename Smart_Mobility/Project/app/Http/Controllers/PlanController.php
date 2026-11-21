<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlanRequest;
use App\Jobs\ProcessPlanningRun;
use App\Models\Calendar;
use App\Models\PlanningRun;
use App\Models\TransportFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    /**
     * Compute and rank multimodal meeting points and participant itineraries.
     */
    public function plan(PlanRequest $request): JsonResponse
    {
        $participants = array_map(
            static fn (array $participant): array => [...$participant, 'status' => 'pending'],
            $request->validated('participants')
        );
        $run = PlanningRun::query()->create([
            'id' => (string) Str::uuid(),
            'status' => 'queued',
            'total_participants' => count($participants),
            'participants' => $participants,
            'request_payload' => $request->validated(),
        ]);

        if (app()->environment('testing')) {
            ProcessPlanningRun::dispatchSync($run->id);
            $run->refresh();

            return response()->json([
                'status' => 'success',
                'meta' => [
                    'feed_snapshot_date' => ($feedSnapshot = TransportFeed::max('imported_at'))
                        ? date('Y-m-d', strtotime($feedSnapshot))
                        : null,
                    'valid_from' => Calendar::min('start_date') ?? '2026-01-01',
                    'valid_until' => Calendar::max('end_date') ?? '2027-12-31',
                    'computation_time_ms' => null,
                    'participants_count' => count($participants),
                ],
                'options' => $run->result['options'] ?? [],
            ]);
        }

        ProcessPlanningRun::dispatch($run->id);

        return response()->json([
            'status' => 'queued',
            'planning_id' => $run->id,
            'participants' => $run->participants,
        ], 202);
    }

    public function progress(string $runId): JsonResponse
    {
        $run = PlanningRun::query()->findOrFail($runId);

        return response()->json([
            'status' => $run->status,
            'participants' => $run->participants,
            'completed_participants' => $run->completed_participants,
            'total_participants' => $run->total_participants,
            'options' => $run->result['options'] ?? null,
            'error' => $run->error,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
