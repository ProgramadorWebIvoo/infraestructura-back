<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Genera un dataset de prueba tipo PRODUCCIÓN en la BD 'gestion-infraestructura-sample'.
 *
 * Inserta 200+ proyectos distribuidos en los 9 estados del flujo, con sus
 * relaciones coherentes (materiales, propuestas, pagos, documentos, auditoría)
 * y antigüedad realista (updated_at) para que el dashboard de Presidencia
 * muestre cuellos de botella y métricas con datos reales.
 *
 * Idempotente: borra y regenera las tablas de dominio (no toca users).
 */
class SampleProductionSeeder extends Seeder
{
    private const STATUSES = [
        'CREADO'                => 12,
        'REVISADO_CIERRE'       => 30,
        'CONFIRMADO_PROCURA'    => 30,
        'COMPARATIVA_ENVIADA'   => 25,
        'CONTRATADO'            => 20,
        'EN_EJECUCION'          => 30,
        'VERIFICANDO_FINALIZACION' => 15,
        'LISTO_PAGO_FINAL'      => 10,
        'COMPLETADO_PAGADO'     => 28,
    ];

    private const LOCATIONS = [
        'Buenos Aires', 'Córdoba', 'Rosario', 'Mendoza', 'La Plata',
        'Mar del Plata', 'Salta', 'Tucumán', 'Neuquén', 'Bariloche',
        'San Miguel de Tucumán', 'Posadas', 'Corrientes', 'Bahía Blanca',
        'San Juan', 'Paraná', 'Santiago del Estero', 'Resistencia',
    ];

    private const MATERIALS = [
        ['Cemento Portland', 'bolsa', 8500],
        ['Arena gruesa', 'm3', 42000],
        ['Piedra partida', 'm3', 38000],
        ['Hierro 12mm', 'kg', 2100],
        ['Hierro 8mm', 'kg', 2050],
        ['Ladrillo común', 'unidad', 320],
        ['Bloque de hormigón', 'unidad', 1450],
        ['Cable 4mm', 'm', 1850],
        ['Caño PVC 110mm', 'm', 3200],
        ['Caño PVC 63mm', 'm', 2100],
        ['Pintura látex', 'l', 6800],
        ['Pintura esmalte', 'l', 9200],
        ['Cerámica 60x60', 'm2', 18500],
        ['Porcelanato', 'm2', 24500],
        ['Yeso', 'kg', 1200],
        ['Masilla', 'kg', 3400],
        ['Tornillos', 'kg', 2800],
        ['Perfil de aluminio', 'm', 5200],
        ['Vidrio templado', 'm2', 18500],
        ['Pintura asfáltica', 'l', 7800],
    ];

    private const CONTRACTOR_NAMES = [
        'Constructora Andina', 'Ingeniería del Sur', 'Obras y Vías', 'Construcciones Roca',
        'Edificar S.A.', 'Hormigón Norte', 'Vialidad Moderna', 'Constructora Patagonia',
        'Obra Fina', 'Ingeniería Civil del Plata', 'Construcciones Litoral', 'Viviendas del Sol',
        'Estructuras Metálicas Sur', 'Construcciones Cuyo', 'Obra Limpia', 'Ingeniería Vial',
    ];

    private const SPECIALTIES = [
        'Construcción Civil', 'Electricidad', 'Plomería', 'Estructuras Metálicas', 'Pintura',
        'Vialidad', 'Hormigón Armado', 'Instalaciones', 'Carpintería', 'Techos',
    ];

    private const ROLES = ['PRESIDENCIA', 'INFRAESTRUCTURA', 'CIERRE_DE_OBRA', 'PROCURA', 'ANALISTA', 'FINANZAS', 'SISTEMA'];

    private const ACTIONS = [
        'Proyecto creado', 'Revisión técnica completada', 'Inversión aprobada',
        'Propuesta registrada', 'Cuadro comparativo enviado', 'Contrato adjudicado',
        'Anticipo liberado', 'Certificación de calidad aprobada', 'Finiquito pagado',
        'Proyecto completado', 'Documento adjuntado', 'Retorno a revisión',
    ];

    public function run(): void
    {
        // ── Limpieza idempotente (no toca users) ──
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'supplier_material_proposals', 'supplier_invitations', 'audit_logs',
            'project_documents', 'project_payments', 'project_proposals',
            'project_materials', 'projects', 'material_catalog', 'contractors',
        ] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->seedContractors();
        $this->seedMaterialCatalog();
        $this->seedProjects();
    }

    private function seedContractors(): void
    {
        $now = now();
        $rows = [];
        foreach (self::CONTRACTOR_NAMES as $i => $name) {
            $rows[] = [
                'code' => 'CON-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'specialty' => self::SPECIALTIES[$i % count(self::SPECIALTIES)],
                'rating' => round(3.0 + (($i * 7) % 20) / 10, 1),
                'email' => 'contacto' . ($i + 1) . '@' . Str::slug($name) . '.com',
                'phone' => null,
                'registration_source' => 'SEED',
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('contractors')->insert($rows);
    }

    private function seedMaterialCatalog(): void
    {
        $now = now();
        $rows = [];
        foreach (self::MATERIALS as $i => [$name, $unit, $price]) {
            $rows[] = [
                'name' => $name,
                'unit' => $unit,
                'estimated_unit_price' => $price,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('material_catalog')->insert($rows);
    }

    private function seedProjects(): void
    {
        $contractors = DB::table('contractors')->get();
        $catalog = DB::table('material_catalog')->get();
        $now = now();

        $projectSeq = 0;
        $proposalSeq = 0;
        $logSeq = 0;

        foreach (self::STATUSES as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $projectSeq++;
                $projectId = 'PRJ-' . str_pad((string) $projectSeq, 3, '0', STR_PAD_LEFT);
                $type = $projectSeq % 3 === 0 ? 'MANTENIMIENTO' : 'INFRAESTRUCTURA';
                $location = self::LOCATIONS[$projectSeq % count(self::LOCATIONS)];
                $createdDate = now()->subDays(mt_rand(20, 400))->format('Y-m-d');

                // Antigüedad realista: estados intermedios con updated_at viejo
                // para que el dashboard detecte cuellos de botella.
                $staleDays = match ($status) {
                    'REVISADO_CIERRE', 'CONFIRMADO_PROCURA' => mt_rand(15, 60),
                    'COMPARATIVA_ENVIADA' => mt_rand(10, 45),
                    default => mt_rand(0, 8),
                };
                $updatedAt = now()->subDays($staleDays);

                $estimatedTotal = mt_rand(800, 9000) * 1000;
                $approved = $estimatedTotal + mt_rand(-5, 15) * 1000;

                $project = [
                    'id' => $projectId,
                    'title' => $this->projectTitle($projectSeq, $type),
                    'type' => $type,
                    'description' => 'Obra de ' . ($type === 'INFRAESTRUCTURA' ? 'infraestructura' : 'mantenimiento') . ' en ' . $location . '. ' . Str::ucfirst(Str::random(20)),
                    'location' => $location,
                    'created_date' => $createdDate,
                    'status' => $status,
                    'estimated_total' => $estimatedTotal,
                    'cierre_obra_notes' => in_array($status, ['REVISADO_CIERRE', 'CONFIRMADO_PROCURA', 'COMPARATIVA_ENVIADA', 'CONTRATADO', 'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO']) ? 'Revisión técnica aprobada por Cierre de Obra.' : null,
                    'calculations_added' => in_array($status, ['REVISADO_CIERRE', 'CONFIRMADO_PROCURA', 'COMPARATIVA_ENVIADA', 'CONTRATADO', 'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO']),
                    'blueprints_count' => in_array($status, ['REVISADO_CIERRE', 'CONFIRMADO_PROCURA', 'COMPARATIVA_ENVIADA', 'CONTRATADO', 'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO']) ? mt_rand(1, 8) : 0,
                    'procura_review_notes' => in_array($status, ['CONFIRMADO_PROCURA', 'COMPARATIVA_ENVIADA', 'CONTRATADO', 'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO']) ? 'Inversión aprobada por Procura.' : null,
                    'approved_investment_amount' => in_array($status, ['CONFIRMADO_PROCURA', 'COMPARATIVA_ENVIADA', 'CONTRATADO', 'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO']) ? $approved : null,
                    'selected_contractor_code' => null,
                    'selected_proposal_id' => null,
                    'quality_verified' => in_array($status, ['VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO']),
                    'completion_verified_date' => in_array($status, ['VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO']) ? $updatedAt->format('Y-m-d') : null,
                    'created_at' => $createdDate . ' 09:00:00',
                    'updated_at' => $updatedAt,
                ];

                DB::table('projects')->insert($project);

                // Materiales (todos los proyectos tienen al menos 2)
                $this->seedMaterials($projectId, $catalog, $createdDate);

                // Propuestas según etapa
                $proposals = [];
                if (in_array($status, ['COMPARATIVA_ENVIADA', 'CONTRATADO', 'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO'])) {
                    $nProposals = mt_rand(2, 4);
                    $contractorPool = $contractors->shuffle()->take($nProposals);
                    foreach ($contractorPool as $c) {
                        $proposalSeq++;
                        $proposalId = 'PROP-' . str_pad((string) $proposalSeq, 4, '0', STR_PAD_LEFT);
                        $materialCost = mt_rand(500, 7000) * 1000;
                        $laborCost = mt_rand(200, 3000) * 1000;
                        $totalCost = $materialCost + $laborCost;
                        $proposals[] = [
                            'id' => $proposalId,
                            'project_id' => $projectId,
                            'contractor_code' => $c->code,
                            'contractor_name_snapshot' => $c->name,
                            'material_cost' => $materialCost,
                            'labor_cost' => $laborCost,
                            'total_cost' => $totalCost,
                            'delivery_weeks' => mt_rand(4, 24),
                            'negotiated_advance_percent' => mt_rand(10, 40),
                            'description' => 'Propuesta de ' . $c->name . ' para ' . $projectId,
                            'created_at' => $updatedAt->subDays(mt_rand(1, 10)),
                        ];
                    }
                    DB::table('project_proposals')->insert($proposals);
                    $proposals = [];
                }

                // Adjudicación (desde CONTRATADO)
                if (in_array($status, ['CONTRATADO', 'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO'])) {
                    $winner = DB::table('project_proposals')->where('project_id', $projectId)->first();
                    if ($winner) {
                        DB::table('projects')->where('id', $projectId)->update([
                            'selected_contractor_code' => $winner->contractor_code,
                            'selected_proposal_id' => $winner->id,
                        ]);
                    }
                }

                // Pagos (anticipo desde EN_EJECUCION, finiquito desde COMPLETADO_PAGADO)
                $this->seedPayments($projectId, $status, $updatedAt);

                // Documentos (desde REVISADO_CIERRE)
                if (in_array($status, ['REVISADO_CIERRE', 'CONFIRMADO_PROCURA', 'COMPARATIVA_ENVIADA', 'CONTRATADO', 'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO'])) {
                    $this->seedDocuments($projectId, $status, $updatedAt);
                }

                // Auditoría (1-3 logs por proyecto)
                $this->seedAuditLogs($projectId, $project['title'], $status, $updatedAt, $logSeq);
            }
        }

        $this->command->info("Seeded {$projectSeq} projects.");
    }

    private function seedMaterials(string $projectId, $catalog, string $createdDate): void
    {
        $n = mt_rand(2, 5);
        $rows = [];
        $pool = $catalog->shuffle()->take($n);
        foreach ($pool as $m) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'project_id' => $projectId,
                'material_catalog_id' => $m->id,
                'name' => $m->name,
                'quantity' => mt_rand(1, 200),
                'unit' => $m->unit,
                'estimated_unit_price' => $m->estimated_unit_price,
                'created_at' => $createdDate . ' 09:00:00',
            ];
        }
        DB::table('project_materials')->insert($rows);
    }

    private function seedPayments(string $projectId, string $status, $updatedAt): void
    {
        $winner = DB::table('project_proposals')->where('project_id', $projectId)->first();
        if (!$winner) return;

        $advanceAmount = round($winner->total_cost * ($winner->negotiated_advance_percent / 100), 2);

        if (in_array($status, ['EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO'])) {
            DB::table('project_payments')->insert([
                'project_id' => $projectId,
                'proposal_id' => $winner->id,
                'payment_type' => 'ADVANCE',
                'amount' => $advanceAmount,
                'paid_date' => $updatedAt->subDays(mt_rand(5, 30))->format('Y-m-d'),
                'notes' => 'Anticipo de inicio de obra',
                'created_at' => $updatedAt,
            ]);
        }

        if ($status === 'COMPLETADO_PAGADO') {
            DB::table('project_payments')->insert([
                'project_id' => $projectId,
                'proposal_id' => $winner->id,
                'payment_type' => 'FINAL',
                'amount' => round($winner->total_cost - $advanceAmount, 2),
                'paid_date' => $updatedAt->format('Y-m-d'),
                'notes' => 'Finiquito de cierre de obra',
                'created_at' => $updatedAt,
            ]);
        }
    }

    private function seedDocuments(string $projectId, string $status, $updatedAt): void
    {
        $rows = [];
        $n = mt_rand(1, 3);
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'project_id' => $projectId,
                'document_type' => $i % 2 === 0 ? 'CALC' : 'PLANO',
                'original_name' => ($i % 2 === 0 ? 'calculo' : 'plano') . '-' . $projectId . '-' . ($i + 1) . '.pdf',
                'stored_path' => 'documents/' . $projectId . '/' . ($i % 2 === 0 ? 'calculo' : 'plano') . '-' . ($i + 1) . '.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => mt_rand(100000, 5000000),
                'uploaded_by' => 1,
                'created_at' => $updatedAt,
                'updated_at' => $updatedAt,
            ];
        }
        DB::table('project_documents')->insert($rows);
    }

    private function seedAuditLogs(string $projectId, string $title, string $status, $updatedAt, int &$seq): void
    {
        $n = mt_rand(1, 3);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $seq++;
            $rows[] = [
                'id' => 'LOG-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'project_id' => $projectId,
                'project_title_snapshot' => $title,
                'role' => self::ROLES[$seq % count(self::ROLES)],
                'user_id' => 1,
                'user_name_snapshot' => 'Administrador IVOO',
                'action' => self::ACTIONS[$seq % count(self::ACTIONS)],
                'logged_at' => $updatedAt->subHours($i),
                'details' => 'Acción registrada para ' . $projectId,
                'created_at' => $updatedAt,
            ];
        }
        DB::table('audit_logs')->insert($rows);
    }

    private function projectTitle(int $seq, string $type): string
    {
        $prefix = $type === 'INFRAESTRUCTURA' ? 'Infraestructura' : 'Mantenimiento';
        $suffix = ['Vial', 'Hidráulica', 'Eléctrica', 'Sanitaria', 'Edilicia', 'Deportiva', 'Escolar', 'Hospitalaria'][$seq % 8];
        return $prefix . ' ' . $suffix . ' — Obra ' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}