<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportFeed extends Model
{
    protected $fillable = [
        'feed_id',
        'feed_name',
        'publisher_name',
        'publisher_url',
        'version',
        'feed_start_date',
        'feed_end_date',
        'source_type',
        'encoding',
        'agency_count',
        'stop_count',
        'route_count',
        'trip_count',
        'stop_time_count',
        'shape_point_count',
        'transfer_count',
        'warnings',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'feed_start_date' => 'date',
            'feed_end_date' => 'date',
            'warnings' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Agency, $this>
     */
    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class, 'feed_id', 'feed_id');
    }

    /**
     * @return HasMany<Shape, $this>
     */
    public function shapes(): HasMany
    {
        return $this->hasMany(Shape::class, 'feed_id', 'feed_id');
    }

    /**
     * @param  Builder<TransportFeed>  $query
     * @return Builder<TransportFeed>
     */
    public function scopeImported(Builder $query): Builder
    {
        return $query->whereNotNull('imported_at');
    }
}
