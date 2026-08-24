<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $table = 'ai_usage_logs';

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'provider',
        'model',
        'endpoint',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cost_estimate',
        'response_time_ms',
        'success',
        'error_message',
        'requested_by',
        'created_at',
    ];

    protected $casts = [
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens'      => 'integer',
        'cost_estimate'     => 'float',
        'response_time_ms'  => 'integer',
        'success'           => 'boolean',
        'created_at'        => 'datetime',
    ];

    // ── Relationships ──

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
