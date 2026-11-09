<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $feed_id
 * @property string $route_id
 * @property string|null $short_name
 * @property string|null $long_name
 * @property int $route_type
 * @property string|null $route_color
 * @property string|null $route_text_color
 */
class Route extends Model
{
    use HasFactory;

    protected $table = 'routes';

    protected $fillable = [
        'feed_id',
        'route_id',
        'short_name',
        'long_name',
        'route_type',
        'route_color',
        'route_text_color',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'route_type' => 'integer',
        ];
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id', 'route_id');
    }
}
