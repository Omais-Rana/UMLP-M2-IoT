<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $feed_id
 * @property string $service_id
 * @property bool $monday
 * @property bool $tuesday
 * @property bool $wednesday
 * @property bool $thursday
 * @property bool $friday
 * @property bool $saturday
 * @property bool $sunday
 * @property Carbon $start_date
 * @property Carbon $end_date
 */
class Calendar extends Model
{
    use HasFactory;

    protected $table = 'calendar';

    protected $fillable = [
        'feed_id',
        'service_id',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monday' => 'boolean',
            'tuesday' => 'boolean',
            'wednesday' => 'boolean',
            'thursday' => 'boolean',
            'friday' => 'boolean',
            'saturday' => 'boolean',
            'sunday' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * Determine if this service runs on the given date (considering day of week and date range).
     */
    public function runsOnDate(CarbonInterface $date): bool
    {
        $dateStr = $date->format('Y-m-d');
        if ($dateStr < $this->start_date->format('Y-m-d') || $dateStr > $this->end_date->format('Y-m-d')) {
            return false;
        }

        return match ($date->dayOfWeekIso) {
            1 => $this->monday,
            2 => $this->tuesday,
            3 => $this->wednesday,
            4 => $this->thursday,
            5 => $this->friday,
            6 => $this->saturday,
            7 => $this->sunday,
            default => false,
        };
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'service_id', 'service_id');
    }

    /**
     * @return HasMany<CalendarDate, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(CalendarDate::class, 'service_id', 'service_id');
    }
}
