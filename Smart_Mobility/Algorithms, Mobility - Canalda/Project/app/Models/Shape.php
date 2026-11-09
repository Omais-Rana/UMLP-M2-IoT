<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shape extends Model
{
    protected $fillable = [
        'feed_id',
        'shape_id',
        'shape_pt_sequence',
        'lat',
        'lon',
        'distance_traveled',
    ];

    protected function casts(): array
    {
        return [
            'shape_pt_sequence' => 'integer',
            'lat' => 'float',
            'lon' => 'float',
            'distance_traveled' => 'float',
        ];
    }

    /**
     * @return BelongsTo<TransportFeed, $this>
     */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(TransportFeed::class, 'feed_id', 'feed_id');
    }
}
