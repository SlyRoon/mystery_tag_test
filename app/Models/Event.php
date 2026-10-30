<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'click_id',
        'event_name',
        'amount_canonical',
        'accepted_at',
        'status',
        'attempt_count',
        'last_error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
