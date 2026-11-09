<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agency extends Model
{
    protected $fillable = [
        'feed_id',
        'agency_id',
        'name',
        'url',
        'timezone',
        'language',
        'phone',
        'fare_url',
        'email',
    ];

    /**
     * @return BelongsTo<TransportFeed, $this>
     */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(TransportFeed::class, 'feed_id', 'feed_id');
    }
}
