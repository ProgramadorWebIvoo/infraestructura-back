<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Project;
use App\Models\SupplierInvitation;
use App\Services\NotificationDispatcher;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Alertas proactivas de plazo — antes solo existían como dato pasivo (el
 * dashboard de Presidencia calculaba "obras estancadas" bajo demanda, ver
 * DashboardSummaryService, y las invitaciones a proveedor simplemente
 * expiraban sin avisar a nadie). Este comando corre diario (ver
 * routes/console.php) y convierte ambas señales en notificaciones reales.
 *
 * No re-notifica lo mismo cada día: antes de crear una alerta, verifica si
 * ya existe una AppNotification de esa acción para ese proyecto/invitación
 * dentro de la ventana de "un día" — evitar spam sin necesitar una columna
 * nueva de tracking en projects/supplier_invitations.
 */
class AlertStaleProjectsAndExpiringInvitations extends Command
{
    protected $signature = 'alertas:vencimientos';
    protected $description = 'Notifica obras sin actividad reciente e invitaciones a proveedor próximas a vencer';

    private const STALE_ACTION = 'Obra sin actividad reciente';
    private const INVITATION_EXPIRING_ACTION = 'Invitacion a proveedor proxima a vencer';

    /** Ventana antes del vencimiento en la que se considera "próxima a vencer". */
    private const INVITATION_WARNING_HOURS = 48;

    public function handle(): int
    {
        $staleCount = $this->alertStaleProjects();
        $invitationCount = $this->alertExpiringInvitations();

        $this->info("Obras estancadas notificadas: {$staleCount}. Invitaciones por vencer notificadas: {$invitationCount}.");

        return self::SUCCESS;
    }

    private function alertStaleProjects(): int
    {
        $thresholdDays = (int) SettingsService::get('proyecto_estancado_umbral_dias', 14);
        $notified = 0;

        Project::where('status', '!=', 'COMPLETADO_PAGADO')
            ->where('updated_at', '<=', now()->subDays($thresholdDays))
            ->each(function (Project $project) use (&$notified) {
                if ($this->alreadyNotifiedToday($project->id, self::STALE_ACTION)) {
                    return;
                }

                $days = (int) abs(now()->diffInDays($project->updated_at));
                NotificationDispatcher::notify(
                    $project,
                    'SISTEMA',
                    self::STALE_ACTION,
                    "Sin actividad hace {$days} día(s), estado actual: {$project->status}."
                );
                $notified++;
            });

        return $notified;
    }

    private function alertExpiringInvitations(): int
    {
        $notified = 0;

        SupplierInvitation::whereNull('used_at')
            ->whereNull('replaced_by')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addHours(self::INVITATION_WARNING_HOURS))
            ->with('project')
            ->get()
            ->each(function (SupplierInvitation $invitation) use (&$notified) {
                if (!$invitation->project) {
                    return;
                }
                if ($this->alreadyNotifiedToday($invitation->project_id, self::INVITATION_EXPIRING_ACTION, $invitation->id)) {
                    return;
                }

                $hoursLeft = (int) max(0, now()->diffInHours($invitation->expires_at, false));
                NotificationDispatcher::notify(
                    $invitation->project,
                    'SISTEMA',
                    self::INVITATION_EXPIRING_ACTION,
                    "Invitación a {$invitation->supplier_name} vence en {$hoursLeft} hora(s) (ID {$invitation->id})."
                );
                $notified++;
            });

        return $notified;
    }

    /**
     * Evita re-notificar el mismo aviso el mismo día. `$discriminator`
     * (opcional) permite distinguir múltiples invitaciones del mismo
     * proyecto, ya que AppNotification no guarda una referencia directa al
     * ID de la invitación — se compara contra el texto de `details`.
     */
    private function alreadyNotifiedToday(string $projectId, string $action, ?string $discriminator = null): bool
    {
        $query = AppNotification::where('project_id', $projectId)
            ->where('action', $action)
            ->where('created_at', '>=', now()->startOfDay());

        if ($discriminator !== null) {
            $query->where('details', 'like', "%ID {$discriminator}%");
        }

        return $query->exists();
    }
}
