<?php

namespace App\Services\Routing;

class LegBuilder
{
    /**
     * Build a formatted leg item.
     *
     * @param  array<string, mixed>  $params
     * @return array{
     *     mode: string,
     *     line: string|null,
     *     line_color: string|null,
     *     trip_id: string|null,
     *     from: array{name: string, lat: float, lon: float},
     *     to: array{name: string, lat: float, lon: float},
     *     depart: string,
     *     arrive: string,
     *     duration_min: int,
     *     distance_m: int,
     *     stops: list<string>,
     *     geometry: list<list<float>>
     * }
     */
    public function makeLeg(array $params): array
    {
        $depSec = (int) ($params['dep_seconds'] ?? 0);
        $arrSec = (int) ($params['arr_seconds'] ?? $depSec);
        $durMin = max(1, (int) round(($arrSec - $depSec) / 60));

        $from = $params['from'];
        $to = $params['to'];

        // Build simple linear or multi-point geometry
        $geometry = $params['geometry'] ?? [
            [$from['lat'], $from['lon']],
            [$to['lat'], $to['lon']],
        ];

        return [
            'mode' => $params['mode'] ?? 'walk',
            'line' => $params['line'] ?? null,
            'line_color' => $params['line_color'] ?? $this->defaultModeColor($params['mode'] ?? 'walk'),
            'trip_id' => $params['trip_id'] ?? null,
            'from' => [
                'name' => $from['name'],
                'lat' => round((float) $from['lat'], 6),
                'lon' => round((float) $from['lon'], 6),
            ],
            'to' => [
                'name' => $to['name'],
                'lat' => round((float) $to['lat'], 6),
                'lon' => round((float) $to['lon'], 6),
            ],
            'depart' => $this->formatSeconds($depSec),
            'arrive' => $this->formatSeconds($arrSec),
            'duration_min' => $durMin,
            'distance_m' => (int) round($params['distance_m'] ?? 0),
            'stops' => $params['stops'] ?? [],
            'geometry' => $geometry,
        ];
    }

    /**
     * Format seconds from midnight to HH:MM format.
     */
    public function formatSeconds(int $sec): string
    {
        $sec = max(0, $sec);
        $h = floor($sec / 3600) % 24;
        $m = floor(($sec % 3600) / 60);

        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * Return standard color code matching Sentinel & transportation conventions.
     */
    public function defaultModeColor(string $mode): string
    {
        return match ($mode) {
            'car' => '#FF5722',          // Sentinel primary orange
            'car_passenger' => '#FB923C',// Passenger orange accent
            'train' => '#7C3AED',        // Rail purple
            'bus' => '#2563EB',          // Transit blue
            'bike' => '#059669',         // Eco green
            'shared_car' => '#0891B2',   // Car-share cyan
            'walk' => '#9CA3AF',         // Pedestrian neutral gray
            default => '#6B7280',
        };
    }
}
