<?php

namespace Database\Factories;

use App\Models\SharedPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedPlan>
 */
class SharedPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => SharedPlan::generateUniqueCode(),
            'option' => [
                'title' => 'Rendez-vous à Gare de Belfort',
                'strategy_type' => 'hub',
                'meeting_points' => [
                    [
                        'name' => 'Gare de Belfort',
                        'lat' => 47.6334,
                        'lon' => 6.8542,
                        'type' => 'hub',
                    ],
                ],
                'arrival_at_destination' => '09:15',
                'itineraries' => [],
            ],
            'destination' => [
                'name' => 'UTBM Belfort',
                'lat' => 47.6425,
                'lon' => 6.8436,
            ],
        ];
    }
}
