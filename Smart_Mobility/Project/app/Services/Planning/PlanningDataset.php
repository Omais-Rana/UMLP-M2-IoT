<?php

namespace App\Services\Planning;

use App\Models\CandidatePoint;
use App\Models\Route;
use App\Models\Stop;
use Illuminate\Support\Collection;

/**
 * Request-scoped planning data shared by participant and strategy evaluation.
 *
 * @property-read Collection<string, Stop> $stops
 * @property-read Collection<int, CandidatePoint> $candidates
 * @property-read Collection<string, Route> $routes
 */
class PlanningDataset
{
    /** @var Collection<string, Stop>|null */
    protected ?Collection $stops = null;

    /** @var Collection<int, CandidatePoint>|null */
    protected ?Collection $candidates = null;

    /** @var Collection<string, Route>|null */
    protected ?Collection $routes = null;

    /**
     * @return Collection<string, Stop>
     */
    public function stops(): Collection
    {
        return $this->stops ??= Stop::query()
            ->get(['id', 'stop_id', 'name', 'lat', 'lon', 'feed_id'])
            ->keyBy(fn (Stop $stop): string => $stop->feed_id.':'.$stop->stop_id);
    }

    /**
     * @return Collection<int, CandidatePoint>
     */
    public function candidates(): Collection
    {
        return $this->candidates ??= CandidatePoint::query()
            ->get(['id', 'name', 'lat', 'lon', 'car_accessible', 'has_parking', 'transit_hub']);
    }

    /**
     * @return Collection<string, Route>
     */
    public function routes(): Collection
    {
        return $this->routes ??= Route::query()
            ->get(['id', 'route_id', 'short_name', 'long_name', 'route_type', 'route_color', 'feed_id'])
            ->keyBy(fn (Route $route): string => $route->feed_id.':'.$route->route_id);
    }
}
