<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $feed_id
 * @property string $stop_id
 * @property string $name
 * @property float $lat
 * @property float $lon
 * @property int $location_type
 * @property string|null $parent_station
 */
class Stop extends Model
{
    use HasFactory;

    protected $table = 'stops';

    protected $fillable = [
        'feed_id',
        'stop_id',
        'name',
        'lat',
        'lon',
        'location_type',
        'parent_station',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lon' => 'float',
            'location_type' => 'integer',
        ];
    }

    /**
     * @return HasMany<StopTime, $this>
     */
    public function stopTimes(): HasMany
    {
        return $this->hasMany(StopTime::class, 'stop_id', 'stop_id');
    }

    /**
     * @return HasMany<Transfer, $this>
     */
    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'from_stop_id', 'stop_id')
            ->whereColumn('transfers.from_feed_id', 'stops.feed_id');
    }

    /**
     * @return HasMany<Transfer, $this>
     */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'to_stop_id', 'stop_id')
            ->whereColumn('transfers.to_feed_id', 'stops.feed_id');
    }
}
