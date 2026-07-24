<?php

namespace App\Observers;

use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectStatusChanged;

class ProjectObserver
{
    public function updated(Project $project): void
    {
        if ($project->isDirty('status')) {
            $oldStatus = $project->getOriginal('status');
            $newStatus = $project->status;

            $roles = $this->getRolesForStatus($newStatus);

            if (empty($roles)) {
                return;
            }

            $users = User::whereIn('role', $roles)->get();
            foreach ($users as $user) {
                $user->notify(new ProjectStatusChanged($project, $oldStatus, $newStatus));
            }
        }
    }

    private function getRolesForStatus(string $status): array
    {
        return match ($status) {
            'CREADO'              => ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'],
            'REVISADO_CIERRE'     => ['PROCURA', 'SUPERADMIN', 'ADMIN'],
            'CONFIRMADO_PROCURA'  => ['ANALISTA', 'SUPERADMIN', 'ADMIN'],
            'COMPARATIVA_ENVIADA' => ['PROCURA', 'SUPERADMIN', 'ADMIN'],
            'CONTRATADO'          => ['FINANZAS', 'CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'],
            'EN_EJECUCION'        => ['CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'],
            'VERIFICANDO_FINALIZACION' => ['SUPERADMIN', 'ADMIN'],
            'LISTO_PAGO_FINAL'    => [],
            'COMPLETADO_PAGADO'   => ['CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'],
            default               => [],
        };
    }
}
