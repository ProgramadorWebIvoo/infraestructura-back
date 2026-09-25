<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectModificationItem;
use App\Models\ProjectModificationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F3 — Modificaciones de obra. Una solicitud agrupa líneas de AUMENTO o
 * DISMINUCION sobre partidas ya contratadas, al costo unitario aprobado; la
 * aprueba Auditoría (configurable) y no altera el estado de la obra.
 * Cantidad final por partida = contratado + Σ modificaciones aprobadas (≥ 0).
 */
class ProjectModificationService
{
    private const OPEN_STATUS = 'EN_EJECUCION';
    private const OVER_EXECUTION_ACTION = 'Sobre-ejecucion de presupuesto';

    public function __construct(private ModificationAccess $access)
    {
    }

    /** @param array<int, array{materialId: string, type: string, quantity: float|int|string, note?: ?string}> $items */
    public function create(Project $project, User $user, string $reason, array $items): ProjectModificationRequest
    {
        $this->assertProjectOpen($project);
        $this->assertCanRequest($project, $user);

        $request = DB::transaction(function () use ($project, $user, $reason, $items) {
            $request = $project->modificationRequests()->create([
                'requested_by_user_id' => $user->id,
                'status' => ProjectModificationRequest::STATUS_PENDING,
                'reason' => $reason,
            ]);
            $this->syncItems($request, $project, $items);

            return $request;
        });

        AuditLog::record($project, $user->role, 'Solicitud de modificacion de obra', $this->summary($request), $reason);

        return $request->load('items.material');
    }

    /** Edita una solicitud pendiente o rechazada; una rechazada vuelve a PENDIENTE (reenvío). */
    public function update(ProjectModificationRequest $request, User $user, string $reason, array $items): ProjectModificationRequest
    {
        $project = $request->project;
        $this->assertProjectOpen($project);
        $this->assertCanRequest($project, $user);
        abort_unless($request->isEditable(), 422, 'La solicitud ya fue aprobada y no se puede editar.');

        DB::transaction(function () use ($request, $project, $reason, $items) {
            $request->update([
                'status' => ProjectModificationRequest::STATUS_PENDING,
                'reason' => $reason,
                'rejection_reason' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
            ]);
            $request->items()->delete();
            $this->syncItems($request, $project, $items);
        });

        AuditLog::record($project, $user->role, 'Solicitud de modificacion de obra', 'Reenviada. ' . $this->summary($request->refresh()), $reason);

        return $request->load('items.material');
    }

    public function approve(ProjectModificationRequest $request, User $user, ?string $notes): ProjectModificationRequest
    {
        $project = $request->project;
        $this->assertProjectOpen($project);
        $this->assertCanReview($user);
        abort_unless($request->status === ProjectModificationRequest::STATUS_PENDING, 422, 'Solo se puede aprobar una solicitud pendiente.');

        DB::transaction(function () use ($request, $project, $user, $notes) {
            // Revalida contra lo aprobado hasta ahora: otra modificación pudo aprobarse entretanto.
            $this->assertQuantities($project, $request->items->map(fn ($i) => [
                'materialId' => $i->project_material_id, 'type' => $i->type, 'quantity' => $i->quantity,
            ])->all());

            $request->update([
                'status' => ProjectModificationRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'rejection_reason' => null,
            ]);
            $this->applyBudgetImpact($project, $request);
        });

        AuditLog::record($project, $user->role, 'Aprobacion de modificacion de obra', $this->summary($request), $notes);
        $this->alertIfOverBudget($project->refresh());

        return $request->load('items.material');
    }

    public function reject(ProjectModificationRequest $request, User $user, string $reason): ProjectModificationRequest
    {
        $project = $request->project;
        $this->assertProjectOpen($project);
        $this->assertCanReview($user);
        abort_unless($request->status === ProjectModificationRequest::STATUS_PENDING, 422, 'Solo se puede rechazar una solicitud pendiente.');

        $request->update([
            'status' => ProjectModificationRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        AuditLog::record($project, $user->role, 'Rechazo de modificacion de obra', 'Puede editarla y reenviarla.', $reason);

        return $request->load('items.material');
    }

    /**
     * Cantidad efectiva por partida: contratado + modificaciones aprobadas.
     *
     * @return array<string, array{contracted: float, modification: float, final: float}> indexado por project_material_id
     */
    public function effectiveQuantities(Project $project): array
    {
        $delta = ProjectModificationItem::query()
            ->whereHas('request', fn ($q) => $q->where('project_id', $project->id)->where('status', ProjectModificationRequest::STATUS_APPROVED))
            ->get()
            ->groupBy('project_material_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($i) => $i->signedQuantity()));

        return $project->materials->mapWithKeys(fn (ProjectMaterial $m) => [$m->id => [
            'contracted' => (float) $m->quantity,
            'modification' => $delta->get($m->id, 0.0),
            'final' => (float) $m->quantity + $delta->get($m->id, 0.0),
        ]])->all();
    }

    public function hasPending(Project $project): bool
    {
        return $project->modificationRequests()->where('status', ProjectModificationRequest::STATUS_PENDING)->exists();
    }

    /**
     * El monto adjudicado (total de la propuesta ganadora) sube con los aumentos y
     * baja con las disminuciones. El tope aprobado por Presidencia NO cambia: si
     * lo adjudicado lo supera, el semáforo de sobre-ejecución lo marca.
     */
    private function applyBudgetImpact(Project $project, ProjectModificationRequest $request): void
    {
        $proposal = $project->proposals()->whereKey($project->selected_proposal_id)->lockForUpdate()->first();
        if ($proposal === null) {
            return;
        }

        $proposal->update(['total_cost' => max(0, round((float) $proposal->total_cost + $request->netAmountUsd(), 2))]);
    }

    private function alertIfOverBudget(Project $project): void
    {
        $approved = $project->approved_investment_amount;
        $awarded = (float) $project->proposals()->whereKey($project->selected_proposal_id)->value('total_cost');
        if ($approved === null || $awarded <= (float) $approved) {
            return;
        }

        NotificationDispatcher::notify(
            $project,
            'SISTEMA',
            self::OVER_EXECUTION_ACTION,
            sprintf('Con las modificaciones aprobadas lo adjudicado ($%s) supera lo aprobado ($%s) por $%s.', number_format($awarded, 2), number_format((float) $approved, 2), number_format($awarded - (float) $approved, 2)),
        );
    }

    private function assertProjectOpen(Project $project): void
    {
        ProjectStateMachine::assertStatus($project, self::OPEN_STATUS, 'Las modificaciones solo se gestionan mientras la obra está en ejecución.');
    }

    /** Solicita un rol configurado y, salvo ADMIN/SUPERADMIN, solo el propietario de la obra. */
    private function assertCanRequest(Project $project, User $user): void
    {
        abort_unless($this->access->canRequest($user), 403, 'Su rol no puede solicitar modificaciones de obra.');
        if (! $this->access->isPrivileged($user)) {
            abort_unless($project->requested_by_user_id === $user->id, 403, 'Solo quien creó la solicitud de la obra puede modificarla.');
        }
    }

    private function assertCanReview(User $user): void
    {
        abort_unless($this->access->canReview($user), 403, 'Su rol no puede aprobar ni rechazar modificaciones de obra.');
    }

    /** Valida y guarda las líneas con el costo unitario aprobado de la propuesta ganadora. */
    private function syncItems(ProjectModificationRequest $request, Project $project, array $items): void
    {
        $this->assertQuantities($project, $items);

        $materials = $project->materials->keyBy('id');
        $prices = $this->unitPrices($project);
        $errors = [];
        $rows = [];

        foreach ($items as $index => $item) {
            $material = $materials->get($item['materialId']);
            $price = $prices->get($material->name, 0.0);
            if ($price <= 0) {
                $errors["items.{$index}.materialId"] = "«{$material->name}» no tiene costo unitario en la propuesta ganadora.";
                continue;
            }
            $rows[] = [
                'project_material_id' => $material->id,
                'type' => $item['type'],
                'quantity' => $item['quantity'],
                'unit_price_usd' => $price,
                'note' => $item['note'] ?? null,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $request->items()->createMany($rows);
    }

    /**
     * Cada partida debe existir en la obra, no repetirse en la solicitud y su
     * cantidad final (contratado + aprobadas + esta solicitud) no puede ser negativa.
     */
    private function assertQuantities(Project $project, array $items): void
    {
        $effective = $this->effectiveQuantities($project);
        $net = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $id = $item['materialId'];
            if (! isset($effective[$id])) {
                $errors["items.{$index}.materialId"] = 'La partida no pertenece a esta obra.';
                continue;
            }
            if (isset($net[$id])) {
                $errors["items.{$index}.materialId"] = 'La partida está repetida en la solicitud.';
                continue;
            }
            $net[$id] = $item['type'] === ProjectModificationItem::TYPE_DECREASE ? -(float) $item['quantity'] : (float) $item['quantity'];
        }

        foreach ($net as $id => $delta) {
            if ($effective[$id]['final'] + $delta < 0) {
                $name = $project->materials->firstWhere('id', $id)->name;
                $errors["items.{$id}"] = "La disminución de «{$name}» excede la cantidad vigente ({$effective[$id]['final']}).";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function unitPrices(Project $project)
    {
        $proposal = $project->proposals()->whereKey($project->selected_proposal_id)->first();

        return collect($proposal?->material_items ?? [])
            ->mapWithKeys(fn ($item) => [($item['materialName'] ?? '') => (float) ($item['unit_price_usd'] ?? $item['unitPrice'] ?? 0)]);
    }

    private function summary(ProjectModificationRequest $request): string
    {
        $request->loadMissing('items.material');
        $lines = $request->items->map(fn ($i) => ($i->type === ProjectModificationItem::TYPE_DECREASE ? '-' : '+') . $i->quantity . ' ' . $i->material?->name)->implode('; ');

        return "{$lines}. Impacto neto: {$request->netAmountUsd()} USD.";
    }
}
