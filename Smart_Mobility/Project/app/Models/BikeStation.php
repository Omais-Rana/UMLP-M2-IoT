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
 * @property int $capacity
 * @property int $num_bikes_available
 * @property int $num_docks_available
 * @property bool $is_installed
 * @property bool $is_renting
 * @property bool $is_returning
 */
class BikeStation extends Model
{
    use HasFactory;

    protected $table = 'bike_stations';

    protected $fillable = [
        'station_id',
        'name',
        'lat',
        'lon',
        'capacity',
        'num_bikes_available',
        'num_docks_available',
        'is_installed',
        'is_renting',
        'is_returning',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lon' => 'float',
            'capacity' => 'integer',
            'num_bikes_available' => 'integer',
            'num_docks_available' => 'integer',
            'is_installed' => 'boolean',
            'is_renting' => 'boolean',
            'is_returning' => 'boolean',
        ];
    }
}
