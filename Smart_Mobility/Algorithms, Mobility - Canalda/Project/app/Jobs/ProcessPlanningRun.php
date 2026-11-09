<?php

namespace App\Jobs;

use App\Models\PlanningRun;
use App\Services\Planning\MeetingPointFinder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessPlanningRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId) {}

    public function handle(MeetingPointFinder $finder): void
    {
        $run = PlanningRun::query()->findOrFail($this->runId);
        $payload = $run->request_payload;
        $participants = $payload['participants'];

        $run->update([
            'status' => 'processing',
            'participants' => array_map(
                static fn (array $participant): array => [...$participant, 'status' => 'processing'],
                $run->participants
            ),
        ]);

        try {
            $options = $finder->find(
                $participants,
                $payload['date'],
                $payload['time'],
                $payload['time_type'],
                $payload['destination'],
                $payload['objective'] ?? 'latest_arrival',
                function (string $participantName, int $completed) use ($run): void {
                    $run->update([
                        'completed_participants' => $completed,
                        'participants' => array_map(
                            static function (array $participant) use ($participantName, $completed): array {
                                if ($participant['name'] === $participantName) {
                                    $participant['status'] = 'completed';
                                } elseif ($completed === 0 && ($participant['status'] ?? '') === 'pending') {
                                    $participant['status'] = 'processing';
                                }

                                return $participant;
                            },
                            $run->participants
                        ),
                    ]);
                }
            );

            $run->update([
                'status' => 'completed',
                'completed_participants' => count($participants),
                'result' => ['options' => $options],
                'participants' => array_map(
                    static fn (array $participant): array => [...$participant, 'status' => 'completed'],
                    $run->participants
                ),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => 'Planning failed: '.$exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        PlanningRun::query()
            ->whereKey($this->runId)
            ->update([
                'status' => 'failed',
                'error' => 'Planning worker stopped: '.($exception?->getMessage() ?? 'execution timed out'),
            ]);
    }
}
