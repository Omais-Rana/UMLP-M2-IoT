<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $station_id
 * @property string $name
 * @property float $lat
 * @property float $lon
 * @property int $num_cars_available
 * @property array<string, mixed>|null $car_types
 */
class CarShareStation extends Model
{
    use HasFactory;

    protected $table = 'car_share_stations';

    protected $fillable = [
        'station_id',
        'name',
        'lat',
        'lon',
        'num_cars_available',
        'car_types',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lon' => 'float',
            'num_cars_available' => 'integer',
            'car_types' => 'array',
        ];
    }
}
