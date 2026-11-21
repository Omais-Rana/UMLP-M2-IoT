<?php

namespace App\Http\Controllers;

use App\Models\Stop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StopSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lon' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'min:100', 'max:10000'],
        ]);

        $query = Str::lower(trim($validated['q']));
        $searchTerm = Str::ascii($query);
        $stops = Stop::query()
            ->where('location_type', 0)
            ->whereRaw('LOWER(name) LIKE ?', [mb_substr($query, 0, 1).'%'])
            ->limit(500)
            ->get(['feed_id', 'stop_id', 'name', 'lat', 'lon']);

        $stops = $stops->filter(function (Stop $stop) use ($searchTerm): bool {
            $name = Str::ascii(Str::lower($stop->name));

            return str_contains($name, $searchTerm);
        });

        if (isset($validated['lat'], $validated['lon'])) {
            $radius = (int) ($validated['radius_m'] ?? 2000);
            $stops = $stops
                ->filter(fn (Stop $stop): bool => $this->distanceMeters(
                    (float) $validated['lat'],
                    (float) $validated['lon'],
                    (float) $stop->lat,
                    (float) $stop->lon
                ) <= $radius)
                ->values();
        }

        return response()->json([
            'data' => $this->formatStops($stops),
        ]);
    }

    /**
     * @param  Collection<int, Stop>  $stops
     * @return list<array<string, mixed>>
     */
    private function formatStops(Collection $stops): array
    {
        return $stops
            ->sortBy([
                ['name', 'asc'],
                ['feed_id', 'asc'],
            ])
            ->take(20)
            ->map(fn (Stop $stop): array => [
                'feed_id' => $stop->feed_id,
                'stop_id' => $stop->stop_id,
                'name' => $stop->name,
                'lat' => (float) $stop->lat,
                'lon' => (float) $stop->lon,
            ])
            ->values()
            ->all();
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
