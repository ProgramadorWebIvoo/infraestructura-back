<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'project_id',
        'project_title_snapshot',
        'role',
        'user_id',
        'user_name_snapshot',
        'action',
        'logged_at',
        'details',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /**
     * Registra una entrada de auditoría para un proyecto. Punto único de
     * generación de ID (timestamp + sufijo random para evitar colisiones
     * bajo concurrencia) — antes triplicado carácter por carácter entre
     * ProjectController::log(), ProjectDocumentController::log() y
     * AIEvaluationController::logEvaluation().
     */
    public static function record(Project $project, string $role, string $action, ?string $details = null): self
    {
        $user = auth()->user();

        return static::create([
            'id' => 'LOG-' . now()->format('YmdHisv') . '-' . Str::random(4),
            'project_id' => $project->id,
            'project_title_snapshot' => $project->title,
            'role' => $role,
            'user_id' => $user?->id,
            'user_name_snapshot' => $user?->name,
            'action' => $action,
            'logged_at' => now(),
            'details' => $details,
        ]);
    }

    protected $casts = [
        'logged_at' => 'datetime:Y-m-d H:i:s',
    ];
}
