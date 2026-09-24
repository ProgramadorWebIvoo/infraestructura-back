<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectRateFreeze;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\DB;

/**
 * Circuito de aprobación de la adjudicación:
 * Procura selecciona (PENDIENTE_PRESIDENCIA) → Presidencia aprueba o rechaza →
 * Procura envía a Finanzas (CONTRATADO). El proveedor y Finanzas solo se
 * notifican en el último paso ('Confirmacion de contratacion').
 */
class AwardApprovalService
{
    private const S = ProjectStateMachine::STATUSES;

    public function __construct(private RateFreezeService $rateFreezeService)
    {
    }

    public function approve(Project $project, ?string $observations = null): Project
    {
        ProjectStateMachine::assertStatus($project, self::S['PENDIENTE_PRESIDENCIA'], 'Solo se puede aprobar una adjudicación pendiente de Presidencia (PENDIENTE_PRESIDENCIA).');

        $project->update(['status' => self::S['APROBADO_PRESIDENCIA']]);
        AuditLog::record($project, 'PRESIDENCIA', 'Aprobacion de adjudicacion por Presidencia', "Contratista {$project->selected_contractor_code} aprobado.", $observations);

        return $project;
    }

    /**
     * Aprobación en bloque, todo o nada: si alguna obra no está pendiente
     * no se aprueba ninguna, para no dejar el lote a medias.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     */
    public function approveBatch($projects, ?string $observations = null): void
    {
        foreach ($projects as $project) {
            ProjectStateMachine::assertStatus($project, self::S['PENDIENTE_PRESIDENCIA'], "La obra \"{$project->title}\" no está pendiente de Presidencia.");
        }

        DB::transaction(fn () => $projects->each(fn (Project $p) => $this->approve($p, $observations)));
    }

    public function reject(Project $project, array $payload): Project
    {
        return RejectionService::reject(
            $project,
            self::S['PENDIENTE_PRESIDENCIA'],
            self::S['COMPARATIVA_ENVIADA'],
            'PRESIDENCIA',
            'Rechazo de adjudicacion por Presidencia',
            $payload,
            function (Project $project) {
                $project->selected_contractor_code = null;
                $project->selected_proposal_id = null;
            }
        );
    }

    public function sendToFinance(Project $project): Project
    {
        ProjectStateMachine::assertStatus($project, self::S['APROBADO_PRESIDENCIA'], 'Solo se puede enviar a Finanzas una adjudicación aprobada por Presidencia (APROBADO_PRESIDENCIA).');

        $proposal = $project->proposals()->whereKey($project->selected_proposal_id)->first();
        abort_unless($proposal, 422, 'La propuesta adjudicada ya no existe.');

        DB::transaction(function () use ($project, $proposal) {
            $project->update(['status' => self::S['CONTRATADO']]);

            $this->rateFreezeService->freezeForTrigger(
                $project,
                ProjectRateFreeze::TRIGGER_CONTRATADO,
                (float) $proposal->total_cost
            );
        });

        AuditLog::record($project, 'PROCURA', 'Confirmacion de contratacion', "Contratista {$project->selected_contractor_code} adjudicado y enviado a Finanzas.");
        CacheVersion::bump('contractor_history:' . $project->selected_contractor_code);

        return $project;
    }
}
