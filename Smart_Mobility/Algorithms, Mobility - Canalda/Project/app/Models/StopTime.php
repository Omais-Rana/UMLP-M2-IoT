<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $feed_id
 * @property string $trip_id
 * @property string $stop_id
 * @property string $arrival_time
 * @property string $departure_time
 * @property int $arrival_seconds
 * @property int $departure_seconds
 * @property int $stop_sequence
 * @property int $pickup_type
 * @property int $drop_off_type
 */
class StopTime extends Model
{
    use HasFactory;

    protected $table = 'stop_times';

    protected $fillable = [
        'feed_id',
        'trip_id',
        'stop_id',
        'arrival_time',
        'departure_time',
        'arrival_seconds',
        'departure_seconds',
        'stop_sequence',
        'pickup_type',
        'drop_off_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arrival_seconds' => 'integer',
            'departure_seconds' => 'integer',
            'stop_sequence' => 'integer',
            'pickup_type' => 'integer',
            'drop_off_type' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    /**
     * @return BelongsTo<Stop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'stop_id', 'stop_id');
    }
}
