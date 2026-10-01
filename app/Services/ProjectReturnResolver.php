<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;

/**
 * Determina si una obra está "devuelta" por un rol del flujo (rechazo o
 * devolución) y aún no fue corregida, para que el registro lo señale.
 *
 * Una devolución sigue vigente mientras la obra permanezca en el estado al que
 * esa acción la envió: cualquier avance (reenvío, corrección) la mueve de estado
 * y el indicador desaparece solo, sin banderas que mantener. Los rechazos del
 * informe de cierre se leen del propio informe, que limpia el motivo al reenviar.
 */
class ProjectReturnResolver
{
    /**
     * acción auditada => [estado al que devuelve, de dónde sale el motivo].
     * 'details' guarda el motivo en las acciones de RejectionService; la
     * devolución de finiquito lo guarda en 'observations'.
     */
    private const LOG_RETURNS = [
        'Rechazo de petición de obra' => ['EN' => 'RECHAZADO_AUDITORIA', 'reason' => 'details'],
        'Solicitud de reevaluación a Auditoría' => ['EN' => 'EN_REEVALUACION_AUDITORIA', 'reason' => 'details'],
        'Rechazo de cuadro comparativo' => ['EN' => 'CONFIRMADO_PROCURA', 'reason' => 'details'],
        'Rechazo de adjudicacion por Presidencia' => ['EN' => 'COMPARATIVA_ENVIADA', 'reason' => 'details'],
        'Devolucion de finiquito a Auditoria' => ['EN' => 'VERIFICANDO_FINALIZACION', 'reason' => 'observations'],
    ];

    /** @return string[] acciones auditadas que constituyen una devolución */
    public static function actions(): array
    {
        return array_keys(self::LOG_RETURNS);
    }

    /**
     * Requiere `latestReturnLog` y `closureReport` cargadas; sin ellas no
     * resuelve (null) para no disparar consultas por fila en los listados.
     *
     * @return array{byRole: string, target: ?string, reason: ?string, at: ?string}|null
     */
    public static function resolve(Project $project): ?array
    {
        $report = $project->relationLoaded('closureReport') ? $project->closureReport : null;
        if ($report?->rejection_reason !== null && $report->rejected_by_role !== null) {
            return [
                'byRole' => $report->rejected_by_role,
                'target' => $report->rejection_target,
                'reason' => $report->rejection_reason,
                'at' => optional($report->updated_at)->toIso8601String(),
            ];
        }

        /** @var AuditLog|null $log */
        $log = $project->relationLoaded('latestReturnLog') ? $project->latestReturnLog : null;
        $rule = $log ? (self::LOG_RETURNS[$log->action] ?? null) : null;
        if ($rule === null || $project->status !== $rule['EN']) {
            return null;
        }

        $reason = $rule['reason'] === 'observations' ? $log->observations : strtok((string) $log->details, "\n");

        return [
            'byRole' => $log->role,
            'target' => null,
            'reason' => $reason === false || $reason === '' ? null : $reason,
            'at' => optional($log->logged_at)->toIso8601String(),
        ];
    }
}
