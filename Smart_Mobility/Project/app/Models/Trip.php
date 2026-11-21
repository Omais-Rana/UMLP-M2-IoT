<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $feed_id
 * @property string $trip_id
 * @property string $route_id
 * @property string $service_id
 * @property string|null $trip_headsign
 * @property int $direction_id
 */
class Trip extends Model
{
    use HasFactory;

    protected $table = 'trips';

    protected $fillable = [
        'feed_id',
        'trip_id',
        'route_id',
        'service_id',
        'trip_headsign',
        'direction_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    /**
     * @return HasMany<StopTime, $this>
     */
    public function stopTimes(): HasMany
    {
        return $this->hasMany(StopTime::class, 'trip_id', 'trip_id')->orderBy('stop_sequence');
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'service_id', 'service_id');
    }
}
