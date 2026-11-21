<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanningRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'status',
        'total_participants',
        'completed_participants',
        'participants',
        'request_payload',
        'result',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'request_payload' => 'array',
            'result' => 'array',
        ];
    }
}
