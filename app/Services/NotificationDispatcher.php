<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\AppNotification;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AdminActionMail;
use App\Notifications\AdminActionNotification;
use App\Notifications\ProjectActionMail;
use App\Notifications\ProjectActionNotification;
use App\Support\NotificationCatalog;
use App\Support\NotificationType;
use Illuminate\Support\Facades\DB;

/**
 * Punto único de notificación de eventos de negocio. Invocado desde
 * AuditLog::record() (acciones con proyecto) y directamente desde
 * controllers administrativos (acciones sin proyecto, ver ConfigAuditLog)
 * para que cada acción auditada también notifique a los roles interesados
 * vía push + bandeja interna persistente, y por correo cuando corresponde,
 * sin que cada controller tenga que construir el envío aparte.
 *
 * Destinatarios resueltos por NotificationRuleResolver (matriz configurable
 * acción×rol×canal, editable desde CONFIG APP). Reemplaza a
 * ProjectObserver::updated() y a la matriz fija anterior indexada por
 * ESTADO del proyecto (no por acción) — ver histórico en el plan de
 * notificaciones configurables por rol.
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
     * isMailActionAllowed()). Los destinatarios se resuelven directamente
     * por acción, sin depender de un status de proyecto que no existe para
     * estos casos.
     */
    public static function notify(?Project $project, string $role, string $action, ?string $details = null): void
    {
        if (!static::isAppNotificationAllowed($action)) {
            return;
        }

        // El propio autor de la acción no necesita que se le notifique algo
        // que él mismo acaba de hacer (ej. SUPERADMIN rechaza un expediente
        // y no debe recibir el toast/push de "expediente rechazado" a sí
        // mismo) — se excluye de ambos canales, no solo de "app".
        $actorId = auth()->id();

        $appRecipients = NotificationRuleResolver::recipientsFor($action, 'app')
            ->reject(fn (User $user) => $user->id === $actorId);
        $type = NotificationCatalog::exists($action) ? NotificationCatalog::type($action) : NotificationType::INFORMACION;

        foreach ($appRecipients as $user) {
            $appNotification = AppNotification::create([
                'user_id' => $user->id,
                'project_id' => $project?->id,
                'project_title_snapshot' => $project?->title,
                'action' => $action,
                'type' => $type,
                'details' => $details,
            ]);

            // Todo lo que sigue son efectos secundarios de "esto ya pasó de
            // verdad": push nativo, bandeja interna en tiempo real. Se
            // difieren con afterCommit() para que, si esta llamada ocurre
            // dentro de un DB::transaction() que termina en rollback (ej.
            // una operación posterior en el mismo closure lanza excepción),
            // nunca lleguen a salir. Sin transacción activa, afterCommit()
            // ejecuta el callback de inmediato — mismo comportamiento que
            // antes para el caso común.
            DB::afterCommit(function () use ($user, $project, $action, $details, $appNotification) {
                if ($project !== null) {
                    $user->notify(new ProjectActionNotification($project, $action, $project->status));
                } else {
                    $user->notify(new AdminActionNotification($action, $details));
                }

                // Con ShouldBroadcastNow el broadcast es síncrono dentro de
                // este request — si Reverb está caído, no debe tumbar el
                // flujo de negocio que originó la notificación (ej. un
                // cambio de estado de proyecto). La notificación ya quedó
                // persistida arriba; el push es una mejora, no un requisito
                // para que la acción real se complete.
                try {
                    broadcast(new NotificationCreated($appNotification));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }

        if (!static::isMailActionAllowed($action)) {
            return;
        }

        $mailRecipients = NotificationRuleResolver::recipientsFor($action, 'mail')
            ->reject(fn (User $user) => $user->id === $actorId);

        foreach ($mailRecipients as $user) {
            DB::afterCommit(function () use ($user, $project, $action, $details) {
                // El correo es igual de "mejora, no requisito" que el push
                // (ver comentario del broadcast arriba): un SMTP mal
                // configurado o caído no debe convertir una acción de
                // negocio ya confirmada (y ya respondida como éxito al
                // cliente, si esto corre de forma síncrona) en un 500.
                try {
                    if ($project !== null) {
                        $user->notify(new ProjectActionMail($project, $action, $details));
                    } else {
                        $user->notify(new AdminActionMail($action, $details));
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
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
}
