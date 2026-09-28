<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectClosureReport;
use App\Models\ProjectPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierre posterior a la ejecución (finiquito):
 * Contratista y residente cargan cada uno su propio informe, en cualquier orden
 * e independientes entre sí: EN_EJECUCION → INFORME_ENVIADO (llegó uno, falta el
 * otro) → VERIFICANDO_FINALIZACION (ambos; Auditoría compara) → Auditoría verifica
 * (PENDIENTE_SOLICITUD_FINIQUITO) → Procura solicita el pago (LISTO_PAGO_FINAL) →
 * Finanzas paga. Auditoría puede devolver el informe del contratista o el del
 * residente; el otro se conserva. Ningún paso se salta.
 */
class ProjectClosureService
{
    private const S = ProjectStateMachine::STATUSES;

    public function __construct(private ClosureReportLinkService $links, private ClosureMeasurementService $measurements, private ProjectModificationService $modifications)
    {
    }

    /** Envío del contratista por el enlace público: partidas ejecutadas + notas + ≥1 foto. */
    public function submit(ProjectClosureReport $report, array $data): ProjectClosureReport
    {
        $project = $report->project;
        ProjectStateMachine::assertStatusIn($project, [self::S['EN_EJECUCION'], self::S['INFORME_ENVIADO']], 'Solo se puede enviar el informe de una obra en ejecución.');
        abort_unless($report->isEditableByContractor(), 422, 'El informe ya fue enviado y está en revisión.');
        abort_if($this->modifications->hasPending($project), 422, 'Hay modificaciones de obra pendientes de aprobación; deben resolverse antes de enviar el informe de cierre.');
        abort_if($project->effectiveResidentId() === null, 422, 'La obra no tiene ingeniero residente asignado; Auditoría debe asignarlo antes de enviar el informe.');
        abort_unless($report->photos()->exists(), 422, 'Adjunte al menos una foto de evidencia antes de enviar el informe.');

        $incoming = collect($data['items'] ?? [])->keyBy('id');
        $errors = [];

        DB::transaction(function () use ($report, $project, $data, $incoming, &$errors) {
            foreach ($report->items as $item) {
                $row = $incoming->get($item->id);
                if ($row === null) {
                    continue;
                }
                $executed = (float) $row['executedQuantity'];
                $note = $row['note'] ?? null;

                if ($executed !== (float) $item->contracted_quantity && blank($note)) {
                    $kind = $executed > $item->contracted_quantity ? 'el aumento' : 'la disminución';
                    $errors["items.{$item->id}"] = "Justifique {$kind} de «{$item->name}».";
                }

                $item->update(['executed_quantity' => $executed, 'note' => $note]);
            }

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $report->update([
                'status' => ProjectClosureReport::STATUS_SENT,
                'contractor_notes' => $data['notes'] ?? null,
                'submitted_at' => now(),
                'rejection_reason' => null,
                'rejected_by_role' => null,
                'rejection_target' => null,
            ]);
            $this->advance($project, $report->refresh());
        });

        AuditLog::record($project, 'PROVEEDOR', 'Envio de informe de cierre del contratista', "Informe revisión {$report->revision} enviado por {$report->contractor_code}.");

        return $report->refresh();
    }

    /** Informe propio del residente: su medición por partida + fotos + notas, sin depender del contratista. */
    public function approveByResident(Project $project, User $user, ?string $notes, array $items): ProjectClosureReport
    {
        $this->assertResidentMayReport($project, $user);

        $report = $project->closureReport;
        abort_if($this->modifications->hasPending($project), 422, 'Hay modificaciones de obra pendientes de aprobación; deben resolverse antes de enviar el informe.');
        abort_unless($report->photos()->where('uploaded_by_type', 'RESIDENTE')->exists(), 422, 'Adjunte al menos una foto de verificación en obra antes de dar el visto bueno.');

        DB::transaction(function () use ($project, $report, $user, $notes, $items) {
            $this->measurements->recordResident($report->load('items'), $items);
            $report->update([
                'resident_user_id' => $user->id,
                'resident_notes' => $notes,
                'resident_verified_at' => now(),
                'rejection_reason' => null,
                'rejected_by_role' => null,
                'rejection_target' => null,
            ]);
            $this->advance($project, $report->refresh());
        });

        AuditLog::record($project, $user->role, 'Informe de verificación del residente', "Informe enviado por {$user->name}.", $notes);

        return $report->refresh();
    }

    /** Auditoría solo aprueba: rige la medición del residente. */
    public function approveByAudit(Project $project, User $user, ?string $notes): ProjectClosureReport
    {
        ProjectStateMachine::assertStatus($project, self::S['VERIFICANDO_FINALIZACION'], 'Solo se puede verificar una obra con visto bueno del residente (VERIFICANDO_FINALIZACION).');

        $report = $project->closureReport->load('items');

        DB::transaction(function () use ($project, $report, $user, $notes, &$amount) {
            $amount = $this->computeFiniquitoAmount($project, $report->load('items'));
            $report->update([
                'status' => ProjectClosureReport::STATUS_AUDIT_APPROVED,
                'audit_user_id' => $user->id,
                'audit_notes' => $notes,
                'audit_verified_at' => now(),
                'finiquito_amount' => $amount,
            ]);
            $project->update([
                'status' => self::S['PENDIENTE_SOLICITUD_FINIQUITO'],
                'quality_verified' => true,
                'completion_verified_date' => now()->toDateString(),
            ]);
        });

        AuditLog::record($project, 'AUDITORIA', 'Verificacion de finalizacion por Auditoria', "Finiquito propuesto: {$amount} USD.", $notes);

        return $report->refresh();
    }

    /**
     * Auditoría devuelve el informe del CONTRATISTA o el del RESIDENTE (con motivo); el otro se conserva.
     * Desde VERIFICANDO_FINALIZACION, cuando ya llegaron ambos.
     */
    public function reject(Project $project, User $user, string $reason, ?string $target = null): ProjectClosureReport
    {
        ProjectStateMachine::assertStatus($project, self::S['VERIFICANDO_FINALIZACION'], 'El informe de cierre no está pendiente de revisión de Auditoría.');
        abort_unless(in_array($user->role, ['AUDITORIA', 'ADMIN', 'SUPERADMIN'], true), 403, 'Solo Auditoría puede rechazar en esta etapa.');
        abort_unless(in_array($target, [ProjectClosureReport::TARGET_CONTRACTOR, ProjectClosureReport::TARGET_RESIDENT], true), 422, 'Indique si el rechazo va al contratista o al residente.');
        $toResident = $target === ProjectClosureReport::TARGET_RESIDENT;

        $report = $project->closureReport;
        DB::transaction(function () use ($project, $report, $reason, $target, $toResident) {
            $report->update([
                'status' => $toResident ? ProjectClosureReport::STATUS_SENT : ProjectClosureReport::STATUS_REJECTED,
                'revision' => $report->revision + 1,
                'rejection_reason' => $reason,
                'rejected_by_role' => 'AUDITORIA',
                'rejection_target' => $target,
                'resident_verified_at' => $toResident ? null : $report->resident_verified_at,
            ]);
            if ($toResident) {
                $this->measurements->reset($report);
            }
            $project->update(['status' => self::S['INFORME_ENVIADO']]);
        });

        AuditLog::record(
            $project,
            'AUDITORIA',
            $toResident ? 'Devolucion de informe de cierre al residente' : 'Rechazo de informe de cierre por Auditoria',
            $toResident ? 'El residente debe repetir su informe y enviarlo de nuevo.' : 'El contratista debe corregir y reenviar el informe.',
            $reason
        );
        if (! $toResident) {
            $this->links->send($report, $project, $reason);
        }

        return $report->refresh();
    }

    /** Procura solicita el pago del finiquito → disponible para Finanzas. */
    public function requestFiniquito(Project $project, ?string $notes): Project
    {
        ProjectStateMachine::assertStatus($project, self::S['PENDIENTE_SOLICITUD_FINIQUITO'], 'Solo se puede solicitar el pago de una obra verificada por Auditoría (PENDIENTE_SOLICITUD_FINIQUITO).');

        $project->update(['status' => self::S['LISTO_PAGO_FINAL']]);
        AuditLog::record($project, 'PROCURA', 'Solicitud de pago de finiquito', "Monto propuesto: {$project->closureReport->finiquito_amount} USD.", $notes);

        return $project;
    }

    /** Procura devuelve a Auditoría cuando no está de acuerdo con lo verificado. */
    public function returnToAudit(Project $project, string $reason): Project
    {
        ProjectStateMachine::assertStatus($project, self::S['PENDIENTE_SOLICITUD_FINIQUITO'], 'Solo se puede devolver a Auditoría una obra pendiente de solicitud de finiquito.');

        $project->closureReport->update(['status' => ProjectClosureReport::STATUS_RESIDENT_APPROVED, 'audit_verified_at' => null]);
        $project->update(['status' => self::S['VERIFICANDO_FINALIZACION'], 'quality_verified' => false]);
        AuditLog::record($project, 'PROCURA', 'Devolucion de finiquito a Auditoria', 'Procura devolvió la verificación a Auditoría.', $reason);

        return $project;
    }

    /** El residente reporta mientras la obra está en ejecución o esperando el informe del contratista. */
    public function assertResidentMayReport(Project $project, User $user): void
    {
        ProjectStateMachine::assertStatusIn($project, [self::S['EN_EJECUCION'], self::S['INFORME_ENVIADO']], 'El informe del residente solo se carga mientras la obra está en ejecución.');
        abort_unless($project->closureReport, 404, 'La obra aún no tiene informe de cierre.');
        abort_if($project->closureReport->residentSubmitted(), 422, 'Su informe ya fue enviado y está en revisión.');
        $this->assertCanActAsResident($project, $user);
    }

    /** Con ambos informes recibidos la obra pasa a Auditoría; con uno solo queda esperando al otro. */
    private function advance(Project $project, ProjectClosureReport $report): void
    {
        $ready = $report->contractorSubmitted() && $report->residentSubmitted();
        if ($ready) {
            $report->update(['status' => ProjectClosureReport::STATUS_RESIDENT_APPROVED]);
        }
        $project->update(['status' => self::S[$ready ? 'VERIFICANDO_FINALIZACION' : 'INFORME_ENVIADO']]);

        if ($ready) {
            AuditLog::record($project, 'SISTEMA', 'Informes de cierre listos para Auditoria', 'Contratista y residente enviaron su informe; Auditoría puede compararlos.');
        }
    }

    /** Solo el residente efectivo de la obra (F2-R D11) o ADMIN/SUPERADMIN pueden actuar como residente. */
    public function assertCanActAsResident(Project $project, User $user): void
    {
        if (in_array($user->role, ['ADMIN', 'SUPERADMIN'], true)) {
            return;
        }

        abort_unless($user->role === 'RESIDENTE' && $project->effectiveResidentId() === $user->id, 403, 'Solo el ingeniero residente de la obra puede corroborar la ejecución.');
    }

    /** Contratado − anticipo − Σ(disminuciones × precio unitario), sobre la cantidad final, nunca negativo. */
    private function computeFiniquitoAmount(Project $project, ProjectClosureReport $report): float
    {
        $proposal = $project->proposals()->whereKey($project->selected_proposal_id)->first();
        $contracted = (float) ($proposal?->total_cost ?? 0);
        $advance = (float) ProjectPayment::where('project_id', $project->id)->where('payment_type', 'ADVANCE')->value('amount');
        $reductions = $report->items->sum(fn ($i) => ($i->contracted_quantity - $i->final_quantity) * $i->unit_price_usd);

        return round(max(0, $contracted - $advance - $reductions), 2);
    }
}
