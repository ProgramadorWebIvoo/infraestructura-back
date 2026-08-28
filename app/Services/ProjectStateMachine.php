<?php

namespace App\Services;

use App\Models\Project;

/**
 * Fuente única de verdad del vocabulario de estados de Project y de las
 * guardas de transición — antes vivían como comparaciones de string
 * dispersas en 8+ métodos de ProjectController (`abort_unless($project->
 * status === self::STATUSES['X'], 422, '...')` repetido). Extraído para
 * que el flujo CREADO → ... → COMPLETADO_PAGADO tenga un solo lugar
 * donde se lee y se modifica, sin tocar la lógica de negocio de cada
 * endpoint (pagos, propuestas, importación) que sigue en el controller.
 */
class ProjectStateMachine
{
    public const STATUSES = [
        'CREADO'                   => 'CREADO',
        'REVISADO_CIERRE'          => 'REVISADO_CIERRE',
        'RECHAZADO_CIERRE'         => 'RECHAZADO_CIERRE',
        'CONFIRMADO_PROCURA'       => 'CONFIRMADO_PROCURA',
        'COMPARATIVA_ENVIADA'      => 'COMPARATIVA_ENVIADA',
        'CONTRATADO'               => 'CONTRATADO',
        'EN_EJECUCION'             => 'EN_EJECUCION',
        'VERIFICANDO_FINALIZACION' => 'VERIFICANDO_FINALIZACION',
        'LISTO_PAGO_FINAL'         => 'LISTO_PAGO_FINAL',
        'COMPLETADO_PAGADO'        => 'COMPLETADO_PAGADO',
    ];

    /** Orden canónico del flujo — también usado por DashboardSummaryService para ordenar el funnel. */
    public const STATUS_ORDER = [
        'CREADO'                   => 0,
        'REVISADO_CIERRE'          => 1,
        'CONFIRMADO_PROCURA'       => 2,
        'COMPARATIVA_ENVIADA'      => 3,
        'CONTRATADO'               => 4,
        'EN_EJECUCION'             => 5,
        'VERIFICANDO_FINALIZACION' => 6,
        'LISTO_PAGO_FINAL'         => 7,
        'COMPLETADO_PAGADO'        => 8,
    ];

    /**
     * Aborta con 422 si el proyecto no está en el estado requerido para la
     * acción — mismo comportamiento y firma que los `abort_unless` que
     * reemplaza, para no cambiar el contrato de la API (código, mensaje).
     */
    public static function assertStatus(Project $project, string $expected, string $message): void
    {
        abort_unless($project->status === $expected, 422, $message);
    }

    /** Variante para acciones válidas desde más de un estado (ej. evaluateDossier). */
    public static function assertStatusIn(Project $project, array $expected, string $message): void
    {
        abort_unless(in_array($project->status, $expected, true), 422, $message);
    }
}
