<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectClosureReport;
use App\Notifications\SupplierClosureReportLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Apertura del informe de cierre y envío/reenvío de su enlace público al contratista. */
class ClosureReportLinkService
{
    /** Crea el informe abierto (partidas contratadas) al liberar el anticipo y envía el enlace. */
    public function open(Project $project): ProjectClosureReport
    {
        $contractor = Contractor::where('code', $project->selected_contractor_code)->first();
        $proposal = $project->proposals()->whereKey($project->selected_proposal_id)->first();
        $unitPrices = collect($proposal?->material_items ?? [])
            ->mapWithKeys(fn ($item) => [($item['materialName'] ?? '') => (float) ($item['unit_price_usd'] ?? $item['unitPrice'] ?? 0)]);

        $report = DB::transaction(function () use ($project, $contractor, $unitPrices) {
            $report = ProjectClosureReport::firstOrCreate(
                ['project_id' => $project->id],
                [
                    'id' => Str::uuid()->toString(),
                    'contractor_code' => $project->selected_contractor_code,
                    'contractor_email' => $contractor?->email,
                    'status' => ProjectClosureReport::STATUS_OPEN,
                ]
            );

            if ($report->items()->doesntExist()) {
                foreach ($project->materials as $material) {
                    $report->items()->create([
                        'project_material_id' => $material->id,
                        'name' => $material->name,
                        'unit' => $material->unit,
                        'contracted_quantity' => $material->quantity,
                        'executed_quantity' => $material->quantity,
                        'unit_price_usd' => $unitPrices->get($material->name, 0),
                    ]);
                }
            }

            return $report;
        });

        $this->send($report, $project);

        return $report;
    }

    /** Reenvía el enlace público sin cambiar de estado. */
    public function resend(Project $project): bool
    {
        abort_unless($project->closureReport, 404, 'La obra no tiene informe de cierre.');

        return $this->send($project->closureReport, $project);
    }

    public function send(ProjectClosureReport $report, Project $project, ?string $rejectionReason = null): bool
    {
        if (!$report->contractor_email) {
            return false;
        }

        // Un SMTP caído no debe bloquear el flujo (mismo criterio que los demás enlaces públicos).
        try {
            Notification::route('mail', $report->contractor_email)
                ->notify(new SupplierClosureReportLink($project->title, $report->id, $rejectionReason));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
