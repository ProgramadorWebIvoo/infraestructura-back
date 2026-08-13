<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectActionMail;
use App\Notifications\ProjectActionNotification;

/**
 * Punto único de notificación de eventos de negocio sobre un Project.
 * Invocado desde AuditLog::record() para que cada acción auditada (creación,
 * revisión, rechazo, adjudicación, pago, etc.) también notifique a los roles
 * interesados vía push + bandeja interna persistente, y por correo cuando la
 * acción es crítica, sin que cada controller tenga que dispararlo aparte.
 *
 * Reemplaza a ProjectObserver::updated(), que solo cubría cambios de status
 * y quedaba desincronizado de las acciones que no cambiaban status pero sí
 * requerían notificar (ej. rechazos, carga de documentos).
 */
class NotificationDispatcher
{
    /**
     * Fallback si el setting "acciones_con_correo" no existe/está vacío
     * (BD sin migrar, o borrado por error) — mismas 4 acciones que antes
     * vivían hardcodeadas aquí.
     */
    private const DEFAULT_MAIL_ACTIONS = [
        'Rechazo de cuadro comparativo',
        'Confirmacion de contratacion',
        'Liberacion de anticipo',
        'Liberacion total de fondos',
    ];

    /**
     * Catálogo único de acciones auditadas que efectivamente puede generar
     * la app vía AuditLog::record() con destinatarios reales (recipientsFor()
     * las resuelve por status de proyecto). Fuente de verdad tanto para el
     * default de `acciones_con_notificacion_app` (migración
     * 2026_08_13_000001) como para el selector de tags en CONFIG APP
     * (GET /settings/notification-actions) — así el frontend nunca muestra
     * una acción que la app no dispara realmente.
     */
    public const AUDITABLE_ACTIONS = [
        'Creacion de peticion de obra',
        'Revision tecnica de calculos y planos',
        'Confirmacion de presupuesto y envio a licitacion',
        'Carga de propuesta',
        'Carga de cuadro comparativo',
        'Importación automática de propuestas de proveedores',
        'Eliminacion de propuesta',
        'Rechazo de cuadro comparativo',
        'Confirmacion de contratacion',
        'Liberacion de anticipo',
        'Liberacion total de fondos',
        'Reporte de obra finalizada',
        'Verificacion de finalizacion y calidad de obra',
        'Carga de hojas de calculo/cubicaciones',
        'Carga de planos de ingenieria',
        'Eliminacion de documento adjunto',
    ];

    public static function notify(Project $project, string $role, string $action, ?string $details = null): void
    {
        $recipients = static::recipientsFor($project->status, $role);

        if ($recipients->isEmpty()) {
            return;
        }

        // Acciones que disparan push + bandeja interna — por defecto todas,
        // editable desde CONFIG APP para silenciar acciones de bajo valor sin
        // dejar de auditarlas (AuditLog::record() ya se hizo antes de llegar
        // aquí). Si el setting no existe (BD sin migrar), no se filtra nada.
        $notifyActions = SettingsService::get('acciones_con_notificacion_app');
        $sendAppNotification = $notifyActions === null || in_array($action, $notifyActions, true);

        if (!$sendAppNotification) {
            return;
        }

        // Acciones que además disparan correo — no todas las auditadas
        // ameritan correo (sería spam). Editable desde CONFIG APP (Fase 1.4).
        $mailActions = SettingsService::get('acciones_con_correo', self::DEFAULT_MAIL_ACTIONS);
        $sendMail = in_array($action, $mailActions, true);

        foreach ($recipients as $user) {
            $user->notify(new ProjectActionNotification($project, $action, $project->status));

            AppNotification::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'project_title_snapshot' => $project->title,
                'action' => $action,
                'details' => $details,
            ]);

            if ($sendMail) {
                $user->notify(new ProjectActionMail($project, $action, $details));
            }
        }
    }

    /**
     * Misma matriz rol→destinatarios que usaba ProjectObserver, ahora
     * indexada por el estado actual del proyecto en el momento de la acción.
     */
    private static function recipientsFor(string $status, string $sourceRole): \Illuminate\Support\Collection
    {
        $roles = match ($status) {
            'CREADO'                    => ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'],
            'REVISADO_CIERRE'           => ['PROCURA', 'SUPERADMIN', 'ADMIN'],
            'CONFIRMADO_PROCURA'        => ['ANALISTA', 'SUPERADMIN', 'ADMIN'],
            'COMPARATIVA_ENVIADA'       => ['PROCURA', 'SUPERADMIN', 'ADMIN'],
            'CONTRATADO'                => ['FINANZAS', 'CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'],
            'EN_EJECUCION'              => ['CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'],
            'VERIFICANDO_FINALIZACION'  => ['SUPERADMIN', 'ADMIN'],
            'LISTO_PAGO_FINAL'          => ['FINANZAS', 'SUPERADMIN', 'ADMIN'],
            'COMPLETADO_PAGADO'         => ['CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'],
            default                     => [],
        };

        if (empty($roles)) {
            return collect();
        }

        return User::whereIn('role', $roles)->get();
    }
}
