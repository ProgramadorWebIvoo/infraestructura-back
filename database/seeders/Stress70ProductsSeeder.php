<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Obras de prueba con 70 productos para auditar desborde/UX en todas las vistas.
 *
 * Solo para QA/local. Idempotente: borra y recrea únicamente lo que lleva el
 * prefijo S70 (obras PRJ-S70-*, contratistas CON-S70-*), no toca el resto.
 *
 *   php artisan db:seed --class=Stress70ProductsSeeder
 *
 * Obras generadas (una por etapa del flujo, todas con 70 materiales):
 *   PRJ-S70-1 CREADO                  → Mis obras / Infraestructura / Auditoría
 *   PRJ-S70-2 CONFIRMADO_PROCURA      → Analistas (registrar propuesta) + enlace público de proveedor
 *   PRJ-S70-3 COMPARATIVA_ENVIADA     → Cuadro comparativo / Procura / Renegociación (enlace público)
 *   PRJ-S70-4 EN_EJECUCION            → Finanzas / Residente
 *   PRJ-S70-5 INFORME_ENVIADO         → Cierre de obra (informe con 70 ítems)
 */
class Stress70ProductsSeeder extends Seeder
{
    private const COUNT = 70;

    private const BASE = [
        ['Cemento Portland tipo I (saco 42.5 kg)', 'Saco', 12.5],
        ['Cabilla de acero 1/2 pulgada', 'Barra', 18],
        ['Cabilla de acero 3/8 pulgada', 'Barra', 11],
        ['Bloque de arcilla 15x20x40', 'Unidad', 0.9],
        ['Arena lavada', 'm3', 22],
        ['Piedra picada 3/4', 'm3', 28],
        ['Tubería PVC 4 pulgadas presión', 'Tubo', 14],
        ['Cable THW 12 AWG', 'Rollo', 65],
        ['Pintura caucho clase A (cuñete)', 'Cuñete', 48],
        ['Porcelanato 60x60 antideslizante', 'm2', 21],
        ['Lámina de zinc acanalada calibre 26', 'Lámina', 16],
        ['Interruptor termomagnético 2x30A', 'Unidad', 9],
    ];

    private const BRANDS = ['Venezolana de Cementos', 'Sidor', 'Pavco', 'Cadafe Pro', 'Montana', 'Alfa'];

    public function run(): void
    {
        $this->cleanup();

        $now = now();
        $userId = DB::table('users')->where('role', 'SUPERADMIN')->value('id') ?? 1;
        $residentId = DB::table('users')->where('role', 'RESIDENTE')->value('id');
        $codes = $this->seedContractors($now);

        $stages = [
            1 => 'CREADO',
            2 => 'CONFIRMADO_PROCURA',
            3 => 'COMPARATIVA_ENVIADA',
            4 => 'EN_EJECUCION',
            5 => 'INFORME_ENVIADO',
        ];

        foreach ($stages as $n => $status) {
            $projectId = "PRJ-S70-{$n}";
            $materials = $this->materials($projectId, $now);
            $estimated = round(collect($materials)->sum(fn($m) => $m['quantity'] * $m['estimated_unit_price']), 2);
            $past = $n >= 2;

            DB::table('projects')->insert([
                'id' => $projectId,
                'title' => "Obra de estrés " . self::COUNT . " productos — etapa {$status}",
                'type' => $n % 2 ? 'INFRAESTRUCTURA' : 'MANTENIMIENTO',
                'description' => 'Obra generada por Stress70ProductsSeeder para auditar la UX con ' . self::COUNT . ' productos.',
                'location' => 'Caracas, Distrito Capital',
                'created_date' => $now->toDateString(),
                'status' => $status,
                'estimated_total' => $estimated,
                'audit_notes' => $past ? 'Revisión técnica aprobada (seed).' : null,
                'calculations_added' => $past,
                'blueprints_count' => $past ? 3 : 0,
                'procura_review_notes' => $past ? 'Inversión aprobada (seed).' : null,
                'approved_investment_amount' => $past ? $estimated : null,
                'resident_user_id' => $n >= 4 ? $residentId : null,
                'requested_by_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('project_materials')->insert($materials);

            if ($n === 2) {
                $this->seedInvitation($projectId, $codes[0], $now);
            }
            if ($n >= 3) {
                $proposalIds = $this->seedProposals($projectId, $materials, $codes, $now, $userId);
                if ($n === 3) {
                    $this->seedSupplierProposal($projectId, $materials, $now);
                    $this->seedRenegotiation($projectId, $proposalIds[0], $codes[0], $now);
                } else {
                    DB::table('projects')->where('id', $projectId)->update([
                        'selected_contractor_code' => $codes[0],
                        'selected_proposal_id' => $proposalIds[0],
                    ]);
                }
            }
            if ($n === 5) {
                $this->seedClosure($projectId, $materials, $codes[0], $now);
            }
        }

        $this->command?->info('Seeded 5 obras PRJ-S70-* con ' . self::COUNT . ' productos cada una.');
    }

    private function cleanup(): void
    {
        $ids = DB::table('projects')->where('id', 'like', 'PRJ-S70-%')->pluck('id');
        DB::table('supplier_material_proposals')->whereIn('project_id', $ids)->delete();
        DB::table('renegotiation_invitations')->whereIn('project_id', $ids)->delete();
        DB::table('supplier_invitations')->whereIn('project_id', $ids)->delete();
        // project_proposals.replaced_by_id / projects.selected_proposal_id son FK: se liberan primero.
        DB::table('projects')->whereIn('id', $ids)->update(['selected_proposal_id' => null]);
        DB::table('project_proposals')->whereIn('project_id', $ids)->delete();
        // Cascada: materiales, informes de cierre (ítems/fotos).
        DB::table('project_closure_reports')->whereIn('project_id', $ids)->delete();
        DB::table('projects')->whereIn('id', $ids)->delete();
        DB::table('contractors')->where('code', 'like', 'CON-S70-%')->delete();
    }

    /** @return string[] códigos de contratista */
    private function seedContractors($now): array
    {
        $rows = [];
        foreach ([1 => 'Constructora Estrés Uno C.A.', 2 => 'Suministros Estrés Dos C.A.', 3 => 'Ingeniería Estrés Tres C.A.'] as $i => $name) {
            $rows[] = [
                'code' => "CON-S70-{$i}",
                'name' => $name,
                'rif' => "J-9000000{$i}-0",
                'specialty' => 'Pruebas de carga',
                'rating' => 3.5 + $i / 2,
                'email' => "estres{$i}@example.com",
                'phone' => "0412000000{$i}",
                'registration_source' => 'INTERNAL',
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('contractors')->insert($rows);

        return array_column($rows, 'code');
    }

    private function materials(string $projectId, $now): array
    {
        $rows = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            [$name, $unit, $price] = self::BASE[$i % count(self::BASE)];
            $rows[] = [
                'id' => (string) Str::uuid(),
                'project_id' => $projectId,
                'material_catalog_id' => null,
                // Sufijo para que los 70 nombres sean distintos y algunos largos (prueba de truncado).
                'name' => sprintf('%s — lote %02d%s', $name, $i + 1, $i % 9 === 0 ? ' con especificación extendida para pruebas de desborde' : ''),
                'quantity' => 5 + ($i * 7) % 180,
                'unit' => $unit,
                'estimated_unit_price' => round($price * (1 + ($i % 5) / 10), 2),
                'condition' => ['NUEVO', 'USADO', 'AMBAS'][$i % 3],
                'warranty_value' => $i % 4 === 0 ? null : 6 + $i % 18,
                'warranty_unit' => $i % 4 === 0 ? null : 'MESES',
                'brand' => self::BRANDS[$i % count(self::BRANDS)],
                'model' => 'M-' . (100 + $i),
                'specifications' => 'Especificación técnica del producto ' . ($i + 1) . ': cumple norma COVENIN, uso estructural y acabado estándar.',
                'observations' => $i % 6 === 0 ? 'Observación larga de ejemplo: entregar en sitio con descarga asistida y verificación por el residente de obra.' : null,
                'created_at' => $now,
            ];
        }

        return $rows;
    }

    private function items(array $materials, float $factor): array
    {
        return array_map(function ($m) use ($factor) {
            $unit = round($m['estimated_unit_price'] * $factor, 2);

            return [
                'materialName' => $m['name'],
                'quantity' => (float) $m['quantity'],
                'unit' => $m['unit'],
                'unitPrice' => $unit,
                'totalPrice' => round($unit * $m['quantity'], 2),
                'notes' => null,
                'conditionStatus' => 'new',
                'warrantyDescription' => '6 meses por defectos de fábrica',
                'warrantyValue' => 6,
                'warrantyUnit' => 'meses',
            ];
        }, $materials);
    }

    private function seedInvitation(string $projectId, string $contractorCode, $now): void
    {
        DB::table('supplier_invitations')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $projectId,
            'supplier_name' => 'Proveedor Estrés',
            'supplier_company' => 'Proveedor Estrés C.A.',
            'supplier_contact' => 'estres1@example.com',
            'created_at' => $now,
            'expires_at' => $now->copy()->addDays(30),
        ]);
    }

    /** @return string[] ids de propuestas (la primera es la ganadora) */
    private function seedProposals(string $projectId, array $materials, array $codes, $now, int $userId): array
    {
        $ids = [];
        foreach ($codes as $i => $code) {
            $items = $this->items($materials, 0.95 + $i * 0.04);
            $materialCost = round(array_sum(array_column($items, 'totalPrice')), 2);
            $labor = 1500 + $i * 250;
            $id = "PROP-{$projectId}-" . ($i + 1);
            DB::table('project_proposals')->insert([
                'id' => $id,
                'project_id' => $projectId,
                'contractor_code' => $code,
                'contractor_name_snapshot' => DB::table('contractors')->where('code', $code)->value('name'),
                'material_cost' => $materialCost,
                'material_items' => json_encode($items),
                'quote_currency' => 'USD',
                'labor_cost' => $labor,
                'total_cost' => $materialCost + $labor,
                'delivery_weeks' => 8 + $i * 2,
                'duration_value' => 8 + $i * 2,
                'duration_unit' => 'semanas',
                'negotiated_advance_percent' => 30,
                'description' => 'Propuesta de estrés ' . ($i + 1),
                'origen' => 'MANUAL',
                'fecha_oferta' => $now->toDateString(),
                'created_by' => $userId,
                'created_at' => $now,
            ]);
            $ids[] = $id;
        }

        return $ids;
    }

    private function seedSupplierProposal(string $projectId, array $materials, $now): void
    {
        DB::table('supplier_material_proposals')->insert([
            'id' => 'SMP-S70-1',
            'project_id' => $projectId,
            'project_title_snapshot' => DB::table('projects')->where('id', $projectId)->value('title'),
            'supplier_name' => 'Proveedor Estrés',
            'supplier_company' => 'Proveedor Estrés C.A.',
            'supplier_contact' => 'estres1@example.com',
            'quote_currency' => 'USD',
            'items' => json_encode($this->items($materials, 0.97)),
            'estimated_days' => 45,
            'duration_unit' => 'dias',
            'advance_percent' => 30,
            'labor_cost' => 1200,
            'submitted_at' => $now,
        ]);
    }

    private function seedRenegotiation(string $projectId, string $proposalId, string $code, $now): void
    {
        DB::table('renegotiation_invitations')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $projectId,
            'proposal_id' => $proposalId,
            'contractor_code' => $code,
            'contractor_email' => 'estres1@example.com',
            'expires_at' => $now->copy()->addDays(30),
        ]);
    }

    private function seedClosure(string $projectId, array $materials, string $code, $now): void
    {
        $reportId = (string) Str::uuid();
        DB::table('project_closure_reports')->insert([
            'id' => $reportId,
            'project_id' => $projectId,
            'contractor_code' => $code,
            'contractor_email' => 'estres1@example.com',
            'status' => 'ENVIADO',
            'revision' => 1,
            'contractor_notes' => 'Informe de cierre de estrés con ' . self::COUNT . ' ítems.',
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('project_closure_report_items')->insert(array_map(fn($m) => [
            'report_id' => $reportId,
            'project_material_id' => $m['id'],
            'name' => $m['name'],
            'unit' => $m['unit'],
            'contracted_quantity' => $m['quantity'],
            'executed_quantity' => $m['quantity'],
            'unit_price_usd' => $m['estimated_unit_price'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $materials));
    }
}
