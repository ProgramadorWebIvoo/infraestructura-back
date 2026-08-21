<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Motor de rechazo transversal: valida el estado previo, aplica la mutación
 * de dominio específica de quien rechaza (vía callback, ya que "rechazar" en
 * Procura no es lo mismo que "rechazar" en un futuro módulo de Cierre de
 * Obra), y audita+notifica en una sola transacción — igual que
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
        });

        return $project->fresh(['materials', 'proposals', 'payments', 'documents']);
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
