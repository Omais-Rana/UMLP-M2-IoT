<?php

namespace App\Models;

use Database\Factories\SharedPlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $code
 * @property array<string, mixed> $option
 * @property array<string, mixed>|null $destination
 * @property array<string, mixed>|null $participants
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SharedPlan extends Model
{
    /** @use HasFactory<SharedPlanFactory> */
    use HasFactory;

    use Prunable;

    protected $fillable = [
        'code',
        'option',
        'destination',
        'participants',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'option' => 'array',
            'destination' => 'array',
            'participants' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }

    /**
     * Prune all expired shared plans immediately.
     */
    public static function pruneExpired(): int
    {
        return static::where('expires_at', '<=', now())->delete();
    }

    /**
     * Check if the shared plan is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Generate a unique 6-character uppercase alphanumeric code.
     */
    public static function generateUniqueCode(): string
    {
        static::pruneExpired();

        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $maxAttempts = 10;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = '';
            for ($c = 0; $c < 6; $c++) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }

            if (! static::where('code', $code)->where(function (Builder $query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->exists()) {
                return $code;
            }
        }

        return Str::upper(Str::random(6));
    }
}
