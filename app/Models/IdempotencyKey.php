<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de una clave de idempotencia (header `Idempotency-Key`) por usuario.
 * La lógica vive en IdempotencyService; este modelo solo describe la fila.
 */
class IdempotencyKey extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'key',
        'method',
        'path',
        'request_hash',
        'status',
        'response_status',
        'response_body',
        'response_omitted',
        'response_headers',
        'locked_until',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_omitted' => 'boolean',
            'response_headers' => 'array',
            'locked_until' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
