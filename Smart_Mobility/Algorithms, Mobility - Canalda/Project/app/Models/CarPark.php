<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property float $lat
 * @property float $lon
 * @property string|null $near_stop_id
 * @property int $capacity
 * @property bool $is_park_and_ride
 */
class CarPark extends Model
{
    use HasFactory;

    protected $table = 'car_parks';

    protected $fillable = [
        'name',
        'lat',
        'lon',
        'near_stop_id',
        'capacity',
        'is_park_and_ride',
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
            'is_park_and_ride' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Stop, $this>
     */
    public function nearStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'near_stop_id', 'stop_id');
    }
}
