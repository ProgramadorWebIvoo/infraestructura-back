<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Motor de rechazo transversal: valida el estado previo, aplica la mutación
 * de dominio específica de quien rechaza (vía callback, ya que "rechazar" en
 * Procura no es lo mismo que "rechazar" en un futuro módulo de
 * Auditoría), y audita+notifica en una sola transacción — igual que
 * `AuditLog::record()` ya hacía para el flujo de proyectos.
 *
 * No introduce un modelo/tabla nueva de "rechazos": es un servicio de
 * orquestación sobre `AuditLog`, hoy el único registro real de este tipo de
 * evento. Generalizar `AuditLog` a `morphTo` solo tendría sentido si
 * apareciera un consumidor fuera de `Project` — no existe todavía.
 */
class RejectionService
{
    /**
     * Umbral de rechazos consecutivos (sin ninguna acción distinta de por
     * medio) sobre el mismo proyecto a partir del cual se avisa — señal de
     * que algo está trabado más allá de un rechazo puntual normal del flujo.
     */
    private const CONSECUTIVE_REJECTION_ALERT_THRESHOLD = 3;

    /**
     * @param array $payload 'reason' (obligatorio) + opcionales:
     *   'observations', 'correctionsRequired', 'responsible', 'dueDate', 'evidence'.
     * @param callable $applyRejection fn(Project $project, array $payload): void —
     *   mutación de dominio (ej. soft-delete de propuestas, limpiar selección).
     *   Debe asignar atributos en memoria sin llamar save(); el servicio
     *   persiste todo junto (estado + mutación) en un único UPDATE.
     */
    public static function reject(
        Project $project,
        string $fromStatus,
        string $toStatus,
        string $role,
        string $action,
        array $payload,
        callable $applyRejection
    ): Project {
        abort_unless(
            $project->status === $fromStatus,
            422,
            "Solo se puede rechazar en estado {$fromStatus}."
        );

        DB::transaction(function () use ($project, $toStatus, $role, $action, $payload, $applyRejection) {
            $applyRejection($project, $payload);
            $project->status = $toStatus;
            $project->save();
            AuditLog::record($project, $role, $action, self::buildDetails($payload), $payload['observations'] ?? null);
            self::alertIfConsecutiveRejections($project);
        });

        return $project->fresh(['materials', 'proposals', 'payments', 'documents']);
    }

    /**
     * Detección de patrones (Fase 4 del plan de refuerzo de auditorías):
     * cuenta cuántas de las entradas más recientes de este proyecto son
     * rechazos consecutivos (`action` empieza con "Rechazo") sin que se haya
     * intercalado ninguna acción distinta — si supera el umbral, notifica
     * una sola vez por racha (no en cada rechazo adicional dentro de la
     * misma racha) para no generar ruido.
     */
    private static function alertIfConsecutiveRejections(Project $project): void
    {
        $recentActions = AuditLog::where('project_id', $project->id)
            ->latest('logged_at')
            ->limit(self::CONSECUTIVE_REJECTION_ALERT_THRESHOLD + 1)
            ->pluck('action');

        $streak = $recentActions->takeWhile(fn (string $a) => str_starts_with($a, 'Rechazo'))->count();

        if ($streak === self::CONSECUTIVE_REJECTION_ALERT_THRESHOLD) {
            NotificationDispatcher::notify(
                $project,
                'SISTEMA',
                'Racha de rechazos detectada',
                "El proyecto \"{$project->title}\" acumula {$streak} rechazos consecutivos sin avanzar de estado."
            );
        }
    }

    /**
     * Compone `details` para AuditLog: si solo llega 'reason' (caso de hoy),
     * el resultado es idéntico al string plano actual. Los campos opcionales
     * se agregan como líneas legibles adicionales, sin tocar el esquema de
     * AuditLog. 'observations' queda fuera a propósito: viaja en su propia
     * columna (`AuditLog::record()`) para que el frontend no tenga que
     * parsear texto libre para separarlo del motivo.
     */
    private static function buildDetails(array $payload): string
    {
        $reason = $payload['reason'] ?? '';
        $extra = [];
        foreach (['correctionsRequired', 'responsible', 'dueDate', 'evidence'] as $key) {
            if (!empty($payload[$key])) {
                $extra[] = ucfirst($key) . ': ' . $payload[$key];
            }
        }

        return $extra ? $reason . "\n" . implode("\n", $extra) : $reason;
    }
}
