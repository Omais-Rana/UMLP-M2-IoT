<?php

namespace App\Http\Controllers;

use App\Models\BikeStation;
use App\Models\Calendar;
use App\Models\CandidatePoint;
use App\Models\CarPark;
use App\Models\Stop;
use App\Models\TransportFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MapController extends Controller
{
    /**
     * Render the single-page multimodal map application.
     */
    public function index(): View
    {
        $bootstrapData = $this->getBootstrapPayload();

        return view('map', [
            'bootstrap' => $bootstrapData,
        ]);
    }

    /**
     * Provide bootstrap initialization data via JSON API.
     */
    public function bootstrap(): JsonResponse
    {
        return response()->json($this->getBootstrapPayload());
    }

    /**
     * Prepare shared bootstrap dataset.
     *
     * @return array<string, mixed>
     */
    protected function getBootstrapPayload(): array
    {
        $placesFile = config('mobility.data_path').'/places.json';
        $places = file_exists($placesFile) ? (json_decode(file_get_contents($placesFile), true) ?? []) : [];

        $stops = Stop::query()
            ->where('lat', '>', 0)
            ->where('lon', '>', 0)
            ->select('name', DB::raw('AVG(lat) as lat'), DB::raw('AVG(lon) as lon'))
            ->groupBy('name')
            ->orderBy('name')
            ->get()
            ->map(fn (object $s): array => [
                'name' => (string) $s->name,
                'lat' => round((float) $s->lat, 6),
                'lon' => round((float) $s->lon, 6),
            ])
            ->all();

        $candidates = CandidatePoint::all();
        $carParks = CarPark::all();
        $bikeStations = BikeStation::all();

        $minDate = Calendar::min('start_date') ?? '2026-01-01';
        $maxDate = Calendar::max('end_date') ?? '2027-12-31';
        $feeds = TransportFeed::query()->orderBy('feed_id')->get([
            'feed_id',
            'feed_name',
            'version',
            'feed_start_date',
            'feed_end_date',
            'imported_at',
        ]);

        return [
            'places' => $places,
            'stops' => $stops,
            'candidates' => $candidates,
            'car_parks' => $carParks,
            'bike_stations' => $bikeStations,
            'bounding_box' => config('mobility.bounding_box'),
            'feed_meta' => [
                'snapshot_date' => optional($feeds->max('imported_at'))->format('Y-m-d'),
                'valid_from' => $minDate,
                'valid_until' => $maxDate,
                'stops_count' => Stop::count(),
                'feeds' => $feeds,
            ],
            'config' => [
            ],
        ];
    }
}
