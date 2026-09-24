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
 * EN_EJECUCION → contratista envía informe (INFORME_ENVIADO) → residente lo
 * corrobora (VERIFICANDO_FINALIZACION = pendiente de Auditoría) → Auditoría
 * verifica (PENDIENTE_SOLICITUD_FINIQUITO) → Procura solicita el pago
 * (LISTO_PAGO_FINAL) → Finanzas paga. Cualquier rechazo vuelve a
 * EN_EJECUCION con motivo; ningún paso se salta.
 */
class ProjectClosureService
{
    private const S = ProjectStateMachine::STATUSES;

    public function __construct(private ClosureReportLinkService $links)
    {
    }

    /** Envío del contratista por el enlace público: partidas ejecutadas + notas + ≥1 foto. */
    public function submit(ProjectClosureReport $report, array $data): ProjectClosureReport
    {
        $project = $report->project;
        ProjectStateMachine::assertStatus($project, self::S['EN_EJECUCION'], 'Solo se puede enviar el informe de una obra en ejecución.');
        abort_unless($report->isEditableByContractor(), 422, 'El informe ya fue enviado y está en revisión.');
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

                if ($executed > $item->contracted_quantity) {
                    $errors["items.{$item->id}"] = "La cantidad ejecutada de «{$item->name}» no puede superar lo contratado; las obras extras se gestionan como modificación de obra.";
                } elseif ($executed < $item->contracted_quantity && blank($note)) {
                    $errors["items.{$item->id}"] = "Justifique la disminución de «{$item->name}».";
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
            ]);
            $project->update(['status' => self::S['INFORME_ENVIADO']]);
        });

        AuditLog::record($project, 'PROVEEDOR', 'Envio de informe de cierre del contratista', "Informe revisión {$report->revision} enviado por {$report->contractor_code}.");

        return $report->refresh();
    }

    public function approveByResident(Project $project, User $user, ?string $notes): ProjectClosureReport
    {
        ProjectStateMachine::assertStatus($project, self::S['INFORME_ENVIADO'], 'Solo se puede corroborar un informe enviado por el contratista (INFORME_ENVIADO).');
        $this->assertCanActAsResident($project, $user);

        $report = $project->closureReport;
        abort_unless($report->photos()->where('uploaded_by_type', 'RESIDENTE')->exists(), 422, 'Adjunte al menos una foto de verificación en obra antes de dar el visto bueno.');

        DB::transaction(function () use ($project, $report, $user, $notes) {
            $report->update([
                'status' => ProjectClosureReport::STATUS_RESIDENT_APPROVED,
                'resident_user_id' => $user->id,
                'resident_notes' => $notes,
                'resident_verified_at' => now(),
            ]);
            $project->update(['status' => self::S['VERIFICANDO_FINALIZACION']]);
        });

        AuditLog::record($project, $user->role, 'Visto bueno de residente al informe de cierre', "Corroborado por {$user->name}.", $notes);

        return $report->refresh();
    }

    public function approveByAudit(Project $project, User $user, ?string $notes): ProjectClosureReport
    {
        ProjectStateMachine::assertStatus($project, self::S['VERIFICANDO_FINALIZACION'], 'Solo se puede verificar una obra con visto bueno del residente (VERIFICANDO_FINALIZACION).');

        $report = $project->closureReport->load('items');
        $amount = $this->computeFiniquitoAmount($project, $report);

        DB::transaction(function () use ($project, $report, $user, $notes, $amount) {
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

    /** Rechazo del residente (desde INFORME_ENVIADO) o de Auditoría (desde VERIFICANDO_FINALIZACION). */
    public function reject(Project $project, User $user, string $reason): ProjectClosureReport
    {
        $byResident = $project->status === self::S['INFORME_ENVIADO'];
        ProjectStateMachine::assertStatusIn($project, [self::S['INFORME_ENVIADO'], self::S['VERIFICANDO_FINALIZACION']], 'El informe de cierre no está pendiente de revisión.');
        if ($byResident) {
            $this->assertCanActAsResident($project, $user);
        } else {
            abort_unless(in_array($user->role, ['AUDITORIA', 'ADMIN', 'SUPERADMIN'], true), 403, 'Solo Auditoría puede rechazar en esta etapa.');
        }

        $report = $project->closureReport;
        DB::transaction(function () use ($project, $report, $reason, $byResident) {
            $report->update([
                'status' => ProjectClosureReport::STATUS_REJECTED,
                'revision' => $report->revision + 1,
                'rejection_reason' => $reason,
                'rejected_by_role' => $byResident ? 'INFRAESTRUCTURA' : 'AUDITORIA',
                'resident_verified_at' => null,
            ]);
            $project->update(['status' => self::S['EN_EJECUCION']]);
        });

        AuditLog::record(
            $project,
            $byResident ? 'INFRAESTRUCTURA' : 'AUDITORIA',
            $byResident ? 'Rechazo de informe de cierre por residente' : 'Rechazo de informe de cierre por Auditoria',
            'El contratista debe corregir y reenviar el informe.',
            $reason
        );
        $this->links->send($report, $project, $reason);

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

    /** Residente asignado; sin asignar, cualquier INFRAESTRUCTURA/ADMIN puede corroborar. */
    public function assertCanActAsResident(Project $project, User $user): void
    {
        if (in_array($user->role, ['ADMIN', 'SUPERADMIN'], true)) {
            return;
        }

        abort_unless($user->role === 'INFRAESTRUCTURA', 403, 'Solo Infraestructura puede corroborar la ejecución.');
        abort_if($project->resident_user_id !== null && $project->resident_user_id !== $user->id, 403, 'Esta obra tiene otro ingeniero residente asignado.');
    }

    /** Contratado − anticipo − Σ(disminuciones × precio unitario), nunca negativo. */
    private function computeFiniquitoAmount(Project $project, ProjectClosureReport $report): float
    {
        $proposal = $project->proposals()->whereKey($project->selected_proposal_id)->first();
        $contracted = (float) ($proposal?->total_cost ?? 0);
        $advance = (float) ProjectPayment::where('project_id', $project->id)->where('payment_type', 'ADVANCE')->value('amount');
        $reductions = $report->items->sum(fn ($i) => max(0, $i->contracted_quantity - $i->executed_quantity) * $i->unit_price_usd);

        return round(max(0, $contracted - $advance - $reductions), 2);
    }
}
