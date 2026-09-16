<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RatingIaRunLog extends Model
{
    protected $fillable = [
        'started_at',
        'finished_at',
        'contractors_evaluated',
        'suggestions_generated',
        'errors_count',
        'status',
        'error_message',
        'debug_details',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
