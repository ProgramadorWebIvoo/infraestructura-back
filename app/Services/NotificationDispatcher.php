<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Punto único de notificación de eventos de negocio. Invocado desde
 * AuditLog::record() (acciones con proyecto) y directamente desde
 * controllers administrativos (acciones sin proyecto, ver ConfigAuditLog)
 * para que cada acción auditada también notifique a los roles interesados
 * vía push + bandeja interna persistente, y por correo cuando corresponde,
 * sin que cada controller tenga que construir el envío aparte.
 *
 * Destinatarios resueltos por NotificationRuleResolver (matriz
 * configurable acción×rol×canal) detrás del flag `usar_matriz_notificaciones`
 * — mientras esté en `false`, usa `recipientsFor()` legacy (match por
 * ESTADO del proyecto, no por acción) para no alterar el comportamiento en
 * producción hasta verificar la equivalencia exacta entre ambos caminos
 * (ver comando `notifications:compare-recipients`). Reemplaza a
 * ProjectObserver::updated(), que solo cubría cambios de status.
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
     * `$project = null` cubre acciones administrativas (usuarios,
     * proveedores, materiales, config de IA) y otras sin proyecto asociado
     * (ej. reset de password, que no pasa por acá — ver
     * isMailActionAllowed()). Con la matriz activa, se resuelven
     * destinatarios directamente por acción, sin depender de un status de
     * proyecto que no existe para estos casos — antes esta rama simplemente
     * no notificaba nada (`if ($project === null) return;`).
     */
    public static function notify(?Project $project, string $role, string $action, ?string $details = null): void
    {
        if (!static::isAppNotificationAllowed($action)) {
            return;
        }

        $sendMail = static::isMailActionAllowed($action);

        if (static::useRuleMatrix()) {
            static::notifyViaMatrix($project, $action, $details, $sendMail);
            return;
        }

        if ($project === null) {
            return;
        }

        static::notifyViaLegacyStatusMatrix($project, $role, $action, $details, $sendMail);
    }

    private static function notifyViaMatrix(?Project $project, string $action, ?string $details, bool $sendMail): void
    {
        $appRecipients = NotificationRuleResolver::recipientsFor($action, 'app');

        foreach ($appRecipients as $user) {
            if ($project !== null) {
                $user->notify(new \App\Notifications\ProjectActionNotification($project, $action, $project->status));
            }

            AppNotification::create([
                'user_id' => $user->id,
                'project_id' => $project?->id,
                'project_title_snapshot' => $project?->title,
                'action' => $action,
                'details' => $details,
            ]);
        }

        if (!$sendMail) {
            return;
        }

        $mailRecipients = NotificationRuleResolver::recipientsFor($action, 'mail');

        foreach ($mailRecipients as $user) {
            if ($project !== null) {
                $user->notify(new \App\Notifications\ProjectActionMail($project, $action, $details));
            }
        }
    }

    private static function notifyViaLegacyStatusMatrix(Project $project, string $role, string $action, ?string $details, bool $sendMail): void
    {
        $recipients = static::recipientsFor($project->status, $role);

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $user) {
            $user->notify(new \App\Notifications\ProjectActionNotification($project, $action, $project->status));

            AppNotification::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'project_title_snapshot' => $project->title,
                'action' => $action,
                'details' => $details,
            ]);

            if ($sendMail) {
                $user->notify(new \App\Notifications\ProjectActionMail($project, $action, $details));
            }
        }
    }

    private static function useRuleMatrix(): bool
    {
        return (bool) SettingsService::get('usar_matriz_notificaciones', false);
    }

    /**
     * Acciones que disparan push + bandeja interna — por defecto todas,
     * editable desde CONFIG APP para silenciar acciones de bajo valor sin
     * dejar de auditarlas. Si el setting no existe (BD sin migrar), no se
     * filtra nada.
     */
    private static function isAppNotificationAllowed(string $action): bool
    {
        $notifyActions = SettingsService::get('acciones_con_notificacion_app');

        return $notifyActions === null || in_array($action, $notifyActions, true);
    }

    /**
     * Expone el filtro `acciones_con_correo` para emisores que no pasan por
     * `notify()` (correos con contenido que este dispatcher no puede
     * construir, ej. el token de restablecimiento de contraseña) pero sí
     * quieren respetar el mismo control de CONFIG APP antes de enviar.
     */
    public static function isMailActionAllowed(string $action): bool
    {
        $mailActions = SettingsService::get('acciones_con_correo', self::DEFAULT_MAIL_ACTIONS);

        return in_array($action, $mailActions, true);
    }

    /**
     * Legacy: matriz rol→destinatarios indexada por el estado del proyecto,
     * no por la acción. Se mantiene solo mientras `usar_matriz_notificaciones`
     * esté en `false` — ver Fase D del plan de notificaciones configurables.
     * Público únicamente para que el comando `notifications:compare-recipients`
     * (el gate antes de activar el flag) pueda comparar ambos caminos; este
     * método y el comando se eliminan juntos en el deploy de limpieza.
     */
    public static function recipientsFor(string $status, string $sourceRole): Collection
    {
        $roles = static::rolesForStatus($status);

        if (empty($roles)) {
            return collect();
        }

        return \App\Models\User::whereIn('role', $roles)->get();
    }

    /**
     * Solo los roles configurados por status (sin hidratar usuarios) — para
     * que `notifications:compare-recipients` compare configuración contra
     * configuración, no "usuarios que existen hoy en esta BD por rol" (un
     * rol sin ningún usuario activo no debería contar como "mismatch").
     */
    public static function rolesForStatus(string $status): array
    {
        return match ($status) {
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
    }
}
