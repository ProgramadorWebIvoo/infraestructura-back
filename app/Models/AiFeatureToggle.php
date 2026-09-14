<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila = "la IA está habilitada/deshabilitada para este departamento" (si
 * `action` es null, interruptor maestro) o "...para esta acción específica
 * del departamento" (si `action` tiene valor). Modelo delgado — la lógica de
 * resolución/caché vive en AiFeatureGate, no aquí (mismo criterio que
 * NotificationRule/NotificationRuleResolver).
 */
class AiFeatureToggle extends Model
{
    protected $fillable = ['department', 'action', 'enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
