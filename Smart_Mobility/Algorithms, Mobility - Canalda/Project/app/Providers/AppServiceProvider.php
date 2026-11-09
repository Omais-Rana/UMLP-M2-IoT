<?php

namespace App\Providers;

use App\Services\Planning\PlanningDataset;
use App\Services\Routing\HaversineTravelTime;
use App\Services\Routing\TravelTimeProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            TravelTimeProvider::class,
            HaversineTravelTime::class
        );

        $this->app->scoped(PlanningDataset::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
