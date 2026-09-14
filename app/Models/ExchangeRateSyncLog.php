<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRateSyncLog extends Model
{
    protected $table = 'exchange_rate_sync_logs';

    protected $fillable = [
        'status',
        'source',
        'rates_synced',
        'error_message',
        'executed_at',
        'debug_details',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'debug_details' => 'array',
    ];
}
