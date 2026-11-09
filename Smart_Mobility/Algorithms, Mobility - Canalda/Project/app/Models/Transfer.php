<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $from_feed_id
 * @property string $from_stop_id
 * @property string|null $to_feed_id
 * @property string $to_stop_id
 * @property int $transfer_type
 * @property int $min_transfer_time
 * @property float $distance_m
 */
class Transfer extends Model
{
    use HasFactory;

    protected $table = 'transfers';

    protected $fillable = [
        'feed_id',
        'from_feed_id',
        'from_stop_id',
        'to_feed_id',
        'to_stop_id',
        'transfer_type',
        'source',
        'min_transfer_time',
        'distance_m',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transfer_type' => 'integer',
            'min_transfer_time' => 'integer',
            'distance_m' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Stop, $this>
     */
    public function fromStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'from_stop_id', 'stop_id')
            ->whereColumn('stops.feed_id', 'transfers.from_feed_id');
    }

    /**
     * @return BelongsTo<Stop, $this>
     */
    public function toStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'to_stop_id', 'stop_id')
            ->whereColumn('stops.feed_id', 'transfers.to_feed_id');
    }
}
