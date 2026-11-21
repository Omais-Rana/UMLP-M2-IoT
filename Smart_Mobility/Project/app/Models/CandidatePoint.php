<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property float $lat
 * @property float $lon
 * @property bool $car_accessible
 * @property bool $has_parking
 * @property bool $transit_hub
 * @property string|null $description
 */
class CandidatePoint extends Model
{
    use HasFactory;

    protected $table = 'candidate_points';

    protected $fillable = [
        'name',
        'lat',
        'lon',
        'car_accessible',
        'has_parking',
        'transit_hub',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lon' => 'float',
            'car_accessible' => 'boolean',
            'has_parking' => 'boolean',
            'transit_hub' => 'boolean',
        ];
    }
}
