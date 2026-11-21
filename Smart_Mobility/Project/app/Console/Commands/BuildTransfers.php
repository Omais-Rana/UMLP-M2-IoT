<?php

namespace App\Console\Commands;

use App\Models\Stop;
use App\Models\Transfer;
use App\Services\Routing\TravelTimeProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildTransfers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mobility:build-transfers {--radius= : Walking radius in meters}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate walking transfers between stops closer than the configured radius (cross-feed footpaths)';

    /**
     * Execute the console command.
     */
    public function handle(TravelTimeProvider $travelTime): int
    {
        $radius = (float) ($this->option('radius') ?? config('mobility.walk_transfer_radius_m', 400));
        $bufferSec = (int) (config('mobility.transfer_buffer_min', 3) * 60);

        $this->info("Generating cross-feed walking transfers within {$radius}m (buffer: {$bufferSec}s)...");

        Transfer::where('source', 'generated')->delete();

        $stops = Stop::all(['stop_id', 'feed_id', 'lat', 'lon', 'name']);
        $count = $stops->count();
        $transfersCreated = 0;
        $batch = [];

        for ($i = 0; $i < $count; $i++) {
            $s1 = $stops[$i];
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }
                $s2 = $stops[$j];

                $distMeters = $travelTime->distanceMeters($s1->lat, $s1->lon, $s2->lat, $s2->lon);

                if ($distMeters <= $radius) {
                    $walkSec = $travelTime->durationSeconds($s1->lat, $s1->lon, $s2->lat, $s2->lon, 'walk');
                    $totalTimeSec = $walkSec + $bufferSec;

                    $batch[] = [
                        'feed_id' => null,
                        'from_feed_id' => $s1->feed_id,
                        'from_stop_id' => $s1->stop_id,
                        'to_feed_id' => $s2->feed_id,
                        'to_stop_id' => $s2->stop_id,
                        'transfer_type' => 2,
                        'source' => 'generated',
                        'min_transfer_time' => $totalTimeSec,
                        'distance_m' => round($distMeters, 2),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $transfersCreated++;

                    if (count($batch) >= 500) {
                        DB::table('transfers')->insert($batch);
                        $batch = [];
                    }
                }
            }
        }

        if (! empty($batch)) {
            DB::table('transfers')->insert($batch);
        }

        $this->info("Generated {$transfersCreated} transfer links in 'transfers' table.");

        return Command::SUCCESS;
    }
}
