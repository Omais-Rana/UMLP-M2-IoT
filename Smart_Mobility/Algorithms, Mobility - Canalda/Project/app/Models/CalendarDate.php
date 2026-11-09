<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $feed_id
 * @property string $service_id
 * @property Carbon $date
 * @property int $exception_type
 */
class CalendarDate extends Model
{
    use HasFactory;

    protected $table = 'calendar_dates';

    protected $fillable = [
        'feed_id',
        'service_id',
        'date',
        'exception_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'exception_type' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'service_id', 'service_id');
    }
}
