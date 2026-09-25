<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectClosurePhoto;
use App\Models\ProjectClosureReport;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Herramienta de DEBUG-MODE (solo con APP_DEBUG, ADMIN/SUPERADMIN): crea una
 * obra de prueba ya en ejecución con su informe de cierre abierto y la avanza
 * paso a paso usando los mismos servicios reales del flujo, para poder probar
 * cada pantalla (contratista, residente, Auditoría, Procura, Finanzas) sin
 * recorrer todo el ciclo de adjudicación. El contratista de prueba no tiene
 * correo, así que no se envía ningún mail externo.
 */
class ClosureDebugFixtureService
{
    public const STEPS = ['EN_EJECUCION', 'INFORME_ENVIADO', 'VERIFICANDO_FINALIZACION', 'PENDIENTE_SOLICITUD_FINIQUITO', 'LISTO_PAGO_FINAL'];

    private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function __construct(
        private ClosureReportLinkService $links,
        private ProjectClosureService $closure,
    ) {
    }

    public function create(User $actor, string $target): Project
    {
        $project = DB::transaction(function () use ($actor) {
            $contractor = Contractor::firstOrCreate(
                ['code' => 'CON-DEBUG'],
                ['name' => 'Contratista de Prueba (debug)', 'rif' => 'J-00000000-0', 'specialty' => 'Pruebas', 'rating' => 4, 'email' => null, 'registration_source' => 'SEED', 'status' => 'ACTIVE']
            );

            $project = Project::create([
                'id' => Project::nextId(),
                'title' => '[DEBUG] Obra de prueba de cierre ' . now()->format('d/m H:i'),
                'type' => 'INFRAESTRUCTURA',
                'description' => 'Obra generada por el DEBUG-MODE para probar el flujo de cierre.',
                'location' => 'Sede de pruebas',
                'created_date' => now()->toDateString(),
                'status' => ProjectStateMachine::STATUSES['EN_EJECUCION'],
                'estimated_total' => 1000,
                'approved_investment_amount' => 1000,
                'selected_contractor_code' => $contractor->code,
                'requested_by_user_id' => $actor->id,
            ]);

            foreach ([['Tomacorriente', 12, 'und', 50], ['Cable', 100, 'm', 2]] as $i => [$name, $qty, $unit, $price]) {
                $project->materials()->create([
                    'id' => "{$project->id}-MAT-" . ($i + 1), 'name' => $name, 'quantity' => $qty, 'unit' => $unit,
                    'estimated_unit_price' => $price, 'condition' => 'NUEVO',
                ]);
            }

            $proposal = ProjectProposal::create([
                'id' => ProjectProposal::nextId(), 'project_id' => $project->id, 'contractor_code' => $contractor->code,
                'contractor_name_snapshot' => $contractor->name, 'material_cost' => 800, 'labor_cost' => 200, 'total_cost' => 1000,
                'delivery_weeks' => 2, 'negotiated_advance_percent' => 30, 'description' => 'Propuesta de prueba', 'origen' => 'MANUAL',
                'fecha_oferta' => now()->toDateString(),
                'material_items' => [['materialName' => 'Tomacorriente', 'unit_price_usd' => 50], ['materialName' => 'Cable', 'unit_price_usd' => 2]],
            ]);
            $project->update(['selected_proposal_id' => $proposal->id]);

            ProjectPayment::create(['project_id' => $project->id, 'proposal_id' => $proposal->id, 'payment_type' => 'ADVANCE', 'amount' => 300, 'paid_date' => now()->toDateString()]);

            return $project;
        });

        $this->links->open($project->refresh());

        return $this->advanceTo($project, $actor, $target);
    }

    /** Avanza la obra, un paso a la vez con los servicios reales, hasta el estado objetivo. */
    public function advanceTo(Project $project, User $actor, string $target): Project
    {
        abort_unless(in_array($target, self::STEPS, true), 422, 'Estado objetivo no válido.');
        $targetIndex = array_search($target, self::STEPS, true);

        while (true) {
            $project->refresh();
            $current = array_search($project->status, self::STEPS, true);
            abort_if($current === false, 422, "La obra está en {$project->status}, fuera del circuito de cierre de prueba.");
            if ($current >= $targetIndex) {
                return $project;
            }
            $this->step($project, $actor);
        }
    }

    private function step(Project $project, User $actor): void
    {
        $report = ProjectClosureReport::with('items')->where('project_id', $project->id)->firstOrFail();

        match ($project->status) {
            'EN_EJECUCION' => $this->closure->submit(
                tap($report, fn ($r) => $this->addPhoto($r, ProjectClosurePhoto::BY_CONTRACTOR, null)),
                ['notes' => 'Informe de prueba (debug)', 'items' => $report->items->map(fn ($i) => ['id' => $i->id, 'executedQuantity' => $i->contracted_quantity])->all()]
            ),
            'INFORME_ENVIADO' => $this->approveResident($project, $report, $actor),
            'VERIFICANDO_FINALIZACION' => $this->closure->approveByAudit($project, $actor, 'Verificado (debug)'),
            'PENDIENTE_SOLICITUD_FINIQUITO' => $this->closure->requestFiniquito($project, 'Solicitud de prueba (debug)'),
        };
    }

    private function approveResident(Project $project, ProjectClosureReport $report, User $actor): void
    {
        $this->addPhoto($report, ProjectClosurePhoto::BY_RESIDENT, $actor->id);
        $items = $report->items->map(fn ($i) => ['id' => $i->id, 'residentQuantity' => $i->executed_quantity])->all();
        $this->closure->approveByResident($project, $actor, 'Corroborado (debug)', $items);
    }

    private function addPhoto(ProjectClosureReport $report, string $byType, ?int $userId): void
    {
        $path = "closure-photos/{$report->id}/debug-" . uniqid() . '.png';
        Storage::disk('local')->put($path, base64_decode(self::PIXEL_PNG));

        $report->photos()->create([
            'uploaded_by_type' => $byType, 'uploaded_by_user_id' => $userId, 'original_name' => 'debug.png',
            'stored_path' => $path, 'mime_type' => 'image/png', 'size_bytes' => strlen(base64_decode(self::PIXEL_PNG)),
        ]);
    }
}
